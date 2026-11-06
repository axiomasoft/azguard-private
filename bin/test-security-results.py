#!/usr/bin/env python3
"""Exercise fail-closed report validation with positive and negative evidence."""

import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('security_results', Path(__file__).with_name('check-security-results.py'))
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


class ResultTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.metadata = self.directory / 'metadata.json'
        self.sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
        self.report = self.directory / 'scan.sarif'
        self.sarif = {'version': '2.1.0', 'runs': [{'tool': {'driver': {'name': 'CodeQL'}},
                      'automationDetails': {'id': '/language:actions/'},
                      'results': [], 'invocations': [{'executionSuccessful': True}]}]}
        self.write(self.sarif)

    def write(self, data):
        self.report.write_text(json.dumps(data))

    def check(self, scanner='codeql-actions', outcome='success', sha=None):
        return checker.check(scanner, outcome, sha or self.sha, self.directory, self.metadata)

    def test_clean_result_records_scope_and_report_digest(self):
        self.assertFalse(self.check())
        record = json.loads(self.metadata.read_text())
        self.assertEqual(self.sha, record['sha'])
        self.assertEqual(checker.SCOPES['codeql-actions'], record['scope'])
        self.assertEqual(64, len(record['report_sha256']))

    def test_zizmor_records_all_workflow_directories(self):
        self.sarif['runs'][0]['tool']['driver']['name'] = 'zizmor'
        self.write(self.sarif)
        self.assertFalse(self.check(scanner='zizmor'))
        record = json.loads(self.metadata.read_text())
        self.assertEqual(
            'GitHub Actions workflows in .github/workflows and packages/core/.github/workflows; pedantic, medium and higher',
            record['scope'],
        )

    def test_findings_skipped_failed_timeout_and_invalid_scope_fail(self):
        for outcome in ('failure', 'skipped', 'cancelled', 'timed_out', ''):
            with self.subTest(outcome=outcome):
                self.assertTrue(self.check(outcome=outcome))
        self.assertTrue(self.check(scanner='php'))
        self.sarif['runs'][0]['results'] = [{'ruleId': 'bad'}]
        self.write(self.sarif)
        self.assertTrue(self.check())

    def test_missing_corrupt_duplicate_or_wrong_tool_report_fails(self):
        self.report.unlink()
        self.assertTrue(self.check())
        self.report.write_text('{')
        self.assertTrue(self.check())
        self.write(self.sarif)
        (self.directory / 'extra.sarif').write_text('{}')
        self.assertTrue(self.check())
        (self.directory / 'extra.sarif').unlink()
        self.assertTrue(self.check(scanner='zizmor'))

    def test_sarif_error_and_missing_results_fail(self):
        invocation = self.sarif['runs'][0]['invocations'][0]
        invocation['toolExecutionNotifications'] = [{'level': 'error'}]
        self.write(self.sarif)
        self.assertTrue(self.check())
        invocation.pop('toolExecutionNotifications')
        invocation['executionSuccessful'] = False
        self.write(self.sarif)
        self.assertTrue(self.check())
        del self.sarif['runs'][0]['results']
        self.write(self.sarif)
        self.assertTrue(self.check())

    def test_missing_empty_or_incomplete_invocations_fail_for_each_sarif_scanner(self):
        for scanner, name in [('codeql-actions', 'CodeQL'), ('zizmor', 'zizmor')]:
            for invocations in (None, [], [{}], [{'executionSuccessful': False}]):
                with self.subTest(scanner=scanner, invocations=invocations):
                    self.sarif['runs'][0]['tool']['driver']['name'] = name
                    if invocations is None:
                        self.sarif['runs'][0].pop('invocations', None)
                    else:
                        self.sarif['runs'][0]['invocations'] = invocations
                    self.write(self.sarif)
                    self.assertTrue(self.check(scanner=scanner))

    def test_contradictory_exit_and_invalid_notification_fail(self):
        for scanner, name in [('codeql-actions', 'CodeQL'), ('zizmor', 'zizmor')]:
            self.sarif['runs'][0]['tool']['driver']['name'] = name
            invocation = self.sarif['runs'][0]['invocations'][0]
            for exit_code in (1, -1, True, '0', None):
                with self.subTest(scanner=scanner, exit_code=exit_code):
                    invocation['exitCode'] = exit_code
                    self.write(self.sarif)
                    self.assertTrue(self.check(scanner=scanner))
            invocation['exitCode'] = 0
            for key in ('toolExecutionNotifications', 'toolConfigurationNotifications'):
                for notifications in ([{'level': 'error'}], [{'level': 'fatal'}], [{'level': None}], [[]], None, {}):
                    with self.subTest(scanner=scanner, key=key, notifications=notifications):
                        invocation[key] = notifications
                        self.write(self.sarif)
                        self.assertTrue(self.check(scanner=scanner))
                invocation[key] = [{}, {'level': 'warning'}]
                self.write(self.sarif)
                self.assertFalse(self.check(scanner=scanner))
                del invocation[key]

    def test_sha_mismatch_fails(self):
        self.assertTrue(self.check(sha='0' * 40))
        self.assertTrue(self.check(sha='bad'))

    def test_codeql_category_must_match_the_requested_language(self):
        workflow = Path(__file__).resolve().parents[1] / '.github/workflows/codeql.yml'
        self.assertIn("category: '/language:${{ matrix.language }}/'", workflow.read_text())
        self.assertTrue(self.check(scanner='codeql-javascript-typescript'))
        for category in (None, '', '/language:actions', '/language:php/', '/language:actions/extra/'):
            with self.subTest(category=category):
                self.sarif['runs'][0]['automationDetails'] = {'id': category}
                self.write(self.sarif)
                self.assertTrue(self.check())
        del self.sarif['runs'][0]['automationDetails']
        self.write(self.sarif)
        self.assertTrue(self.check())
        self.sarif['runs'][0]['automationDetails'] = {'id': '/language:javascript-typescript/'}
        self.write(self.sarif)
        self.assertFalse(self.check(scanner='codeql-javascript-typescript'))

    def test_gitleaks_clean_and_finding(self):
        self.report.unlink()
        self.report = self.directory / 'scan.json'
        self.write([])
        self.assertFalse(self.check(scanner='gitleaks-history'))
        self.write([{'RuleID': 'synthetic'}])
        self.assertTrue(self.check(scanner='gitleaks-history'))

    def test_gitleaks_policy_enforces_digest_product_paths_and_no_ignore_file(self):
        self.report.unlink()
        self.report = self.directory / 'scan.json'
        self.write([])
        config_text = (checker.ROOT / '.gitleaks.toml').read_text()
        (self.directory / '.gitleaks.toml').write_text(config_text)
        self.assertFalse(checker.check('gitleaks-history', 'success', self.sha, self.directory, self.metadata, root=self.directory))

        (self.directory / '.gitleaksignore').write_text('')
        self.assertTrue(checker.check('gitleaks-history', 'success', self.sha, self.directory, self.metadata, root=self.directory))
        (self.directory / '.gitleaksignore').unlink()

        (self.directory / '.gitleaks.toml').write_text(config_text + '\n# modified\n')
        self.assertTrue(checker.check('gitleaks-history', 'success', self.sha, self.directory, self.metadata, root=self.directory))

        for forbidden in ("'''^packages/'''", "'''^src/'''", "'''^config/'''", "'''^\\.github/workflows/'''"):
            with self.subTest(forbidden=forbidden):
                bad_config = config_text.replace("'''^plans/'''", f"'''^plans/''',\n    {forbidden}")
                bad_sha = checker.hashlib.sha256(bad_config.encode('utf-8')).hexdigest()
                (self.directory / '.gitleaks.toml').write_text(bad_config)
                with self.assertRaises(ValueError):
                    checker.validate_gitleaks_policy(self.directory, expected_sha256=bad_sha)


if __name__ == '__main__':
    unittest.main()
