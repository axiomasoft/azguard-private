#!/usr/bin/env python3
"""Run a deliberately incomplete, local-only qualification against a clean SHA.

This is not a release qualification runner.  It never downloads dependencies,
invokes Docker, contacts a server, publishes artifacts, creates a tag or changes
the source checkout.  Missing matrix lanes remain explicit and force exit 1.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone


SCHEMA = "azguard.local-qualification-candidate.v1"
SHA = re.compile(r"[0-9a-f]{40}\Z")
MISSING = [
    "sqlite PHP/Laravel dependency matrix (10 rows)",
    "PostgreSQL/MySQL/MariaDB semantic matrix and engine races",
    "Redis cross-process and streaming replica",
    "Octane/RoadRunner and queue worker reuse",
    "extended/property/scale and fuzz lanes",
    "coverage and type coverage",
    "Pint, PHPStan and Rector",
    "workflow pin and security policy checks",
    "documentation build and language gates",
    "dependency, CodeQL, action and secret security scanners",
    "consumer and archive installation matrix",
    "upgrade, restore and operations drills",
    "full test suite and API compatibility",
]


def timestamp() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def command(argv: list[str], cwd: Path, env: dict[str, str], timeout: int = 120) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          timeout=timeout, check=False)


def safe_environment(home: Path) -> dict[str, str]:
    # Deliberately do not pass inherited DB, network credentials, Git config or
    # hooks; these checks do not require a database or network access.
    return {
        "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(home),
        "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8", "APP_ENV": "testing",
        "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:",
        "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null",
        "GIT_TERMINAL_PROMPT": "0", "GIT_CONFIG_COUNT": "2",
        "GIT_CONFIG_KEY_0": "core.hooksPath", "GIT_CONFIG_VALUE_0": "/dev/null",
        "GIT_CONFIG_KEY_1": "core.fsmonitor", "GIT_CONFIG_VALUE_1": "false",
    }


def checked(argv: list[str], cwd: Path, env: dict[str, str]) -> str:
    result = command(argv, cwd, env)
    if result.returncode:
        raise RuntimeError(f"{argv[0]} failed ({result.returncode}): {result.stderr.decode(errors='replace')[:400]}")
    return result.stdout.decode().strip()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--candidate", required=True, help="JSON candidate file, with schema and exact source_sha")
    parser.add_argument("--no-publish", required=True, action="store_true")
    parser.add_argument("--report", required=True, help="new JSON receipt path; never overwritten")
    parser.add_argument("--repository", default=str(Path(__file__).resolve().parent.parent), help="source checkout")
    args = parser.parse_args()

    repo = Path(args.repository).resolve(strict=True)
    candidate = json.loads(Path(args.candidate).read_text(encoding="utf-8"))
    if not isinstance(candidate, dict) or set(candidate) != {"schema", "source_sha"} or candidate["schema"] != SCHEMA:
        raise ValueError("candidate must contain exactly schema and source_sha")
    sha = candidate["source_sha"]
    if not isinstance(sha, str) or not SHA.fullmatch(sha):
        raise ValueError("candidate source_sha must be a full lowercase 40-character commit SHA")
    report = Path(args.report).absolute()
    parent = report.parent.resolve(strict=True)
    if report.name in ("", ".", "..") or report.is_symlink() or report.exists():
        raise ValueError("report must be a new regular file")
    artifacts = parent / (report.name + ".artifacts")
    if artifacts.exists() or artifacts.is_symlink():
        raise ValueError("receipt artifacts directory already exists")

    # A private work directory isolates clone, HOME, evidence logs and cleanup.
    # No external resources, credentials or untracked state are carried across.
    with tempfile.TemporaryDirectory(prefix="azguard-qualification-") as temp:
        work = Path(temp)
        env = safe_environment(work)
        root = Path(checked(["git", "rev-parse", "--show-toplevel"], repo, env)).resolve()
        if root != repo:
            raise ValueError("repository must be the Git checkout root")
        if checked(["git", "rev-parse", "HEAD^{commit}"], repo, env) != sha:
            raise ValueError("candidate does not match checkout HEAD")
        if checked(["git", "status", "--porcelain=v1", "--untracked-files=all"], repo, env):
            raise ValueError("source checkout is dirty")
        checkout = work / "checkout"
        checked(["git", "clone", "--quiet", "--local", "--no-hardlinks", "--no-checkout", "--", str(repo), str(checkout)], work, env)
        checked(["git", "-c", "core.hooksPath=/dev/null", "checkout", "--quiet", "--detach", sha], checkout, env)
        actual_sha = checked(["git", "rev-parse", "HEAD^{commit}"], checkout, env)
        if actual_sha != sha or checked(["git", "status", "--porcelain=v1", "--untracked-files=all"], checkout, env):
            raise RuntimeError("isolated checkout is not clean or does not match candidate")
        tree = checked(["git", "rev-parse", "HEAD^{tree}"], checkout, env)
        git_version = checked(["git", "--version"], checkout, env)
        try:
            php_version = checked(["php", "--version"], checkout, env).splitlines()[0]
        except (OSError, RuntimeError):
            php_version = "unavailable"
        lock = checkout / "composer.lock"
        if lock.is_symlink() or not lock.is_file():
            raise RuntimeError("composer.lock unavailable or not a regular file")
        lock_hash = digest(lock.read_bytes())
        checks = [
            ["php", "-l", "bin/release-gate.php"],
            ["php", "-l", "bin/verify-release-evidence.php"],
            ["php", "-l", "packages/core/src/AzGuard.php"],
        ]
        artifacts.mkdir(mode=0o700)
        results = []
        for index, argv in enumerate(checks, start=1):
            started = timestamp()
            try:
                source = checkout / argv[-1]
                if source.is_symlink() or not source.is_file() or not source.resolve().is_relative_to(checkout):
                    raise OSError("check target must be a regular file within the isolated checkout")
                result = command(argv, checkout, env)
                exit_code, output, error = result.returncode, result.stdout, result.stderr
            except (OSError, subprocess.TimeoutExpired) as exc:
                exit_code, output, error = 127, b"", str(exc).encode()
            log = output + b"\n--- stderr ---\n" + error
            log_name = f"check-{index}.log"
            (artifacts / log_name).write_bytes(log)
            results.append({"argv": argv, "cwd": "isolated-checkout", "started_at": started,
                            "finished_at": timestamp(), "exit": exit_code,
                            "status": "passed" if exit_code == 0 else "failed",
                            "log": f"{artifacts.name}/{log_name}", "sha256": digest(log)})
        final_sha = checked(["git", "rev-parse", "HEAD^{commit}"], checkout, env)
        if final_sha != sha or checked(["git", "status", "--porcelain=v1", "--untracked-files=all"], checkout, env):
            raise RuntimeError("a check changed the isolated checkout")
        if checked(["git", "rev-parse", "HEAD^{commit}"], repo, env) != sha or checked(["git", "status", "--porcelain=v1", "--untracked-files=all"], repo, env):
            raise RuntimeError("source checkout changed during qualification")
        receipt = {
            "schema": "azguard.local-qualification-receipt.v1", "qualification": "nonrelease",
            "result": "failed" if any(c["exit"] != 0 for c in results) else "incomplete",
            "source_sha": sha, "source_tree": tree, "composer_lock_sha256": lock_hash,
            "tools": {"git": git_version, "php": php_version, "python": sys.version.split()[0]},
            "clean_source": True, "isolated_checkout": True, "no_publish": True,
            "started_at": results[0]["started_at"], "finished_at": timestamp(),
            "checks": results, "required_lanes_unavailable": MISSING,
            "note": "Local source checks only. Not a full matrix, release qualification, attestation or publication proof.",
        }
        payload = (json.dumps(receipt, indent=2, ensure_ascii=False) + "\n").encode()
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(parent / report.name, flags, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(payload)
        print(f"{receipt['result']}: nonrelease local checks only; required matrix incomplete; receipt {parent / report.name}")
        return 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError, RuntimeError, subprocess.TimeoutExpired, json.JSONDecodeError) as exc:
        print(f"local qualification: {exc}", file=sys.stderr)
        sys.exit(2)
