#!/usr/bin/env python3
import argparse, json, sys

parser = argparse.ArgumentParser()
parser.add_argument("--surface", required=True)
args = parser.parse_args()
if args.surface != "context.observer":
    sys.exit(2)
try:
    payload = json.load(sys.stdin)
except (json.JSONDecodeError, TypeError):
    sys.exit(2)
required = {"session_id", "cwd", "hook_event_name", "model", "permission_mode", "source"}
if not required.issubset(payload) or payload["hook_event_name"] != "SessionStart":
    sys.exit(2)
print(json.dumps({"systemMessage": "[harness observer] read-only policy active"}))
