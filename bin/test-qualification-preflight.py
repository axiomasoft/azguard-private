#!/usr/bin/env python3
"""Isolated preflight regressions: never contact Docker, a database or Redis."""
import contextlib
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
CODE = (ROOT / 'bin/qualification-preflight.sh').read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]


class PreflightTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(dir=ROOT / 'build', prefix='p1-preflight-')
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.socket_path = self.root / 'docker.sock'
        sock = socket.socket(socket.AF_UNIX)
        sock.bind(str(self.socket_path))
        sock.close()
        self.defaults = {'APP_ENV': 'testing', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:'}
        self.write_defaults()
        (self.root / 'composer.lock').write_text('{}')
        self.report = self.root / 'report.json'
        self.calls = []
        self.dirty = ''
        self.endpoint = 'unix://' + str(self.socket_path)
        self.workdir = str(self.root)
        self.config_files = str(self.root / 'docker-compose.yml')
        self.config_hash = 'canonical'
        self.pgdata = '/var/lib/postgresql/data'
        self.mounts = None
        self.volume_project = 'fixture'
        self.cluster = '123456'
        self.upstream = 'authority-primary'
        self.streaming = 'streaming'
        self.migration_port = None
        self.pcov = '1'
        self.php_runtimes = {}

    def write_defaults(self):
        (self.root / 'phpunit.xml').write_text('<phpunit><php>' + ''.join(
            f'<env name="{k}" value="{v}"/>' for k, v in self.defaults.items()) + '</php></phpunit>')

    def run_command(self, argv, **kwargs):
        self.calls.append(argv)
        output, rc = 'fixture', 0
        if argv[0] == 'git':
            if argv[1] == 'rev-parse': output = 'a' * 40
            elif argv[1] == 'status': output = self.dirty
        elif argv[0] == 'docker':
            if argv[1] == 'context':
                output = json.dumps([{'Endpoints': {'docker': {'Host': self.endpoint}}}])
            elif argv[1] == 'compose':
                if 'config' in argv:
                    if argv[-1].startswith('authority-') and '--profile' not in argv:
                        output, rc = 'no such service', 1
                    else:
                        output = argv[-1] + ' canonical'
                else:
                    output = {'authority-primary': 'b' * 12, 'authority-replica': 'c' * 12}.get(argv[-1], 'd' * 12)
            elif argv[1] == 'volume':
                output = json.dumps([{'Labels': {'com.docker.compose.project': self.volume_project,
                                                'com.docker.compose.volume': argv[-1]}}])
            elif argv[1] == 'inspect':
                service = {'b' * 12: 'authority-primary', 'c' * 12: 'authority-replica'}.get(argv[-1],
                          'redis' if self.environment.get('QUALIFICATION_REDIS') == '1' else 'postgres')
                target, port = {'redis': ('6379', '26379'), 'authority-primary': ('5432', '25433'),
                                'authority-replica': ('5432', '25434')}.get(service, ('5432', '5432'))
                data = '/data' if service == 'redis' else '/var/lib/postgresql/data'
                volume = {'redis': 'redis_data', 'authority-primary': 'authority_primary_test',
                          'authority-replica': 'authority_replica_test'}.get(service)
                mounts = self.mounts if self.mounts is not None else ([
                    {'Destination': data, 'Type': 'volume', 'Name': volume}] if volume else [])
                output = json.dumps([{'Config': {'Env': ['PGDATA=' + self.pgdata], 'Labels': {
                    'com.docker.compose.service': service, 'com.docker.compose.project': 'fixture',
                    'com.docker.compose.project.working_dir': self.workdir,
                    'com.docker.compose.project.config_files': self.config_files,
                    'com.docker.compose.config-hash': self.config_hash}},
                    'State': {'Status': 'running', 'Health': {'Status': 'healthy'}},
                    'NetworkSettings': {'Ports': {target + '/tcp': [{'HostIp': '127.0.0.1', 'HostPort': port}]},
                                        'Networks': {'fixture': {'IPAddress': '172.18.0.2'}}},
                    'HostConfig': {'Tmpfs': {data: ''}}, 'Mounts': mounts}])
        elif argv[0] in ('php', 'php8.4'):
            code = argv[2] if len(argv) > 2 else ''
            if 'get_loaded_extensions' in code:
                output = json.dumps(self.php_runtimes.get(argv[0], {
                    'extensions': ['pdo_sqlite', 'pdo_pgsql', 'redis', 'pcov'], 'pcov_enabled': self.pcov}))
            elif 'pg_is_in_recovery' in code:
                standby = argv[-1] == '25434'
                output = ('azguard_authority_test:' + ('true' if standby else 'false')) if 'pg_control_system' not in code else json.dumps({'database': 'azguard_authority_test', 'recovery': standby,
                                     'cluster': self.cluster if standby else '123456',
                                     'receiver': [{'status': self.streaming, 'sender_host': self.upstream,
                                                   'sender_port': 5432}] if standby else []})
            elif 'information_schema.tables' in code:
                output = '0:0' if argv[4] == self.migration_port else '2:7'
            elif 'current_database' in code: output = argv[-1]
            elif 'new Redis()' in code: output = 'empty' if 'count($keys)' in code else 'PONG'
            elif len(argv) > 1 and argv[1].endswith('qualification-sqlite-migrations.php'):
                output = json.dumps({'status': 'passed', 'target': 'sqlite::memory:', 'migrations': ['a', 'b'], 'tables': list(range(7))})
        return subprocess.CompletedProcess(argv, rc, output, '')

    def execute(self, **environment):
        self.environment = environment
        with patch.dict(os.environ, environment, clear=True), patch('pathlib.Path.cwd', return_value=self.root), \
                patch('os.getcwd', return_value=str(self.root)), \
                patch('subprocess.run', side_effect=self.run_command), patch('shutil.which', return_value='/fixture/php'), \
                patch('sys.argv', ['preflight', '--read-only', '--report', str(self.report)]), \
                contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as result:
                exec(compile(CODE, 'qualification-preflight.sh', 'exec'), {})
        return result.exception.code, json.loads(self.report.read_text())

    def rejected_before_client(self, **env):
        exit_code, report = self.execute(**env)
        self.assertNotEqual(exit_code, 0)
        self.assertFalse(any(a[0] == 'php' and len(a) > 2 and ('new PDO' in a[2] or 'new Redis()' in a[2]) for a in self.calls))
        return report

    def test_clean_sqlite(self):
        self.assertEqual(self.execute()[0], 0)

    def test_dirty_tree_is_failed_with_paths(self):
        self.dirty = ' M composer.lock\n M bin/qualification-sqlite-migrations.php'
        code, report = self.execute()
        self.assertEqual(report['checks']['worktree']['status'], 'failed')
        self.assertIn('composer.lock', str(report['checks']['worktree']['detail']))
        self.assertNotEqual(code, 0)

    def test_remote_context_rejected_before_pdo(self):
        self.endpoint = 'ssh://remote'
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_remote_host_rejected_before_pdo(self):
        self.rejected_before_client(DB_CONNECTION='pgsql', DOCKER_HOST='tcp://remote:2376')

    def test_context_takes_precedence_over_host(self):
        self.endpoint = 'ssh://remote'
        self.rejected_before_client(DB_CONNECTION='pgsql', DOCKER_HOST=self.endpoint, DOCKER_CONTEXT='remote')

    def test_local_context_overrides_remote_host(self):
        self.assertEqual(self.execute(DB_CONNECTION='pgsql', DOCKER_HOST='ssh://remote', DOCKER_CONTEXT='local')[0], 0)

    def test_missing_workdir_rejected(self):
        self.workdir = ''
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_foreign_config_rejected(self):
        self.config_files += ',/tmp/override.yml'
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_changed_config_hash_rejected(self):
        self.config_hash = 'overridden'
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_pinned_compose_command(self):
        self.assertEqual(self.execute(DB_CONNECTION='pgsql', COMPOSE_FILE='/tmp/override.yml')[0], 0)
        commands = [a for a in self.calls if a[:2] == ('docker', 'compose')]
        self.assertTrue(commands)
        self.assertTrue(all(a[2:6] == ('--project-directory', str(self.root), '-f', str(self.root / 'docker-compose.yml')) for a in commands))

    def test_persistent_mount_masking_tmpfs_rejected(self):
        self.mounts = [{'Destination': self.pgdata, 'Type': 'bind'}]
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_nested_mount_masking_pgdata_rejected(self):
        self.pgdata += '/nested'
        self.mounts = [{'Destination': self.pgdata, 'Type': 'volume', 'Name': 'foreign'}]
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_pgdata_outside_tmpfs_rejected(self):
        self.pgdata = '/persistent'
        self.rejected_before_client(DB_CONNECTION='pgsql')

    def test_redis_owned_volume(self):
        self.assertEqual(self.execute(QUALIFICATION_REDIS='1')[0], 0)

    def test_redis_foreign_volume_rejected(self):
        self.volume_project = 'foreign'
        self.rejected_before_client(QUALIFICATION_REDIS='1')

    def test_redis_bind_rejected(self):
        self.mounts = [{'Destination': '/data', 'Type': 'bind'}]
        self.rejected_before_client(QUALIFICATION_REDIS='1')

    def test_redis_bad_prefix_never_pings(self):
        self.rejected_before_client(QUALIFICATION_REDIS='1', REDIS_PREFIX='production:')

    def test_replica_same_cluster(self):
        self.assertEqual(self.execute(DB_CONNECTION='pgsql', AUTHORITY_REPLICA_TEST='1')[0], 0)

    def test_replica_other_cluster(self):
        self.cluster = '999999'
        code, report = self.execute(DB_CONNECTION='pgsql', AUTHORITY_REPLICA_TEST='1')
        self.assertNotEqual(code, 0)
        self.assertEqual(report['checks']['replica']['status'], 'unavailable')

    def test_replica_other_upstream(self):
        self.upstream = 'another-primary'
        self.assertNotEqual(self.execute(DB_CONNECTION='pgsql', AUTHORITY_REPLICA_TEST='1')[0], 0)

    def test_replica_not_streaming(self):
        self.streaming = 'stopped'
        self.assertNotEqual(self.execute(DB_CONNECTION='pgsql', AUTHORITY_REPLICA_TEST='1')[0], 0)

    def test_standby_ledger_checked_separately(self):
        self.migration_port = '25434'
        code, report = self.execute(DB_CONNECTION='pgsql', AUTHORITY_REPLICA_TEST='1')
        self.assertNotEqual(code, 0)
        self.assertEqual(report['checks']['migrations']['status'], 'unavailable')

    def test_phpunit_target_drift_rejected(self):
        self.defaults['DB_DATABASE'] = '/tmp/disk.sqlite'
        self.write_defaults()
        self.assertNotEqual(self.execute()[0], 0)

    def test_invalid_lane_switch_rejected(self):
        self.rejected_before_client(QUALIFICATION_REDIS='true')

    def test_zero_replica_switch_is_off_like_php(self):
        self.assertEqual(self.execute(AUTHORITY_REPLICA_TEST='0')[0], 0)

    def test_pcov_off_is_not_coverage(self):
        self.pcov = 'Off'
        self.assertNotEqual(self.execute()[0], 0)

    def fallback_coverage(self, extensions):
        self.php_runtimes = {
            'php': {'extensions': ['pdo_sqlite', 'pdo_pgsql', 'redis'], 'pcov_enabled': '0'},
            'php8.4': {'extensions': extensions + ['pcov'], 'pcov_enabled': '1'},
        }

    def test_coverage_fallback_without_target_pdo_is_rejected(self):
        self.fallback_coverage(['pdo_sqlite', 'redis'])
        code, report = self.execute(DB_CONNECTION='pgsql')
        self.assertNotEqual(code, 0)
        self.assertEqual(report['checks']['database.driver']['status'], 'passed')
        self.assertEqual(report['checks']['database.target']['status'], 'passed')
        self.assertEqual(report['checks']['coverage']['status'], 'failed')
        self.assertEqual(report['checks']['coverage']['detail']['missing_extensions'], ['pdo_pgsql'])

    def test_coverage_fallback_without_redis_is_rejected(self):
        self.fallback_coverage(['pdo_sqlite'])
        code, report = self.execute(QUALIFICATION_REDIS='1')
        self.assertNotEqual(code, 0)
        self.assertEqual(report['checks']['redis.readiness']['status'], 'passed')
        self.assertEqual(report['checks']['coverage']['status'], 'failed')
        self.assertEqual(report['checks']['coverage']['detail']['missing_extensions'], ['redis'])

    def test_coverage_fallback_with_lane_extensions_is_accepted(self):
        self.fallback_coverage(['pdo_sqlite', 'pdo_pgsql', 'redis'])
        self.assertEqual(self.execute(DB_CONNECTION='pgsql')[0], 0)

    def test_atomic_replace_failure_preserves_previous_report(self):
        self.report.write_text('{"previous": true}\n')
        with patch('os.replace', side_effect=OSError('interrupted publication')):
            code, report = self.execute()
        self.assertNotEqual(code, 0)
        self.assertEqual(report, {'previous': True})
        self.assertEqual(list(self.root.glob('.report.json.*')), [])


if __name__ == '__main__':
    (ROOT / 'build').mkdir(exist_ok=True)
    unittest.main()
