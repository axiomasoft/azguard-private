"""Bounded checks and exact product snapshots; never an acceptance substitute."""
import datetime
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time

EVIDENCE = Path(__file__).resolve().parent
ROOT = EVIDENCE.parents[3]


def snapshot():
    names = subprocess.check_output(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"], cwd=ROOT
    ).decode().split("\0")
    files = {}
    for name in sorted(set(names)):
        if not name or name.startswith("plans/"):
            continue
        path = ROOT / name
        if path.is_file():
            files[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    digest = hashlib.sha256(json.dumps(files, sort_keys=True).encode()).hexdigest()
    target = EVIDENCE / ("snapshot-" + digest + ".json")
    if not target.exists():
        target.write_text(json.dumps({"sha256": digest, "files": files}, indent=2) + "\n")
    return digest


def main():
    if sys.argv[1] == "snapshot":
        print(snapshot())
        return
    name, *command = sys.argv[1:]
    if (EVIDENCE / (name + ".json")).exists():
        raise SystemExit("Each attempt requires a new evidence name")
    before = snapshot()
    env = dict(os.environ)
    env.pop("AUTHORITY_REPLICA_TEST", None)
    env.update(APP_ENV="testing", DB_CONNECTION="sqlite", DB_DATABASE=":memory:",
               CACHE_STORE="array", SESSION_DRIVER="array", QUEUE_CONNECTION="sync",
               XDEBUG_MODE="off")
    started = datetime.datetime.now(datetime.timezone.utc).isoformat()
    start = time.monotonic()
    with (EVIDENCE / (name + ".log")).open("w") as log:
        process = subprocess.Popen(command, cwd=ROOT, env=env, stdout=log,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        try:
            code = process.wait(timeout=30)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGTERM)
            try:
                process.wait(timeout=2)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
            code = 124
    result = {"command": command, "cwd": str(ROOT), "started_at": started,
              "seconds": round(time.monotonic() - start, 3), "exit_code": code,
              "timeout_seconds": 30, "product_sha256_before": before,
              "product_sha256_after": snapshot(),
              "head": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT).decode().strip(),
              "environment": {key: env[key] for key in
                  ("APP_ENV", "DB_CONNECTION", "DB_DATABASE", "CACHE_STORE",
                   "SESSION_DRIVER", "QUEUE_CONNECTION", "XDEBUG_MODE")},
              "AUTHORITY_REPLICA_TEST": "unset"}
    (EVIDENCE / (name + ".json")).write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result))
    tail = (EVIDENCE / (name + ".log")).read_text(errors="replace").splitlines()[-8:]
    print("\n".join(tail))
    raise SystemExit(code)


if __name__ == "__main__":
    main()
