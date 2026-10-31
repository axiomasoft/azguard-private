#!/usr/bin/env python3
"""Optional real PostgreSQL/Redis preflight proof in a fresh, isolated Compose project."""
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess

root = Path(__file__).resolve().parent.parent
os.chdir(root)
(root / 'build').mkdir(exist_ok=True)
report_path = root / 'build/qualification-p1-container.json'
sockets = [socket.socket() for _ in range(3)]
try:
    for sock in sockets:
        sock.bind(('127.0.0.1', 0))
    ports = [str(sock.getsockname()[1]) for sock in sockets]
finally:
    for sock in sockets:
        sock.close()
environment = dict(os.environ, COMPOSE_PROJECT_NAME='azguard-p1-' + secrets.token_hex(6),
                   AUTHORITY_PRIMARY_PORT=ports[0], AUTHORITY_REPLICA_PORT=ports[1], REDIS_PORT=ports[2],
                   APP_ENV='testing', DB_CONNECTION='pgsql', AUTHORITY_REPLICA_TEST='1', QUALIFICATION_REDIS='1')
for key in ('REDIS_PREFIX', 'TEST_TOKEN', 'DATABASE_URL', 'DB_URL', 'DB_HOST', 'DB_PORT', 'QUALIFICATION_SOURCE_SHA'):
    environment.pop(key, None)
command = ['docker', 'compose', '--project-directory', str(root), '-f', str(root / 'docker-compose.yml'),
           '--profile', 'authority-replica']


def probe():
    result = subprocess.run(['bin/qualification-preflight.sh', '--read-only', '--report=' + str(report_path)],
                            env=environment, text=True, capture_output=True, timeout=90)
    print(result.stdout, result.stderr, flush=True)
    return result, json.loads(report_path.read_text())


# Reuse the product's endpoint guard before creating any container.
_, report = probe()
assert report['checks']['docker.readiness']['status'] == 'passed', report['checks']['docker.readiness']
try:
    subprocess.run([*command, 'up', '-d', '--wait', '--wait-timeout', '120',
                    'authority-primary', 'authority-replica', 'redis'], env=environment, check=True, timeout=160)
    result, report = probe()
    for name in ('replica', 'redis.readiness', 'redis.isolation'):
        assert report['checks'][name]['status'] == 'passed', (name, report['checks'][name])
    # Fresh databases have no package migration ledger; topology alone must never qualify them.
    assert report['checks']['migrations']['status'] == 'unavailable', report['checks']['migrations']
    assert result.returncode == 1 and report['status'] == 'failed'
    print('Real same-cluster/upstream and owned Redis probes passed; unmigrated stand correctly fails.', flush=True)
finally:
    subprocess.run([*command, 'down', '--volumes', '--remove-orphans'], env=environment, check=True, timeout=60)
