#!/usr/bin/env python3
"""Exercise the isolated migration probe, including unavailable migrations."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
probe = root / 'bin/qualification-sqlite-migrations.php'
env = dict(os.environ, APP_ENV='production', DB_CONNECTION='pgsql', DB_DATABASE='production', AZGUARD_DB_CONNECTION='production')
run = subprocess.run(['php', str(probe)], env=env, text=True, capture_output=True, check=True)
result = json.loads(run.stdout)
assert result['status'] == 'passed' and result['target'] == 'sqlite::memory:'
assert len(result['migrations']) == 2 and len(result['tables']) == 7

with tempfile.TemporaryDirectory(prefix='azguard-sqlite-probe-') as directory:
    temporary = Path(directory)
    missing = subprocess.run(['php', str(probe), '--core-migration=' + str(temporary / 'missing.php')], text=True, capture_output=True)
    assert missing.returncode != 0 and 'migration path is unavailable' in missing.stderr

    checkout = temporary / 'checkout'
    (checkout / 'bin').mkdir(parents=True)
    migrations = checkout / 'packages/core/database/migrations'
    migrations.mkdir(parents=True)
    shutil.copy2(probe, checkout / 'bin' / probe.name)
    shutil.copy2(root / 'packages/core/database/migrations/2026_10_01_000000_create_azguard_storage.php', migrations)
    missing_audit = subprocess.run(['php', str(checkout / 'bin' / probe.name)], text=True, capture_output=True)
    assert missing_audit.returncode != 0 and 'Migration unavailable' in missing_audit.stderr

    # A present migration that throws is not migration-readiness evidence.
    audit = checkout / 'packages/core/modules/audit/database/migrations'
    audit.mkdir(parents=True)
    shutil.copy2(root / 'packages/core/modules/audit/database/migrations/2026_10_20_000000_create_azguard_audit_log.php', audit)
    (checkout / 'vendor').symlink_to(root / 'vendor', target_is_directory=True)
    (migrations / '2026_10_01_000000_create_azguard_storage.php').write_text(
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration {'
        'public function up(): void { throw new \\RuntimeException("broken migration fixture"); }};'
    )
    broken = subprocess.run(['php', str(checkout / 'bin' / probe.name)], text=True, capture_output=True)
    assert broken.returncode != 0 and 'broken migration fixture' in broken.stderr, broken.stderr

print('SQLite migration probe: positive and missing-migration cases passed')
