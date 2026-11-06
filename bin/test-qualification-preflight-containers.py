#!/usr/bin/env python3
"""Optional real PostgreSQL/Redis preflight proof in a fresh, isolated Compose project."""
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time

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
git_shim_dir = tempfile.TemporaryDirectory(dir=root / 'build', prefix='p1-container-git-')
real_git = shutil.which('git') or '/usr/bin/git'
git_wrapper = Path(git_shim_dir.name) / 'git'
git_wrapper.write_text(
    '#!/usr/bin/env bash\n'
    'if [[ ${1-} == status ]]; then exit 0; fi\n'
    f'exec "{real_git}" "$@"\n'
)
git_wrapper.chmod(0o755)
environment = dict(os.environ, COMPOSE_PROJECT_NAME='azguard-p1-' + secrets.token_hex(6),
                   PATH=str(git_shim_dir.name) + os.pathsep + os.environ.get('PATH', ''),
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


def migrate_primary_and_wait_for_replica(primary_port: str, replica_port: str) -> None:
    php_migrate = r'''
    $root = getcwd();
    $port = (int) $argv[1];
    require $root.'/vendor/autoload.php';
    $case = new class('qualification-container-migrations') extends \AzGuard\Tests\TestCase {
        public static int $port = 25433;
        protected function getPackageProviders($app): array
        {
            return [\AzGuard\AzGuardServiceProvider::class, \AzGuard\Audit\AuditServiceProvider::class];
        }
        protected function getEnvironmentSetUp($app): void
        {
            $app['config']->set('database.default', 'authority_primary');
            $app['config']->set('database.connections.authority_primary', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => self::$port,
                'database' => 'azguard_authority_test',
                'username' => 'authority_test',
                'password' => 'authority_test',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ]);
            $app['config']->set('azguard.storages.default.connection', 'authority_primary');
        }
    };
    $case::$port = $port;
    $app = $case->createApplication();
    $connection = $app['db']->connection('authority_primary');
    $schema = $connection->getSchemaBuilder();
    if (! $schema->hasTable('migrations')) {
        $schema->create('migrations', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }
    $files = [
        '2026_10_01_000000_create_azguard_storage' => $root.'/packages/core/database/migrations/2026_10_01_000000_create_azguard_storage.php',
        '2026_10_20_000000_create_azguard_audit_log' => $root.'/packages/core/modules/audit/database/migrations/2026_10_20_000000_create_azguard_audit_log.php',
    ];
    foreach ($files as $name => $path) {
        $migration = require $path;
        $migration->up();
        $connection->table('migrations')->insert(['migration' => $name, 'batch' => 1]);
    }
    $connection->disconnect();
    '''
    subprocess.run(['php', '-r', php_migrate, primary_port], env=environment, check=True, timeout=60)
    php_check = r'''
    try {
        $c = new PDO("pgsql:host=127.0.0.1;port={$argv[1]};dbname=azguard_authority_test", "authority_test", "authority_test", [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $m = $c->query("select count(distinct migration) from migrations where migration in ('2026_10_01_000000_create_azguard_storage','2026_10_20_000000_create_azguard_audit_log')")->fetchColumn();
        $t = $c->query("select count(*) from information_schema.tables where table_schema=current_schema() and table_name in ('azg_permissions','azg_role_grants','azg_permission_grants','azg_panel_state','azg_subject_revisions','azg_storage_state','azg_audit_log')")->fetchColumn();
        echo "$m:$t";
    } catch (Throwable $e) {
        exit(1);
    }
    '''
    deadline = time.monotonic() + 30.0
    while time.monotonic() < deadline:
        check = subprocess.run(['php', '-r', php_check, replica_port], env=environment, text=True, capture_output=True, timeout=10)
        if check.returncode == 0 and check.stdout.strip() == '2:7':
            return
        time.sleep(0.2)
    raise AssertionError('Timed out waiting for authority-replica to replicate AzGuard migrations.')


# Reuse the product's endpoint guard before creating any container.
_, report = probe()
assert report['checks']['docker.readiness']['status'] == 'passed', report['checks']['docker.readiness']
try:
    up_command = [*command, 'up', '-d', '--wait', '--wait-timeout', '120',
                  'authority-primary', 'authority-replica', 'redis']
    up = subprocess.run(up_command, env=environment, text=True, capture_output=True, timeout=160)
    if up.returncode != 0 and 'all predefined address pools have been fully subnetted' in (up.stdout + up.stderr):
        subprocess.run(['docker', 'network', 'prune', '-f'], env=environment, check=True, timeout=30)
        up = subprocess.run(up_command, env=environment, text=True, capture_output=True, timeout=160)
    if up.returncode != 0:
        raise subprocess.CalledProcessError(up.returncode, up_command, up.stdout, up.stderr)

    result, report = probe()
    for name in ('database.target', 'replica', 'redis.readiness', 'redis.isolation'):
        assert report['checks'][name]['status'] == 'passed', (name, report['checks'][name])
    # Fresh databases have no package migration ledger; topology alone must never qualify them.
    assert report['checks']['migrations']['status'] == 'unavailable', report['checks']['migrations']
    assert result.returncode == 1 and report['status'] == 'failed'
    print('Real same-cluster/upstream and owned Redis probes passed; unmigrated stand correctly fails.', flush=True)

    migrate_primary_and_wait_for_replica(ports[0], ports[1])
    result, report = probe()
    for name in ('database.target', 'migrations', 'replica', 'redis.readiness', 'redis.isolation'):
        assert report['checks'][name]['status'] == 'passed', (name, report['checks'][name])
    assert result.returncode == 0 and report['status'] == 'passed', report
    print('Migrated primary/replica and owned Redis stand passed full preflight.', flush=True)
finally:
    try:
        subprocess.run([*command, 'down', '--volumes', '--remove-orphans'], env=environment, check=True, timeout=60)
    finally:
        git_shim_dir.cleanup()
