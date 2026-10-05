#!/usr/bin/env python3
"""Execute normalized P0 criteria from the isolated candidate checkout as cwd.

This adapter writes no plan evidence. The caller owns per-check logs, candidate
inventory and validation receipts. Temporary spec/lock changes are restored.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import signal
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET


ROOT = Path.cwd()
TEST_ENV = {"APP_ENV": "testing", "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:",
            "CACHE_STORE": "array", "CACHE_DRIVER": "array", "SESSION_DRIVER": "array",
            "QUEUE_CONNECTION": "sync"}


def run(argv: list[str], *, test: bool = False, capture: bool = False) -> subprocess.CompletedProcess:
    print("$ " + shlex.join(argv), flush=True)
    env = {**os.environ, "COMPOSER_PROCESS_TIMEOUT": "0", **(TEST_ENV if test else {})}
    result = subprocess.run(argv, env=env, text=True, stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.STDOUT if capture else None)
    if capture and result.stdout:
        print(result.stdout, end="" if result.stdout.endswith("\n") else "\n", flush=True)
    print(f"observed_exit={result.returncode}", flush=True)
    return result


def require(result: subprocess.CompletedProcess) -> None:
    if result.returncode != 0:
        raise RuntimeError(f"required command failed: exit {result.returncode}")


def terminate(signum, _frame):
    raise SystemExit(128 + signum)


def regression_negative() -> int:
    path = ROOT / "tests/Regression/specs/P01a.md"
    original = path.read_bytes()
    corrupted, count = re.subn(rb"(?m)^(\*\*Owning item:\*\*\s*)[^\r\n]+",
                              rb"\g<1>PLAN2.P9.9", original)
    if count != 1 or corrupted == original:
        raise RuntimeError("expected one existing P01a owning-item field")
    try:
        path.write_bytes(corrupted)
        result = run(["php", "-d", "memory_limit=1G", "vendor/bin/pest",
                      "tests/Regression/RegressionSpecsTest.php"], test=True, capture=True)
        if result.returncode <= 0 or "PLAN2.P9.9" not in (result.stdout or ""):
            raise RuntimeError("corruption did not produce the expected owning-item assertion RED")
        print("negative_control=RED (expected owning-item assertion)", flush=True)
    finally:
        path.write_bytes(original)
        if path.read_bytes() != original:
            raise RuntimeError("spec restoration failed")
        print("spec_restored_sha256=" + hashlib.sha256(original).hexdigest(), flush=True)
    require(run(["php", "-d", "memory_limit=1G", "vendor/bin/pest", "tests/Regression"], test=True))
    print("restored_control=GREEN", flush=True)
    return 0


def third_party_versions(raw: bytes) -> dict[str, str]:
    value = json.loads(raw)
    return {row["name"]: row["version"] for key in ("packages", "packages-dev")
            for row in value.get(key, []) if not row["name"].startswith("axiomasoft/")}


def lock_update() -> int:
    path = ROOT / "composer.lock"
    original = path.read_bytes()
    try:
        require(run(["composer", "update", "--lock", "--no-install", "--no-scripts", "--no-interaction"]))
        updated = path.read_bytes()
        before, after = third_party_versions(original), third_party_versions(updated)
        if before != after:
            print(json.dumps({"third_party_before": before, "third_party_after": after}, sort_keys=True), flush=True)
            raise RuntimeError("third-party package versions/membership changed during lock synchronization")
        require(run(["composer", "validate", "--strict"]))
        print("lock_update=GREEN third_party_versions_unchanged=true", flush=True)
    finally:
        path.write_bytes(original)
        if path.read_bytes() != original:
            raise RuntimeError("composer.lock restoration failed")
        print("lock_restored_sha256=" + hashlib.sha256(original).hexdigest(), flush=True)
    return 0


def grep_scan(pattern: str) -> tuple[int, str]:
    result = run(["git", "grep", "-nE", pattern, "--", ":!legacy", ":!audits", ":!plans",
                  ":!CHANGELOG.md"], capture=True)
    if result.returncode not in (0, 1):
        raise RuntimeError("git grep failed rather than reporting presence/absence")
    return result.returncode, result.stdout or ""


def old_names() -> int:
    code, output = grep_scan("axioma-studio/azguard|azguard-context")
    if code == 1:
        print("old_names=ABSENT", flush=True)
        return 0
    unexpected = []
    for row in output.splitlines():
        path, number, text = row.split(":", 2)
        # The declared exception is an explicit historical mention in the short
        # README status block / RELEASING, not arbitrary old-package references.
        historical = "0.3" in text or "legacy/0.3" in text
        readme_status = path in {"README.md", "README.ru.md"} and bool(
            re.match(r"\s*>\s*\*\*(?:Status|Статус):\*\*", text))
        if not (historical and (readme_status or path == "RELEASING.md")):
            unexpected.append(row)
    if unexpected:
        print("non_historical_or_outside_allowed_block:\n" + "\n".join(unexpected), flush=True)
        return 1
    print("old_names=EXPLICIT_HISTORICAL_ONLY (all hits printed above)", flush=True)
    return 0


def no_context() -> int:
    code, _output = grep_scan(r"AzGuard\\Context|packages/context")
    if code != 1:
        print("context_scan=RED unexpected live references", flush=True)
        return 1
    print("context_scan=ABSENT observed_git_grep_exit=1", flush=True)
    return 0


def fixture(with_filament: bool) -> int:
    result = run(["bash", "bin/consumer-fixture.sh"] + (["--with-filament"] if with_filament else []), capture=True)
    if result.returncode == 3:
        print("fixture_status=unavailable acceptance_green=false (actual script exit 3)", flush=True)
        return 3
    if result.returncode != 0:
        print("fixture_status=failed acceptance_green=false", flush=True)
        return result.returncode if result.returncode > 0 else 1
    required = ["requiring from the artifact repository:", "discovered: AzGuard\\AzGuardServiceProvider"]
    if with_filament:
        required.append("discovered: AzGuard\\Filament\\AzGuardFilamentServiceProvider")
    if not all(marker in (result.stdout or "") for marker in required):
        raise RuntimeError("fixture exit 0 lacked artifact-install/provider-discovery evidence")
    print("fixture_status=available acceptance_green=true", flush=True)
    return 0


def optional_shellcheck() -> int:
    import shutil
    if shutil.which("shellcheck") is None:
        print("shellcheck=not-applicable executable_absent=true (declared 'при наличии')", flush=True)
        return 0
    return run(["shellcheck", "bin/consumer-fixture.sh"]).returncode


def clean_tree() -> int:
    result = run(["git", "status", "--porcelain=v1", "--untracked-files=all"], capture=True)
    require(result)
    if (result.stdout or "").strip():
        print("clean_tree=RED (must run in initially clean isolated checkout before evidence writes)", flush=True)
        return 1
    print("clean_tree=GREEN ignored build/ is omitted by git status", flush=True)
    return 0


def shared_suite() -> int:
    with tempfile.TemporaryDirectory(prefix="azguard-p0-suite-") as directory:
        report = Path(directory) / "junit.xml"
        require(run(["composer", "test", "--", "--log-junit", str(report)], test=True))
        junit = ET.parse(report)
        counts = {name: 0 for name in ("Arch", "Unit", "Feature", "Regression")}
        for case in junit.iter("testcase"):
            if case.find("skipped") is not None:
                continue
            identity = " ".join(str(case.get(key, "")) for key in ("class", "classname", "file", "name"))
            for name in counts:
                if re.search(r"(?:^|[/\\.])" + name + r"(?:[/\\.]|$)", identity):
                    counts[name] += 1
        print("observed_non_skipped_suite_counts=" + json.dumps(counts, sort_keys=True), flush=True)
        if not all(counts.values()):
            raise RuntimeError("JUnit did not prove >=1 executed non-skipped test in each declared suite")
        print(ET.tostring(junit.getroot(), encoding="unicode"), flush=True)
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("regression-negative", "lock-update", "old-names", "no-context",
                                         "fixture", "fixture-filament", "shellcheck", "clean-tree", "shared-suite"))
    args = parser.parse_args()
    if not (ROOT / "packages/core/composer.json").is_file() or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("run adapter from the prepared isolated AZGuard checkout")
    actions = {"regression-negative": regression_negative, "lock-update": lock_update,
               "old-names": old_names, "no-context": no_context, "fixture": lambda: fixture(False),
               "fixture-filament": lambda: fixture(True), "shellcheck": optional_shellcheck,
               "clean-tree": clean_tree, "shared-suite": shared_suite}
    return actions[args.action]()


if __name__ == "__main__":
    signal.signal(signal.SIGTERM, terminate)
    signal.signal(signal.SIGINT, terminate)
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, ValueError, ET.ParseError) as error:
        print("adapter_failure=" + str(error), file=sys.stderr, flush=True)
        raise SystemExit(1)
