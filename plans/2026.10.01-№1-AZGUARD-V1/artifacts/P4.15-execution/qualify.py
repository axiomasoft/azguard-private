"""Run the item's scoped check and retain its actual output and exit status."""

from pathlib import Path
import hashlib
import json
import subprocess
import sys
import time
from datetime import datetime, timezone

repo = Path.cwd()
output = Path(__file__).resolve().parent
plan = output.parent.parent
check_id = sys.argv[1]
item = json.loads((plan / "phases/P4/P4.15.json").read_text())
check = next(row for row in item["validation"] if row["id"] == check_id)
files = sorted([*Path("tests/Fixtures/Crm").rglob("*.php"),
                *Path("tests/Acceptance/Crm").glob("*"),
                Path("tests/Pest.php"), Path("phpunit.xml"), Path("CHANGELOG.md")])
inventory = {str(p): hashlib.sha256(p.read_bytes()).hexdigest() for p in files if p.is_file()}
candidate = hashlib.sha256(json.dumps(inventory, sort_keys=True).encode()).hexdigest()
target = output / "final-001"
target.mkdir(exist_ok=True)
started = datetime.now(timezone.utc).isoformat()
begin = time.monotonic()
with (target / (check_id + ".log")).open("w") as log:
    result = subprocess.run(check["cmd"], shell=True, cwd=repo, stdout=log, stderr=subprocess.STDOUT)
receipt = {
    "check": check_id, "cmd": check["cmd"], "cwd": str(repo),
    "started_at": started, "duration_seconds": time.monotonic() - begin,
    "exit_code": result.returncode, "candidate": candidate, "files": inventory,
    "log": str(target / (check_id + ".log")),
}
(target / (check_id + ".json")).write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n")
print(json.dumps({k: v for k, v in receipt.items() if k != "files"}, ensure_ascii=False))
raise SystemExit(result.returncode)
