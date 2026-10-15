#!/usr/bin/env python3
"""Run one audit check from the repository root; retain output and observed status."""
import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

evidence = Path(__file__).resolve().parent
root = evidence.parents[2]
label, *command = sys.argv[1:]
if not re.fullmatch(r"[a-z0-9-]+", label) or not command:
    raise SystemExit("Usage: run-check.py <label> <command> [args...]")
env = os.environ.copy()
env.update(APP_ENV="testing", DB_CONNECTION="sqlite", DB_DATABASE=":memory:",
           CACHE_STORE="array", CACHE_DRIVER="array", SESSION_DRIVER="array",
           QUEUE_CONNECTION="sync", AUTHORITY_REPLICA_TEST="", NO_COLOR="1")
start = time.monotonic()
metadata = {
    "command": command,
    "cwd": str(root),
    "head": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip(),
    "started_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "test_environment": {k: env[k] for k in ["APP_ENV", "DB_CONNECTION", "DB_DATABASE", "AUTHORITY_REPLICA_TEST"]},
}
log = evidence / (label + ".log")
with log.open("w") as output:
    result = subprocess.run(command, cwd=root, env=env, stdout=output, stderr=subprocess.STDOUT)
metadata.update(exit_code=result.returncode, duration_seconds=round(time.monotonic() - start, 3), log=log.name)
(evidence / (label + ".json")).write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n")
print(json.dumps(metadata, ensure_ascii=False))
print("\n".join(log.read_text(errors="replace").splitlines()[-18:]))
raise SystemExit(result.returncode)
