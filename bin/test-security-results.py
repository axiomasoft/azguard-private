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

    def test_sha_mismatch_fails(self):
        self.assertTrue(self.check(sha='0' * 40))
        self.assertTrue(self.check(sha='bad'))

    def test_gitleaks_clean_and_finding(self):
        self.report.unlink()
        self.report = self.directory / 'scan.json'
        self.write([])
        self.assertFalse(self.check(scanner='gitleaks-history'))
        self.write([{'RuleID': 'synthetic'}])
        self.assertTrue(self.check(scanner='gitleaks-history'))


if __name__ == '__main__':
    unittest.main()
