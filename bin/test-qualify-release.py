#!/usr/bin/env python3
"""Safety and evidence checks for the local-only qualification runner."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

RUNNER = Path(__file__).resolve().parent / "qualify-release.sh"


class LocalQualificationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="azguard-local-qual-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        self.git("init", "--quiet")
        self.git("config", "user.name", "Qualification Test")
        self.git("config", "user.email", "qualification@example.invalid")
        for path in ("bin/release-gate.php", "bin/verify-release-evidence.php", "packages/core/src/AzGuard.php"):
            file = self.repo / path
            file.parent.mkdir(parents=True, exist_ok=True)
            # PHP lint must not execute candidate code.
            file.write_text("<?php file_put_contents(" + json.dumps(str(self.root / 'executed')) + ", 'bad');\n", encoding="utf-8")
        (self.repo / "composer.lock").write_text('{"packages": []}\n', encoding="utf-8")
        self.git("add", "bin", "packages", "composer.lock")
        self.git("commit", "--quiet", "-m", "test fixture")
        self.sha = self.git("rev-parse", "HEAD").strip()
        self.candidate = self.root / "candidate.json"
        self.candidate.write_text(json.dumps({"schema": "azguard.local-qualification-candidate.v1", "source_sha": self.sha}))
        self.report = self.root / "report.json"

    def git(self, *args):
        env = {"PATH": "/usr/bin:/bin", "HOME": str(self.root), "GIT_CONFIG_NOSYSTEM": "1",
               "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_CONFIG_COUNT": "1",
               "GIT_CONFIG_KEY_0": "core.hooksPath", "GIT_CONFIG_VALUE_0": "/dev/null"}
        return subprocess.check_output(["git", *args], cwd=self.repo, env=env, text=True, stderr=subprocess.PIPE)

    def run_runner(self, *extra):
        return subprocess.run(["bash", str(RUNNER), "--candidate", str(self.candidate), "--no-publish",
                               "--report", str(self.report), "--repository", str(self.repo), *extra],
                              text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)

    def test_receipt_reports_real_checks_but_never_claims_complete(self):
        result = self.run_runner()
        self.assertEqual(1, result.returncode, result.stderr)
        receipt = json.loads(self.report.read_text())
        self.assertEqual("incomplete", receipt["result"])
        self.assertEqual("nonrelease", receipt["qualification"])
        self.assertEqual(self.sha, receipt["source_sha"])
        self.assertTrue(receipt["clean_source"] and receipt["isolated_checkout"] and receipt["no_publish"])
        self.assertTrue(receipt["required_lanes_unavailable"])
        self.assertEqual(3, len(receipt["checks"]))
        for check in receipt["checks"]:
            self.assertEqual(0, check["exit"])
            log = self.root / check["log"]
            self.assertEqual(hashlib.sha256(log.read_bytes()).hexdigest(), check["sha256"])
        self.assertFalse((self.root / "executed").exists())
        self.assertEqual("", self.git("status", "--porcelain"))

    def test_dirty_and_wrong_head_are_rejected_without_receipt(self):
        (self.repo / "untracked.txt").write_text("not committed")
        dirty = self.run_runner()
        self.assertEqual(2, dirty.returncode)
        self.assertIn("dirty", dirty.stderr)
        self.assertFalse(self.report.exists())
        (self.repo / "untracked.txt").unlink()
        self.candidate.write_text(json.dumps({"schema": "azguard.local-qualification-candidate.v1", "source_sha": "0" * 40}))
        wrong = self.run_runner()
        self.assertEqual(2, wrong.returncode)
        self.assertIn("does not match", wrong.stderr)
        self.assertFalse(self.report.exists())

    def test_existing_report_or_symlink_are_never_overwritten(self):
        self.report.write_text("keep")
        result = self.run_runner()
        self.assertEqual(2, result.returncode)
        self.assertEqual("keep", self.report.read_text())
        self.report.unlink()
        target = self.root / "target"
        self.report.symlink_to(target)
        result = self.run_runner()
        self.assertEqual(2, result.returncode)
        self.assertFalse(target.exists())

    def test_a_failing_check_is_failed_not_incomplete_or_green(self):
        (self.repo / "bin/release-gate.php").write_text("<?php invalid syntax!\n")
        self.git("add", "bin/release-gate.php")
        self.git("commit", "--quiet", "-m", "bad syntax")
        sha = self.git("rev-parse", "HEAD").strip()
        self.candidate.write_text(json.dumps({"schema": "azguard.local-qualification-candidate.v1", "source_sha": sha}))
        result = self.run_runner()
        self.assertEqual(1, result.returncode, result.stderr)
        receipt = json.loads(self.report.read_text())
        self.assertEqual("failed", receipt["result"])
        self.assertNotEqual(0, receipt["checks"][0]["exit"])

    def test_checkout_disables_source_fsmonitor_and_git_hooks(self):
        marker = self.root / "git-hook-executed"
        script = self.root / "malicious-hook"
        script.write_text("#!/bin/sh\nprintf invoked > '" + str(marker) + "'\n")
        script.chmod(0o755)
        self.git("config", "core.fsmonitor", str(script))
        hooks = self.repo / ".git/hooks"
        (hooks / "post-checkout").write_text(script.read_text())
        (hooks / "post-checkout").chmod(0o755)
        result = self.run_runner()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertFalse(marker.exists())

    def test_no_publish_is_mandatory_and_unknown_candidate_keys_are_rejected(self):
        command = ["bash", str(RUNNER), "--candidate", str(self.candidate), "--report", str(self.report),
                   "--repository", str(self.repo)]
        self.assertNotEqual(0, subprocess.run(command, capture_output=True).returncode)
        self.assertFalse(self.report.exists())
        self.candidate.write_text(json.dumps({"schema": "azguard.local-qualification-candidate.v1", "source_sha": self.sha,
                                              "checks": [["sh", "-c", "touch /tmp/should-not-run"]]}))
        self.assertEqual(2, self.run_runner().returncode)
        self.assertFalse(self.report.exists())


if __name__ == "__main__":
    unittest.main()
