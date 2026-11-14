#!/usr/bin/env python3
"""Negative examples for the workflow policy."""

import importlib.util
import hashlib
import subprocess
import sys
import tempfile
from pathlib import Path
import unittest
from unittest import mock

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

    def test_self_repository_workflows_are_allowed_only_as_jobs(self):
        text = '''
on: workflow_dispatch
permissions: {contents: read}
jobs:
  test:
    uses: $/.github/workflows/tests.yml
'''
        def check_reviewed(workflow):
            pins.REVIEWED_WORKFLOWS['self.yml'] = hashlib.sha256(workflow.encode()).hexdigest()
            return pins.check_workflow(workflow, 'self.yml')

        self.addCleanup(pins.REVIEWED_WORKFLOWS.pop, 'self.yml', None)
        self.assertEqual([], check_reviewed(text))
        self.assertTrue(any('forbidden uses' in error for error in check_reviewed(text.replace('tests.yml', 'unknown.yml'))))
        self.assertTrue(any('forbidden uses' in error for error in check_reviewed(BASE.replace('actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803', '$/.github/workflows/tests.yml'))))

    def test_permissions(self):
        self.check_bad(BASE.replace('permissions: {contents: read}', 'permissions: write-all'))
        self.check_bad(BASE.replace('runs-on: ubuntu-latest', 'permissions: {contents: write}\n    runs-on: ubuntu-latest'))

    def test_checkout_credentials(self):
        self.check_bad(BASE.replace('persist-credentials: false', 'persist-credentials: true'))
        self.check_bad(BASE.replace('        with: {persist-credentials: false}\n', ''))

    def test_size_label_permissions_are_job_specific(self):
        text = (pins.ROOT / '.github/workflows/pr-check.yml').read_text()
        self.assertEqual([], pins.check_workflow(text, 'pr-check.yml'))
        unsafe = text.replace('  title:\n', '  title:\n    permissions: {contents: read, pull-requests: write}\n')
        self.assertNotEqual(text, unsafe)
        with mock.patch.dict(pins.REVIEWED_WORKFLOWS, {'pr-check.yml': hashlib.sha256(unsafe.encode()).hexdigest()}):
            self.assertTrue(any('title: forbidden permission pull-requests: write' in error
                                for error in pins.check_workflow(unsafe, 'pr-check.yml')))

    def test_untrusted_inline_and_privileged_trigger(self):
        self.check_bad(BASE.replace('echo safe', 'echo ${{ github.event.pull_request.title }}'))
        self.check_bad(BASE.replace('echo safe', "echo ${{ github['event'].pull_request.title }}"))
        self.check_bad(BASE.replace('echo safe', "echo ${{ inputs['value'] }}"))
        self.check_bad(BASE.replace('pull_request', 'pull_request_target'))
        for expression in ("format('{0}', github.event.pull_request.title)",
                           "fromJSON('{\"a\":1}') && github.event.pull_request.title",
                           'toJSON(env)', 'env.TITLE'):
            with self.subTest(expression=expression):
                self.check_bad(BASE.replace('echo safe', 'echo ${{ ' + expression + ' }}'))

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
        for run in ('python3 -c "$TITLE"', '"$TITLE"', 'env "$TITLE"',
                    'bash "$TITLE"', 'printf "%s" "$TITLE" | bash',
                    'echo "$TITLE"; python3 -c "$TITLE"'):
            with self.subTest(run=run):
                self.check_bad(template.replace('echo VALUE', run))
        self.check_bad(template.replace('echo VALUE', 'git diff "$TITLE" -- . ":!plans"'))
        self.check_bad(template.replace('TITLE:', 'BASH_ENV:').replace('VALUE', 'safe'))
        self.check_bad(BASE.replace('    steps:', f'    env: {{TITLE: "{expression}"}}\n    steps:').replace('echo safe', 'echo $TITLE'))

    def test_fail_closed_execution_boundaries(self):
        expression = '${{ github.event.pull_request.title }}'
        for field in ('runs-on', 'container'):
            self.check_bad(BASE.replace('runs-on: ubuntu-latest', f'{field}: "{expression}"'))
        self.check_bad(BASE.replace('    steps:', f'    container:\n      image: "{expression}"\n    steps:'))
        for expression in ('${{ needs.a.outputs.title }}', '${{ steps.a.outputs.title }}', '${{ matrix.title }}'):
            self.check_bad(BASE.replace('echo safe', 'echo ' + expression))
        producer = BASE.replace('jobs:\n', 'jobs:\n  a:\n    runs-on: ubuntu-latest\n    outputs:\n      title: ${{ github.event.pull_request.title }}\n    steps:\n      - run: echo safe\n')
        self.check_bad(producer.replace('echo safe\n', 'echo ${{ needs.a.outputs.title }}\n', 1))
        for name in ('PATH', 'LD_PRELOAD', 'LD_LIBRARY_PATH', 'NODE_OPTIONS', 'PYTHONPATH', 'GIT_EXTERNAL_DIFF', 'GIT_SSH_COMMAND'):
            self.check_bad(BASE.replace('      - run: echo safe', f'      - env:\n          {name}: "${{{{ github.event.pull_request.title }}}}"\n        run: echo safe'))
        self.check_bad(BASE.replace('echo safe', 'echo $GITHUB_HEAD_REF'))
        self.check_bad(BASE.replace('      - run: echo safe', '      - env:\n          TITLE: "${{ github.event.pull_request.title }}"\n        run: python3 -c \'import os; os.system(os.environ["TITLE"])\''))

    def test_snapshot_binds_workflow_environment_and_runner(self):
        for filename in ('compatibility.yml', 'release.yml', 'tests.yml'):
            with self.subTest(filename=filename):
                workflow = (pins.ROOT / '.github/workflows' / filename).read_text()
                self.assertEqual([], pins.check_workflow(workflow, filename))
                self.assertIn('reviewed workflow changed: full review and digest update required',
                              pins.check_workflow(workflow + '\n# review required\n', filename))
        text = (pins.ROOT / '.github/workflows/compatibility.yml').read_text()
        for modified in (text.replace('permissions:\n', 'env: {GIT_EXTERNAL_DIFF: attacker}\npermissions:\n', 1),
                         text.replace('runs-on: ubuntu-latest', 'runs-on: ${{ github.event.pull_request.title }}')):
            self.assertTrue(pins.check_workflow(modified, 'compatibility.yml'))

    def test_env_context_cannot_be_injected_into_action_script(self):
        for expression in ('env.TITLE', 'toJSON(env)'):
            text = BASE.replace('      - run: echo safe',
                '      - uses: actions/github-script@' + 'a' * 40 +
                '\n        with:\n          script: ${{ ' + expression + ' }}')
            self.check_bad(text)

    def test_reviewed_scripts_stay_accepted_and_changes_require_review(self):
        for filename in ('compatibility.yml', 'release.yml', 'bench.yml', 'workers.yml',
                         'dependabot-auto-merge.yml'):
            with self.subTest(filename=filename):
                text = (pins.ROOT / '.github/workflows' / filename).read_text()
                self.assertEqual([], pins.check_workflow(text, filename))
        text = (pins.ROOT / '.github/workflows/compatibility.yml').read_text()
        self.assertTrue(pins.check_workflow(text.replace('HEAD "$LABELS"', 'HEAD "$LABELS"; bash "$LABELS"'), 'compatibility.yml'))

    def test_release_tag_check_receipt_is_bound_to_exact_script(self):
        text = (pins.ROOT / '.github/workflows/release.yml').read_text()
        needle = 'test "$(git rev-parse HEAD)" = "${TAGGED_SHA}"'
        unsafe = text.replace(needle, 'test "$(git rev-parse HEAD^{commit})" = "${TAGGED_SHA}"', 1)
        self.assertNotEqual(text, unsafe)
        # Even with the entire changed workflow provisionally reviewed, a changed
        # script using untrusted environment must receive its own review receipt.
        with mock.patch.dict(pins.REVIEWED_WORKFLOWS, {'release.yml': hashlib.sha256(unsafe.encode()).hexdigest()}):
            self.assertIn('validate: unreviewed use of untrusted environment',
                          pins.check_workflow(unsafe, 'release.yml'))

    def test_release_prerelease_input(self):
        text = BASE.replace('      - run: echo safe', '      - uses: softprops/action-gh-release@' + 'a' * 40 + '\n        with:\n          prerelease: ${{ contains(github.ref_name, \'-\') }}')
        self.check_bad(text)
        self.check_bad(text.replace('prerelease:', 'script:'))
        release = (pins.ROOT / '.github/workflows/release.yml').read_text()
        self.assertEqual([], pins.check_workflow(release, 'release.yml'))

    def test_checkout_only_accepts_exact_candidate_expression(self):
        checkout = BASE.replace('with: {persist-credentials: false}',
                                'with: {persist-credentials: false, ref: "${{ github.sha }}"}')
        self.assertFalse(any('untrusted expression in action input ref' in error
                             for error in pins.check_workflow(checkout, 'test.yml')))
        unsafe = checkout.replace('${{ github.sha }}', '${{ github.event.pull_request.head.ref }}')
        self.assertTrue(any('untrusted expression in action input ref' in error
                            for error in pins.check_workflow(unsafe, 'test.yml')))

    def test_release_artifact_input_only_accepts_exact_run_attempt(self):
        action = 'actions/download-artifact@9000827ccba6bdab643e8b6fd33ac0654aef8333'
        text = BASE.replace('      - run: echo safe',
                            '      - uses: ' + action + '\n        with:\n          name: release-artifacts-${{ github.run_attempt }}')
        self.assertFalse(any('untrusted expression in action input name' in error
                             for error in pins.check_workflow(text, 'test.yml')))
        unsafe = text.replace('github.run_attempt', 'github.event.pull_request.title')
        self.assertTrue(any('untrusted expression in action input name' in error
                            for error in pins.check_workflow(unsafe, 'test.yml')))

    def test_consumer_artifact_pattern_is_exact_and_scoped(self):
        action = 'actions/download-artifact@9000827ccba6bdab643e8b6fd33ac0654aef8333'
        text = BASE.replace('  test:', '  consumer-matrix:').replace(
            '      - run: echo safe', '      - uses: ' + action +
            '\n        with:\n          pattern: consumer-*-${{ github.run_attempt }}')
        def has_pattern_error(workflow, filename='tests.yml'):
            return any('untrusted expression in action input pattern' in error
                       for error in pins.check_workflow(workflow, filename))

        self.assertFalse(has_pattern_error(text))
        for unsafe in (
            text.replace('github.run_attempt', 'github.event.pull_request.title'),
            text.replace('consumer-*-', 'consumer-other-'),
            text.replace('  consumer-matrix:', '  another-job:'),
            text.replace(action, 'actions/download-artifact@' + 'a' * 40),
            text.replace('actions/download-artifact@', 'other/download-artifact@'),
        ):
            with self.subTest(unsafe=unsafe):
                self.assertTrue(has_pattern_error(unsafe))
        self.assertTrue(has_pattern_error(text, 'other.yml'))

    def test_release_replacement_and_unreviewed_privileges_are_rejected(self):
        replacement = '''
on: {push: {tags: ['v*']}}
permissions: {contents: read}
jobs:
  release:
    runs-on: ubuntu-latest
    permissions: {contents: write}
    steps:
      - uses: softprops/action-gh-release@efb35369e0ad2afab669f228072c1b0d510eae64
'''
        self.assertTrue(pins.check_workflow(replacement, 'release.yml'))
        self.assertTrue(pins.check_workflow(replacement, 'new.yml'))
        # Even a harmless replacement at an existing path needs review.
        self.assertTrue(pins.check_workflow(BASE, 'release.yml'))
        # Matching a privileged filename/job never grants write to an unreviewed snapshot.
        benign = replacement.replace('      - uses: softprops/action-gh-release@efb35369e0ad2afab669f228072c1b0d510eae64', '      - run: echo safe')
        self.assertTrue(any('forbidden permission' in e for e in pins.check_workflow(benign, 'release.yml')))
        self.assertTrue(pins.check_workflow(replacement.replace('contents: write', 'contents: read'), 'new.yml'))

    def test_release_provenance_cannot_regain_write_even_after_snapshot_review(self):
        text = (pins.ROOT / '.github/workflows/release.yml').read_text()
        unsafe = text.replace('  provenance:\n    name: Verify split mapping and attest final artifacts\n    needs: [gate, split]\n    runs-on: ubuntu-latest\n    timeout-minutes: 75\n    permissions:\n      contents: read',
                                '  provenance:\n    name: Verify split mapping and attest final artifacts\n    needs: [gate, split]\n    runs-on: ubuntu-latest\n    timeout-minutes: 75\n    permissions:\n      contents: read\n      id-token: write')
        self.assertNotEqual(text, unsafe)
        with mock.patch.dict(pins.REVIEWED_WORKFLOWS, {'release.yml': hashlib.sha256(unsafe.encode()).hexdigest()}):
            self.assertTrue(any('provenance: forbidden permission id-token: write' in error
                                for error in pins.check_workflow(unsafe, 'release.yml')))

    def test_unreviewed_data_example_cannot_print_secrets(self):
        template = BASE.replace('      - run: echo safe', '      - env:\n          TITLE: "VALUE"\n        run: echo "$TITLE"')
        for value in ('${{ toJSON(secrets) }}', '${{ secrets.GITHUB_TOKEN }}',
                      '${{ github.event.pull_request.title }}${{ toJSON(secrets) }}'):
            with self.subTest(value=value):
                self.check_bad(template.replace('VALUE', value))
        for value in ('safe text', '${{ github.event.pull_request.title }}'):
            with self.subTest(value=value):
                self.assertEqual([], pins.check_workflow(template.replace('VALUE', value), 'new.yml'))

    def test_dependabot_target_only_with_fixed_safe_job(self):
        text = (pins.ROOT / '.github/workflows/dependabot-auto-merge.yml').read_text()
        self.assertEqual([], pins.check_workflow(text, 'dependabot-auto-merge.yml'))
        for unsafe in (
            text.replace('dependabot/fetch-metadata@', 'actions/checkout@'),
            text.replace('gh pr merge --disable-auto "$PR_URL"', 'gh pr merge --auto --squash "$PR_URL"'),
            text.replace('gh pr merge --disable-auto "$PR_URL"', 'echo ignored'),
            text.replace('cancel-in-progress: false', 'cancel-in-progress: true'),
            text.replace(', auto_merge_enabled', ''),
            text.replace("cron: '*/15 * * * *'", "cron: '0 0 * * *'"),
            text.replace('gh pr merge --disable-auto "$number" --repo "$REPOSITORY"', 'echo ignored'),
            text.replace('      pull-requests: write', '      contents: write\n      pull-requests: write', 1),
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

    def test_missing_reviewed_release_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            (Path(directory) / 'safe.yml').write_text(BASE)
            result = subprocess.run([sys.executable, str(pins.ROOT / 'bin/check-workflow-pins.py'), directory],
                                    capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('release.yml: reviewed workflow missing', result.stderr)

    def test_package_workflows_require_each_reviewed_snapshot(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            names = list(pins.REVIEWED_PACKAGE_WORKFLOWS)
            digests = {name: hashlib.sha256(BASE.encode()).hexdigest() for name in names}
            for name in names:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(BASE)
            with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS', digests):
                self.assertEqual([], pins.check_package_workflows(root))
                for name in names:
                    with self.subTest(name=name):
                        path = root / name
                        path.write_text(BASE + '\n# changed after review\n')
                        self.assertTrue(any(p == path and 'independent review' in error
                                            for p, error in pins.check_package_workflows(root)))
                        path.unlink()
                        self.assertTrue(any(p == path and 'missing or invalid' in error
                                            for p, error in pins.check_package_workflows(root)))
                        path.write_text(BASE)
            with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS', dict.fromkeys(names)):
                self.assertEqual(len(names), len(pins.check_package_workflows(root)))

    def test_package_workflows_check_pins_even_for_matching_snapshots(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            names = list(pins.REVIEWED_PACKAGE_WORKFLOWS)
            for name in names:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(BASE)
            for name in names:
                path = root / name
                for unsafe, expected in (
                    (BASE.replace('@d23441a48e516b6c34aea4fa41551a30e30af803', '@v6'), 'forbidden uses'),
                    (BASE.replace('persist-credentials: false', 'persist-credentials: true'), 'persisted credentials'),
                    (BASE.replace('runs-on: ubuntu-latest', 'runs-on: ubuntu-latest\n    permissions: {contents: write}'), 'forbidden permissions'),
                    (BASE.replace('runs-on: ubuntu-latest', 'runs-on: ubuntu-latest\n    permissions: {id-token: write}'), 'forbidden permissions'),
                ):
                    with self.subTest(name=name, expected=expected):
                        path.write_text(unsafe)
                        digests = {other: hashlib.sha256((root / other).read_bytes()).hexdigest() for other in names}
                        with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS', digests):
                            self.assertTrue(any(p == path and expected in error
                                                for p, error in pins.check_package_workflows(root)))
                        path.write_text(BASE)

    def test_package_snapshot_does_not_bypass_untrusted_input_checks(self):
        name = 'packages/core/.github/workflows/attestation-setup.yml'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            unsafe_workflows = (
                BASE.replace('echo safe', 'echo ${{ github.event.issue.title }}'),
                BASE.replace('echo safe', 'echo ${{ inputs.command }}'),
                BASE.replace('      - run: echo safe',
                             '      - env:\n          BASH_ENV: "${{ inputs.script }}"\n        run: echo safe'),
                BASE.replace('      - run: echo safe',
                             '      - uses: actions/github-script@' + 'a' * 40 +
                             '\n        with:\n          script: ${{ inputs.code }}'),
            )
            for unsafe in unsafe_workflows:
                with self.subTest(unsafe=unsafe):
                    path.write_text(unsafe)
                    with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS',
                                           {name: hashlib.sha256(unsafe.encode()).hexdigest()}):
                        self.assertTrue(pins.check_package_workflows(root))

    def test_package_probe_permissions_are_path_and_job_specific(self):
        permitted = 'packages/core/.github/workflows/attestation-setup.yml'
        text = BASE.replace('  test:', '  probe:').replace(
            'runs-on: ubuntu-latest',
            'runs-on: ubuntu-latest\n    permissions: {contents: read, id-token: write, attestations: write}')
        with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS',
                               {permitted: hashlib.sha256(text.encode()).hexdigest()}):
            self.assertEqual([], pins.check_workflow(text, permitted, package=True))
            for unsafe_name, unsafe_text in (
                (permitted, text.replace('  probe:', '  other:')),
                (permitted, text.replace('attestations: write', 'contents: write')),
                ('packages/another/.github/workflows/attestation-setup.yml', text),
            ):
                with self.subTest(name=unsafe_name, text=unsafe_text):
                    self.assertTrue(pins.check_workflow(unsafe_text, unsafe_name, package=True))

    def test_unknown_package_workflows_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            names = list(pins.REVIEWED_PACKAGE_WORKFLOWS)
            digests = {name: hashlib.sha256(BASE.encode()).hexdigest() for name in names}
            for name in names:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(BASE)
            with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS', digests):
                self.assertEqual([], pins.check_package_workflows(root))
                for name in ('packages/core/.github/workflows/extra.yml',
                             'packages/filament/.github/workflows/extra.yaml',
                             'packages/another/.github/workflows/extra.yml'):
                    with self.subTest(name=name):
                        path = root / name
                        path.parent.mkdir(parents=True, exist_ok=True)
                        path.write_text(BASE)
                        self.assertTrue(any(p == path and 'unknown package workflow' in error
                                            for p, error in pins.check_package_workflows(root)))
                        path.unlink()

    def test_current_package_workflows_meet_pin_and_permission_rules(self):
        # Simulate approved snapshots only for this test; production policy stays fail-closed.
        digests = {name: hashlib.sha256((pins.ROOT / name).read_bytes()).hexdigest()
                   for name in pins.REVIEWED_PACKAGE_WORKFLOWS}
        with mock.patch.object(pins, 'REVIEWED_PACKAGE_WORKFLOWS', digests):
            self.assertEqual([], pins.check_package_workflows(pins.ROOT))

    def test_duplicate_keys(self):
        self.check_bad(BASE.replace('permissions: {contents: read}', 'permissions: {contents: read}\npermissions: write-all'))


if __name__ == '__main__':
    unittest.main()
