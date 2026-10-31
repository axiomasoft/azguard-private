#!/usr/bin/env python3
"""Negative examples for the workflow policy."""

import importlib.util
import subprocess
import sys
import tempfile
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('workflow_pins', Path(__file__).with_name('check-workflow-pins.py'))
pins = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pins)

BASE = '''
on: pull_request
permissions: {contents: read}
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803
        with: {persist-credentials: false}
      - run: echo safe
'''


class PolicyTests(unittest.TestCase):
    def check_bad(self, text):
        self.assertTrue(pins.check_workflow(text, 'test.yml'))

    def test_valid(self):
        self.assertEqual([], pins.check_workflow(BASE, 'test.yml'))

    def test_bad_refs(self):
        for ref in ('v4', 'd23441a', '${{ inputs.action }}', './.github/workflows/unknown.yml', 'docker://alpine:latest'):
            with self.subTest(ref=ref):
                self.check_bad(BASE.replace('actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803', ref))

    def test_local_workflow_allowlist(self):
        self.assertEqual([], pins.check_workflow(BASE.replace('actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803', './.github/workflows/tests.yml'), 'test.yml'))

    def test_permissions(self):
        self.check_bad(BASE.replace('permissions: {contents: read}', 'permissions: write-all'))
        self.check_bad(BASE.replace('runs-on: ubuntu-latest', 'permissions: {contents: write}\n    runs-on: ubuntu-latest'))

    def test_checkout_credentials(self):
        self.check_bad(BASE.replace('persist-credentials: false', 'persist-credentials: true'))
        self.check_bad(BASE.replace('        with: {persist-credentials: false}\n', ''))

    def test_untrusted_inline_and_privileged_trigger(self):
        self.check_bad(BASE.replace('echo safe', 'echo ${{ github.event.pull_request.title }}'))
        self.check_bad(BASE.replace('echo safe', "echo ${{ github['event'].pull_request.title }}"))
        self.check_bad(BASE.replace('echo safe', "echo ${{ inputs['value'] }}"))
        self.check_bad(BASE.replace('pull_request', 'pull_request_target'))

    def test_untrusted_shell_and_action_script(self):
        expression = '${{ github.event.pull_request.title }}'
        self.check_bad(BASE.replace('    steps:', f'    defaults:\n      run:\n        shell: "{expression}"\n    steps:'))
        self.check_bad(BASE.replace('      - run: echo safe', f'      - shell: "{expression}"\n        run: echo safe'))
        self.check_bad(BASE.replace('      - run: echo safe', f'      - uses: actions/github-script@' + 'a' * 40 + f'\n        with:\n          script: "{expression}"'))

    def test_untrusted_env_as_data_and_shell_control(self):
        expression = '${{ github.event.pull_request.title }}'
        template = BASE.replace('      - run: echo safe', f'      - env:\n          TITLE: "{expression}"\n        run: echo VALUE')
        self.check_bad(template.replace('VALUE', '$TITLE'))
        self.check_bad(template.replace('VALUE', '${TITLE:-default}'))
        self.assertEqual([], pins.check_workflow(template.replace('VALUE', '"$TITLE"'), 'test.yml'))
        self.check_bad(template.replace('echo VALUE', 'eval "$TITLE"'))
        self.check_bad(template.replace('echo VALUE', 'bash -c "$TITLE"'))
        self.check_bad(template.replace('echo VALUE', '. "$TITLE"'))
        self.assertEqual([], pins.check_workflow(template.replace('echo VALUE', 'git diff "$TITLE" -- . ":!plans"'), 'test.yml'))
        self.check_bad(template.replace('TITLE:', 'BASH_ENV:').replace('VALUE', 'safe'))
        self.check_bad(BASE.replace('    steps:', f'    env: {{TITLE: "{expression}"}}\n    steps:').replace('echo safe', 'echo $TITLE'))

    def test_release_prerelease_input(self):
        text = BASE.replace('      - run: echo safe', '      - uses: softprops/action-gh-release@' + 'a' * 40 + '\n        with:\n          prerelease: ${{ contains(github.ref_name, \'-\') }}')
        self.assertEqual([], pins.check_workflow(text, 'test.yml'))
        self.check_bad(text.replace('prerelease:', 'script:'))
        release = (pins.ROOT / '.github/workflows/release.yml').read_text()
        self.assertEqual([], pins.check_workflow(release, 'release.yml'))

    def test_dependabot_target_only_with_fixed_safe_job(self):
        text = (pins.ROOT / '.github/workflows/dependabot-auto-merge.yml').read_text()
        self.assertEqual([], pins.check_workflow(text, 'dependabot-auto-merge.yml'))
        for unsafe in (
            text.replace('dependabot/fetch-metadata@', 'actions/checkout@'),
            text.replace('gh pr merge --disable-auto "$PR_URL"', 'gh pr merge --auto --squash "$PR_URL"'),
            text.replace('gh pr merge --disable-auto "$PR_URL"', 'echo ignored'),
            text.replace('cancel-in-progress: false', 'cancel-in-progress: true'),
            text.replace('if: always()', "if: steps.meta.outcome == 'success'"),
            text.replace('    steps:', '    env: {BASH_ENV: /tmp/attacker}\n    steps:'),
            text.replace('permissions:\n  contents: read', 'env: {BASH_ENV: /tmp/attacker}\npermissions:\n  contents: read'),
        ):
            with self.subTest(unsafe=unsafe):
                self.assertTrue(pins.check_workflow(unsafe, 'dependabot-auto-merge.yml'))
        self.assertTrue(pins.check_workflow(text, 'other.yml'))

    def test_directory_argument_is_used(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'unsafe.yml'
            path.write_text(BASE.replace('echo safe', 'echo ${{ github.event.issue.title }}'))
            result = subprocess.run([sys.executable, str(pins.ROOT / 'bin/check-workflow-pins.py'), directory],
                                    capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('unsafe.yml', result.stderr)

    def test_duplicate_keys(self):
        self.check_bad(BASE.replace('permissions: {contents: read}', 'permissions: {contents: read}\npermissions: write-all'))


if __name__ == '__main__':
    unittest.main()
