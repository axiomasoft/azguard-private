#!/usr/bin/env python3
"""Exercise the workflow's eligibility expressions and auto-merge contract offline."""

import pathlib
import subprocess
import tempfile
import unittest

import yaml


ROOT = pathlib.Path(__file__).resolve().parent.parent
WORKFLOW = yaml.load(
    (ROOT / '.github/workflows/dependabot-auto-merge.yml').read_text(),
    Loader=yaml.BaseLoader,
)
JOB = WORKFLOW['jobs']['auto-merge']
META, MERGE = JOB['steps']


def evaluate(expression, values):
    """Evaluate the small, explicitly supported subset used by this workflow."""
    import re
    token = re.compile(r"\s*(==|&&|\|\||'[^']*'|[a-zA-Z_][a-zA-Z0-9_.-]*)")
    tokens = []
    while expression.strip():
        match = token.match(expression)
        if not match:
            raise ValueError('unsupported expression: ' + expression)
        tokens.append(match.group(1))
        expression = expression[match.end():]
    if not tokens or len(tokens) % 4 != 3:
        raise ValueError('unsupported expression')
    def value(token):
        return token[1:-1] if token.startswith("'") else values[token]
    terms = []
    operators = []
    for offset in range(0, len(tokens), 4):
        if tokens[offset + 1] != '==':
            raise ValueError('unsupported comparison')
        terms.append(value(tokens[offset]) == value(tokens[offset + 2]))
        if offset + 3 < len(tokens):
            operators.append(tokens[offset + 3])
    if any(op not in ('&&', '||') for op in operators):
        raise ValueError('unsupported operator')
    # AND binds more tightly than OR, as in GitHub expressions.
    groups = [terms[0]]
    for op, term in zip(operators, terms[1:]):
        if op == '&&':
            groups[-1] = groups[-1] and term
        else:
            groups.append(term)
    return any(groups)


def eligible(author='dependabot[bot]', head='owner/repo', base='owner/repo',
             actor='dependabot[bot]', update='version-update:semver-patch'):
    values = {
        'github.event.pull_request.user.login': author,
        'github.event_name': 'pull_request_target',
        'github.event.pull_request.head.repo.full_name': head,
        'github.event.pull_request.base.repo.full_name': base,
        'github.repository': 'owner/repo',
        'github.actor': actor,
        'steps.meta.outputs.update-type': update,
    }
    return evaluate(JOB['if'], values)


class DependabotPolicyTest(unittest.TestCase):
    def test_eligibility_matrix(self):
        self.assertTrue(eligible())
        for case in (
            {'author': 'human'},
            {'author': 'human', 'actor': 'dependabot[bot]'},
            {'head': 'fork/repo'},
            {'base': 'other/repo'},
        ):
            with self.subTest(case=case):
                self.assertFalse(eligible(**case))
        self.assertTrue(eligible(actor='human'))
        # No update type, including a patch, can arm auto-merge under D21.
        self.assertNotIn('gh pr merge --auto ', MERGE['run'])

    def test_trusted_metadata_and_permissions(self):
        self.assertEqual(set(WORKFLOW['on']), {'pull_request_target', 'workflow_dispatch', 'schedule'})
        self.assertEqual(JOB['permissions'], {'pull-requests': 'write'})
        self.assertEqual(META['uses'],
                         'dependabot/fetch-metadata@25dd0e34f4fe68f24cc83900b1fe3fe149efef98')
        self.assertNotIn('with', META)  # Preserve the action's verification defaults.
        self.assertNotIn('continue-on-error', META)
        self.assertEqual(len(JOB['steps']), 2)  # No PR checkout or untrusted code.

    def test_sweep_existing_requests_and_failures(self):
        sweep = WORKFLOW['jobs']['sweep']
        self.assertEqual(sweep['permissions'], {'pull-requests': 'write'})
        with tempfile.TemporaryDirectory() as directory:
            gh = pathlib.Path(directory) / 'gh'
            gh.write_text('#!/bin/bash\n'
                          'case "$1 $2" in\n'
                          '  "api --paginate") [[ "$FAIL" != list ]] || exit 1; printf "1\\n2\\n" ;;\n'
                          '  "pr view") [[ "$FAIL" != view ]] || exit 1; [[ "$3" == 1 ]] && echo true || echo false ;;\n'
                          '  "pr merge") [[ "$FAIL" != merge ]] || exit 1; printf "%s\\n" "$*" >> "$LOG" ;;\n'
                          'esac\n')
            gh.chmod(0o755)
            log = pathlib.Path(directory) / 'log'
            for failure in ('', 'list', 'view', 'merge'):
                log.write_text('')
                result = subprocess.run(['/bin/bash', '-e', '-c', sweep['steps'][0]['run']],
                                        capture_output=True, text=True,
                                        env={'PATH': directory, 'REPOSITORY': 'owner/repo',
                                             'FAIL': failure, 'LOG': str(log)})
                self.assertEqual(result.returncode == 0, not failure, result.stderr)
                self.assertEqual(log.read_text(), 'pr merge --disable-auto 1 --repo owner/repo\n' if not failure else '')

    def test_reconcile_existing_auto_merge(self):
        # Exercise the actual shell against an API stub, including API failures.
        # GitHub's required-check enforcement is verified by the live ruleset audit.
        with tempfile.TemporaryDirectory() as directory:
            gh = pathlib.Path(directory) / 'gh'
            gh.write_text('#!/bin/bash\n'
                          'if [[ "$1 $2" == "pr view" ]]; then\n'
                          '  [[ "$API_FAIL" != true ]] || exit 1\n'
                          '  printf "%s\\n" "$ENABLED"\n'
                          'else\n'
                          '  printf "%s\\n" "$*" >> "$LOG"\n'
                          'fi\n')
            gh.chmod(0o755)
            log = pathlib.Path(directory) / 'log'
            for enabled, update, meta, api_fail, expected, exit_code in (
                ('false', 'version-update:semver-patch', 'success', 'false', '', 0),
                ('true', 'version-update:semver-patch', 'success', 'false', 'pr merge --disable-auto', 0),
                ('true', 'version-update:semver-minor', 'success', 'false', 'pr merge --disable-auto', 0),
                ('true', 'version-update:semver-major', 'success', 'false', 'pr merge --disable-auto', 0),
                ('true', '', 'failure', 'false', 'pr merge --disable-auto', 1),
                ('false', '', 'failure', 'false', '', 1),
                ('true', 'version-update:semver-patch', 'success', 'true', '', 1),
            ):
                with self.subTest(enabled=enabled, update=update, meta=meta, api_fail=api_fail):
                    log.write_text('')
                    result = subprocess.run(['/bin/bash', '-e', '-c', MERGE['run']], capture_output=True,
                                            text=True, env={'PATH': directory, 'PR_URL': 'https://example.test/pr/1',
                                                            'ENABLED': enabled, 'UPDATE_TYPE': update,
                                                            'META_RESULT': meta, 'API_FAIL': api_fail, 'LOG': str(log)})
                    self.assertEqual(result.returncode, exit_code, result.stderr)
                    self.assertEqual(log.read_text(), expected + ' https://example.test/pr/1\n' if expected else '')


if __name__ == '__main__':
    unittest.main()
