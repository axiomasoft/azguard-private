#!/usr/bin/env python3
"""Qualify the existing local AzGuard test DB after checking its effective target."""
import json
import os
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[3]
driver = sys.argv[1]
service, prefix, user_key, password_key = {
    "pgsql": ("postgres", "PGSQL", "POSTGRES_USER", "POSTGRES_PASSWORD"),
    "mysql": ("mysql", "MYSQL", "MYSQL_USER", "MYSQL_PASSWORD"),
    "mariadb": ("mariadb", "MARIADB", "MARIADB_USER", "MARIADB_PASSWORD"),
}[driver]
states = [json.loads(line) for line in subprocess.check_output(
    ["docker", "compose", "ps", "--format", "json", service], cwd=root, text=True).splitlines() if line]
assert len(states) == 1 and states[0]["State"] == "running" and states[0]["Health"] == "healthy", "Test service is not healthy"
container = json.loads(subprocess.check_output(["docker", "inspect", states[0]["ID"]], cwd=root))[0]
settings = dict(item.split("=", 1) for item in container["Config"]["Env"] if "=" in item)
publishers = [p for p in states[0]["Publishers"] if p["PublishedPort"]]
port = str(publishers[0]["PublishedPort"])
assert publishers[0]["URL"] == "127.0.0.1", "Expected local test service"
assert settings.get({"pgsql": "POSTGRES_DB", "mysql": "MYSQL_DATABASE", "mariadb": "MARIADB_DATABASE"}[driver]) == "azguard_test", "Container must be a dedicated AzGuard test stand"
env = os.environ.copy()
env.update({prefix + "_HOST": "127.0.0.1", prefix + "_PORT": port,
            prefix + "_DATABASE": "azguard_test", prefix + "_USERNAME": settings[user_key],
            prefix + "_PASSWORD": settings[password_key], "DB_CONNECTION": driver,
            "APP_ENV": "testing", "AUTHORITY_REPLICA_TEST": "", "TEST_TOKEN": ""})
probe = r'''
require 'vendor/autoload.php';
$app = (new AzGuard\Tests\TestCase('audit-preflight'))->createApplication();
$db = $app['db']->connection();
if ($app['config']->get('app.env') !== 'testing' || $db->getDatabaseName() !== 'azguard_test') {
    throw new RuntimeException('Test target isolation failed');
}
$db->select('SELECT 1');
echo json_encode(['driver'=>$db->getDriverName(), 'database'=>$db->getDatabaseName(), 'ready'=>true]).PHP_EOL;
'''
subprocess.run(["php", "-r", probe], cwd=root, env=env, check=True)
print("Using existing isolated azguard_test, host 127.0.0.1, port " + port, flush=True)
raise SystemExit(subprocess.run(["php", "bin/test-db.php", driver, *sys.argv[2:]], cwd=root, env=env).returncode)
