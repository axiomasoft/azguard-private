"""Prove that the standalone benchmark exits 1 when its isolated DB is unavailable."""
import json
import os
import socket
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[4]
with socket.socket() as reserved:
    reserved.bind(('127.0.0.1', 0))  # Held, non-listening: no external DB can occupy this port.
    env = os.environ.copy()
    env.pop('AUTHORITY_REPLICA_TEST', None)
    env.update(DB_CONNECTION='pgsql', PGSQL_HOST='127.0.0.1',
               PGSQL_PORT=str(reserved.getsockname()[1]), PGSQL_DATABASE='azguard_test')
    result = subprocess.run(['php', 'tests/Benchmarks/AuthorizationLatency.php'], cwd=root,
                            env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
Path(__file__).with_name('benchmark-unavailable-fixed.log').write_text(result.stdout)
print(json.dumps({'expected_child_exit': 1, 'observed_child_exit': result.returncode,
                  'result': 'passed' if result.returncode == 1 else 'failed'}))
raise SystemExit(0 if result.returncode == 1 else 1)
