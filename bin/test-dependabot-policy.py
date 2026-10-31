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
        'github.event.pull_request.head.repo.full_name': head,
        'github.event.pull_request.base.repo.full_name': base,
        'github.repository': 'owner/repo',
        'github.actor': actor,
        'steps.meta.outputs.update-type': update,
    }
    return evaluate(JOB['if'], values) and evaluate(MERGE['if'], values)


class DependabotPolicyTest(unittest.TestCase):
    def test_eligibility_matrix(self):
        self.assertTrue(eligible())
        for case in (
            {'author': 'human'},
            {'author': 'human', 'actor': 'dependabot[bot]'},
            {'head': 'fork/repo'},
            {'base': 'other/repo'},
            {'update': 'version-update:semver-minor'},
            {'update': 'version-update:semver-major'},
            # The action reports the highest update type in a mixed group.
            {'update': 'version-update:semver-major', 'actor': 'human'},
            {'update': ''},
        ):
            with self.subTest(case=case):
                self.assertFalse(eligible(**case))
        self.assertTrue(eligible(actor='human'))

    def test_trusted_metadata_and_permissions(self):
        self.assertEqual(set(WORKFLOW['on']), {'pull_request_target'})
        self.assertEqual(JOB['permissions'], {'contents': 'write', 'pull-requests': 'write'})
        self.assertEqual(META['uses'],
                         'dependabot/fetch-metadata@25dd0e34f4fe68f24cc83900b1fe3fe149efef98')
        self.assertNotIn('with', META)  # Preserve the action's verification defaults.
        self.assertNotIn('continue-on-error', META)
        self.assertEqual(len(JOB['steps']), 2)  # No PR checkout or untrusted code.

    def test_failing_required_check_cannot_be_bypassed_by_command(self):
        # A stub models GitHub's required-check gate: --auto requests a merge but
        # leaves a failing PR open. Assert the actual workflow command is used.
        with tempfile.TemporaryDirectory() as directory:
            gh = pathlib.Path(directory) / 'gh'
            gh.write_text('#!/bin/sh\n[ "$*" = "pr merge --auto --squash https://example.test/pr/1" ] || exit 1\n'
                          'echo "required check failed: PR remains open"\n')
            gh.chmod(0o755)
            result = subprocess.run(['/bin/bash', '-e', '-c', MERGE['run']], capture_output=True,
                                    text=True, env={'PATH': directory, 'PR_URL': 'https://example.test/pr/1'})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('PR remains open', result.stdout)


if __name__ == '__main__':
    unittest.main()
