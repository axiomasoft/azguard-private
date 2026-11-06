#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.." || exit 1
exec python3 - "$@" <<'PY'
import datetime, hashlib, json, os, re, secrets, shutil, subprocess, sys, tempfile
import argparse
from pathlib import Path
import xml.etree.ElementTree as ET

root = Path.cwd()
parser = argparse.ArgumentParser(description='Inspect qualification prerequisites without running tests')
parser.add_argument('--read-only', action='store_true', help='inspect without qualification tests; applies SQLite migrations in memory and opens read-only server/Redis probes (also the default)')
parser.add_argument('--report', default='build/qualification/preflight.json', help='output JSON report path')
args = parser.parse_args()
checks = {}
def check(name, status, detail):
    checks[name] = {'status': status, 'detail': detail}
def run(*args):
    try:
        p = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        return p.returncode, p.stdout.strip(), p.stderr.strip()
    except (OSError, subprocess.TimeoutExpired) as e:
        return 127, '', str(e)
def env(name, default=''):
    return os.environ.get(name, default)

compose = ('docker', 'compose', '--project-directory', str(root), '-f', str(root / 'docker-compose.yml'), '--profile', 'authority-replica')
containers_by_id = {}

def local_docker():
    # Resolve the same endpoint precedence as Docker before trusting daemon loopback bindings.
    if env('DOCKER_CONTEXT') or not env('DOCKER_HOST'):
        rc, output, _ = run('docker', 'context', 'inspect', env('DOCKER_CONTEXT')) if env('DOCKER_CONTEXT') else run('docker', 'context', 'inspect')
        try:
            endpoint = json.loads(output)[0]['Endpoints']['docker']['Host'] if rc == 0 else ''
        except (ValueError, KeyError, TypeError, IndexError):
            endpoint = ''
    else:
        endpoint = env('DOCKER_HOST')
    local = endpoint.startswith('unix:///') and Path(endpoint[len('unix://'):]).is_socket()
    return local, {'endpoint': endpoint, 'reason': 'local Unix socket required for client loopback probes'}

def compose_container(service, port, target_port, tmpfs=None, volume=None):
    """Prove that the tested loopback port belongs to this checkout's healthy compose stand."""
    if checks['docker.readiness']['status'] != 'passed':
        return None, 'local Docker endpoint not proven'
    rc, cid, _ = run(*compose, 'ps', '-q', service)
    if rc or not re.fullmatch(r'[0-9a-f]{12,64}', cid):
        return None, 'compose service absent or ambiguous'
    rc, output, _ = run('docker', 'inspect', cid)
    try:
        containers = json.loads(output)
        container = containers[0] if rc == 0 and len(containers) == 1 else {}
        labels = container['Config']['Labels']
        ports = container['NetworkSettings']['Ports'][f'{target_port}/tcp']
        healthy = container['State']['Status'] == 'running' and container['State'].get('Health', {}).get('Status') == 'healthy'
        workdir = labels.get('com.docker.compose.project.working_dir')
        config_files = labels.get('com.docker.compose.project.config_files', '').split(',')
        hrc, config_hash, _ = run(*compose, 'config', '--hash', service)
        expected_hash = config_hash.split()[-1] if hrc == 0 and config_hash.split() else None
        owned = (labels.get('com.docker.compose.service') == service and bool(workdir)
                 and Path(workdir).resolve() == root.resolve()
                 and config_files == [str(root / 'docker-compose.yml')]
                 and expected_hash is not None and labels.get('com.docker.compose.config-hash') == expected_hash
                 and bool(labels.get('com.docker.compose.project')))
        bound = len(ports) == 1 and ports[0]['HostIp'] == '127.0.0.1' and ports[0]['HostPort'] == str(port)
        disposable = tmpfs is None or tmpfs in (container['HostConfig'].get('Tmpfs') or {})
        data_path = tmpfs or ('/data' if service == 'redis' else '/var/lib/postgresql/data')
        # A nested bind/volume can mask tmpfs, including a nested PGDATA directory.
        mounts = [m for m in container.get('Mounts', [])
                  if Path(m['Destination']) == Path(data_path)
                  or Path(data_path) in Path(m['Destination']).parents
                  or Path(m['Destination']) in Path(data_path).parents]
        if tmpfs:
            disposable = disposable and all(m.get('Type') == 'tmpfs' and m['Destination'] == data_path for m in mounts)
        if target_port == '5432':
            pgdata = [v.split('=', 1)[1] for v in container['Config'].get('Env', []) if v.startswith('PGDATA=')]
            disposable = disposable and len(pgdata) == 1 and (Path(pgdata[0]) == Path(data_path) or Path(data_path) in Path(pgdata[0]).parents) and '..' not in Path(pgdata[0]).parts
        if volume:
            if len(mounts) != 1 or mounts[0].get('Destination') != data_path or mounts[0].get('Type') != 'volume':
                return None, 'data volume is not owned or is masked by another mount'
            vrc, vout, _ = run('docker', 'volume', 'inspect', mounts[0]['Name'])
            volumes = json.loads(vout) if vrc == 0 else []
            vl = volumes[0].get('Labels', {}) if len(volumes) == 1 else {}
            disposable = disposable and vl.get('com.docker.compose.volume') == volume and vl.get('com.docker.compose.project') == labels.get('com.docker.compose.project')
        if not (healthy and owned and bound and disposable):
            return None, 'service ownership, health, loopback port or disposable storage not proven'
    except (ValueError, KeyError, TypeError, IndexError):
        return None, 'cannot inspect service ownership or port'
    containers_by_id[cid] = container
    checks['docker.readiness']['detail'].setdefault('services', {})[service] = {
        'container': cid, 'project': labels['com.docker.compose.project'],
        'config_files': config_files, 'config_hash': expected_hash,
    }
    return cid, 'healthy owned compose service with matching canonical configuration'

def server_probe(driver, host, port, database):
    if host != '127.0.0.1' or not port.isdigit() or not 1 <= int(port) <= 65535:
        return 'failed', 'server must use an explicit loopback port'
    if not re.fullmatch(r'[a-z0-9_]+_test', database) or re.search(r'(^|_)(prod|production|live)(_|$)', database, re.I):
        return 'failed', 'database must be a non-production *_test name'
    cid, reason = compose_container(driver if driver != 'pgsql' else 'postgres', port,
                                    '5432' if driver == 'pgsql' else '3306',
                                    '/var/lib/postgresql/data' if driver == 'pgsql' else '/var/lib/mysql')
    if not cid:
        return 'unavailable', reason
    # Probe the same host endpoint, credentials and exact worker database used by TestCase.
    # The password is read by PHP from its environment, never passed as argv or logged.
    code = '''$p=strtoupper($argv[1]); $d=$argv[1]==="pgsql"?"pgsql":"mysql";
      $u=getenv($p."_USERNAME")?:"azguard"; $w=getenv($p."_PASSWORD")?:"azguard";
      try { $c=new PDO("$d:host=127.0.0.1;port=$argv[2];dbname=$argv[3]",$u,$w,[PDO::ATTR_TIMEOUT=>3,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        echo $c->query($d==="pgsql"?"select current_database()":"select database()")->fetchColumn(); }
      catch (Throwable $e) { exit(1); }'''
    rc, output, _ = run('php', '-r', code, driver, port, database)
    return ('passed', 'owned disposable service and exact database reachable') if rc == 0 and output == database else ('unavailable', 'exact database is not reachable with the configured test user')

rc, sha, _ = run('git', 'rev-parse', '--verify', 'HEAD^{commit}')
expected = env('QUALIFICATION_SOURCE_SHA', sha if rc == 0 else '')
check('source', 'passed' if rc == 0 and re.fullmatch('[0-9a-f]{40,64}', sha) and expected == sha else 'failed',
      {'head': sha if rc == 0 else None, 'expected': expected or None, 'owner': 'qualification source'})
rc, dirty, _ = run('git', 'status', '--porcelain', '--untracked-files=normal')
check('worktree', ('failed' if dirty else 'passed') if rc == 0 else 'unavailable', {'dirty': bool(dirty) if rc == 0 else None, 'changes': dirty.splitlines() if rc == 0 else None, 'owner': 'qualification source'})
lock = root / 'composer.lock'
rc, _, _ = run('git', 'ls-files', '--error-unmatch', 'composer.lock')
lock_hash = hashlib.sha256(lock.read_bytes()).hexdigest() if lock.is_file() else None
rc2, _, _ = run('composer', 'validate', '--no-check-publish', '--no-interaction')
check('lock', 'passed' if rc == 0 and lock_hash and rc2 == 0 else 'failed', {'sha256': lock_hash, 'tracked': rc == 0, 'composer_validate_exit': rc2, 'owner': 'development lock'})

for name, cmd in {'php': ['php', '--version'], 'composer': ['composer', '--version'], 'node': ['node', '--version'], 'npm': ['npm', '--version'], 'actionlint': ['actionlint', '-version'], 'zizmor': ['zizmor', '--version'], 'gh': ['gh', '--version'], 'docker': ['docker', '--version']}.items():
    rc, out, err = run(*cmd)
    check('tool.' + name, 'passed' if rc == 0 else 'unavailable', {'version': out.splitlines()[0] if out else None, 'exit': rc, 'owner': 'qualification tooling'})
rc, out, _ = run('php', '-r', 'echo json_encode(["extensions"=>get_loaded_extensions(),"pcov_enabled"=>ini_get("pcov.enabled"),"xdebug_mode"=>ini_get("xdebug.mode"),"opcache_cli"=>ini_get("opcache.enable_cli"),"jit"=>ini_get("opcache.jit"),"jit_buffer"=>ini_get("opcache.jit_buffer_size")]);')
try: php = json.loads(out) if rc == 0 else {}
except ValueError: php = {}
ext = {x.lower() for x in php.get('extensions', [])}
check('php.runtime', 'passed' if php else 'unavailable', php)
coverage_php = None
coverage_extensions = set()
for binary in ('php', 'php8.4'):
    code, output, _ = run(binary, '-r', 'echo json_encode(["extensions"=>get_loaded_extensions(),"pcov_enabled"=>ini_get("pcov.enabled"),"xdebug_mode"=>ini_get("xdebug.mode")]);')
    try: candidate = json.loads(output) if code == 0 else {}
    except ValueError: candidate = {}
    drivers = {x.lower() for x in candidate.get('extensions', [])}
    if ('pcov' in drivers and str(candidate.get('pcov_enabled')).lower() in ('1', 'on', 'yes', 'true')) or ('xdebug' in drivers and 'coverage' in (env('XDEBUG_MODE') or candidate.get('xdebug_mode') or '').split(',')):
        coverage_php = shutil.which(binary)
        coverage_extensions = drivers
        break
local, docker_detail = local_docker()
rc, _, _ = run('docker', 'info', '--format', '{{.ServerVersion}}') if local else (1, '', '')
check('docker.readiness', 'passed' if rc == 0 else 'unavailable', dict(docker_detail, exit=rc, owner='local test stand'))

try:
    defaults = {e.attrib['name']: e.attrib['value'] for e in ET.parse(root / 'phpunit.xml').findall('./php/env')}
    defaults_ok = all(defaults.get(k) == v for k, v in {'APP_ENV': 'testing', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:'}.items())
except (OSError, ET.ParseError, KeyError):
    defaults, defaults_ok = {}, False
driver = env('DB_CONNECTION', defaults.get('DB_CONNECTION', ''))
app_env = env('APP_ENV', defaults.get('APP_ENV', ''))
database = env('DB_DATABASE', defaults.get('DB_DATABASE', ''))
unsafe_aliases = [n for n in ('DATABASE_URL', 'DB_URL', 'DB_HOST', 'DB_PORT') if env(n)]
if env('TEST_TOKEN') and not env('TEST_TOKEN').isdigit(): unsafe_aliases.append('TEST_TOKEN')
for switch in ('QUALIFICATION_REDIS', 'AUTHORITY_REPLICA_TEST'):
    if env(switch) not in ('', '0', '1'): unsafe_aliases.append(switch)
replica_requested = env('AUTHORITY_REPLICA_TEST') == '1'
safe_environment = app_env == 'testing' and not unsafe_aliases and defaults_ok
check('environment', 'passed' if safe_environment else 'failed', {'app_env': app_env or None, 'unsupported_aliases': unsafe_aliases, 'owner': 'test target'})
driver_ext = {'sqlite': 'pdo_sqlite', 'pgsql': 'pdo_pgsql', 'mysql': 'pdo_mysql', 'mariadb': 'pdo_mysql'}
check('database.driver', 'passed' if driver in driver_ext and driver_ext[driver] in ext else 'failed', {'driver': driver or None, 'required_extension': driver_ext.get(driver), 'owner': 'database lane'})
# Coverage can fall back to another PHP executable. That runtime must also be
# able to run the requested target lane, independently of the PATH PHP probes.
coverage_required = {driver_ext[driver]} if driver in driver_ext else set()
if env('REDIS_PREFIX') or env('QUALIFICATION_REDIS') == '1':
    coverage_required.add('redis')
coverage_missing = sorted(coverage_required - coverage_extensions)
check('coverage', 'passed' if coverage_php and not coverage_missing else 'failed',
      {'owner': 'coverage driver', 'php': coverage_php, 'missing_extensions': coverage_missing})
if driver == 'sqlite':
    ok = database == ':memory:' and not replica_requested and safe_environment
    check('database.target', 'passed' if ok else 'failed', {'driver': driver, 'database': database, 'owner': 'database lane'})
elif driver in ('pgsql', 'mysql', 'mariadb'):
    prefix = driver.upper()
    host, port = env(prefix + '_HOST', '127.0.0.1'), env(prefix + '_PORT', {'pgsql':'5432','mysql':'3306','mariadb':'3307'}[driver])
    db = env(prefix + '_DATABASE', 'azguard_test')
    token = env('TEST_TOKEN')
    if token and db.endswith('_test'):
        db = db[:-5] + '_' + token + '_test'
    status, reason = server_probe(driver, host, port, db) if safe_environment else ('failed', 'unsafe environment aliases')
    check('database.target', status, {'host': host, 'port': port, 'database': db, 'owner': 'database lane', 'reason': reason})
else:
    check('database.target', 'failed', {'owner': 'database lane', 'reason': 'unknown DB_CONNECTION'})
if replica_requested:
    ports = [env('AUTHORITY_PRIMARY_PORT', '25433'), env('AUTHORITY_REPLICA_PORT', '25434')]
    reason = 'replica requires two distinct loopback compose ports and PostgreSQL'
    status = 'failed'
    if driver == 'pgsql' and safe_environment and not env('TEST_TOKEN') and all(p.isdigit() and 1 <= int(p) <= 65535 for p in ports) and ports[0] != ports[1]:
        nodes = [compose_container('authority-primary', ports[0], '5432', volume='authority_primary_test'),
                 compose_container('authority-replica', ports[1], '5432', volume='authority_replica_test')]
        if all(cid for cid, _ in nodes) and nodes[0][0] != nodes[1][0]:
            code = '''try { $c=new PDO("pgsql:host=127.0.0.1;port=$argv[1];dbname=azguard_authority_test","authority_test","authority_test",[PDO::ATTR_TIMEOUT=>3,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
              $s=$c->query("select current_database() as database, pg_is_in_recovery() as recovery, system_identifier::text as cluster from pg_control_system()")->fetch(PDO::FETCH_ASSOC);
              $s["receiver"]=$c->query("select status, sender_host, sender_port from pg_stat_wal_receiver")->fetchAll(PDO::FETCH_ASSOC);
              echo json_encode($s,JSON_THROW_ON_ERROR); } catch (Throwable $e) { exit(1); }'''
            probes = [run('php', '-r', code, port) for port in ports]
            try:
                primary, standby = [json.loads(out) if rc == 0 else {} for rc, out, _ in probes]
                addresses = [n.get('IPAddress') for n in containers_by_id[nodes[0][0]]['NetworkSettings']['Networks'].values()]
                receivers = standby.get('receiver', [])
                paired = (primary.get('database') == standby.get('database') == 'azguard_authority_test'
                          and primary.get('recovery') is False and standby.get('recovery') is True
                          and re.fullmatch(r'[0-9]+', primary.get('cluster', '')) is not None
                          and primary.get('cluster') == standby.get('cluster') and len(receivers) == 1
                          and receivers[0].get('status') == 'streaming'
                          and receivers[0].get('sender_host') in ['authority-primary', *addresses]
                          and str(receivers[0].get('sender_port')) == '5432')
            except (ValueError, KeyError, TypeError):
                paired = False
            status = 'passed' if paired else 'unavailable'
            reason = 'same cluster and streaming primary/standby topology proven' if paired else 'same-cluster upstream topology or credentials not proven'
        else:
            status, reason = 'unavailable', 'owned healthy primary and replica volumes and ports not proven'
    check('replica', status, {'owner': 'replica lane', 'ports': ports, 'reason': reason})
    # Replica tests select the primary independently of PGSQL_* and do not use TEST_TOKEN.
    if driver == 'pgsql':
        check('database.target', status, {'host': '127.0.0.1', 'port': ports[0], 'database': 'azguard_authority_test', 'owner': 'database lane', 'reason': reason})
else:
    check('replica', 'passed', {'owner': 'replica lane', 'reason': 'not requested'})
prefix = env('REDIS_PREFIX')
redis_ok = not prefix or re.fullmatch(r'azguard-qualification-[0-9a-f]{24}:', prefix) is not None
check('redis.prefix', 'passed' if redis_ok else 'failed', {'owner': 'redis lane', 'reason': 'Redis test uses its own random prefix; bench Redis is not authorized' if redis_ok else 'prefix collides with an unqualified keyspace'})
redis_requested = bool(prefix) or env('QUALIFICATION_REDIS') == '1'
if redis_requested:
    port = env('REDIS_PORT', '26379')
    cid, reason = compose_container('redis', port, '6379', volume='redis_data') if redis_ok and safe_environment and port.isdigit() and 1 <= int(port) <= 65535 else (None, 'invalid Redis prefix, environment or port')
    status = 'unavailable'
    if cid and 'redis' in ext:
        code = '''try { $r=new Redis(); if (!$r->connect("127.0.0.1",(int)$argv[1],3) || !$r->select(15)) exit(1);
          $p=$r->ping(); if ($p!==true && $p!=="PONG" && $p!=="+PONG") exit(1); echo "PONG"; } catch (Throwable $e) { exit(1); }'''
        rc, output, _ = run('php', '-r', code, port)
        status = 'passed' if rc == 0 and output == 'PONG' else 'unavailable'
        reason = 'owned Redis DB 15 responded to PING' if status == 'passed' else 'Redis DB 15 did not respond using the test client'
    elif cid:
        reason = 'PHP redis extension is unavailable'
    check('redis.readiness', status, {'owner': 'redis lane', 'port': port, 'database': 15, 'reason': reason})
    if status == 'passed' and redis_ok:
        probe_prefix = prefix or 'azguard-qualification-' + secrets.token_hex(12) + ':'
        code = '''try { $r=new Redis(); if (!$r->connect("127.0.0.1",(int)$argv[1],3) || !$r->select(15)) exit(1);
          $keys=$r->keys($argv[2]."*"); if (!is_array($keys)) exit(1); echo count($keys)===0?"empty":"collision"; } catch (Throwable $e) { exit(1); }'''
        rc, output, _ = run('php', '-r', code, port, probe_prefix)
        isolation = 'passed' if rc == 0 and output == 'empty' else ('failed' if rc == 0 and output == 'collision' else 'unavailable')
        reason = 'checked namespace is empty; test checks its own random namespace at runtime' if isolation == 'passed' else 'namespace collision or isolation probe unavailable'
    else:
        isolation, reason = ('failed', 'invalid qualification prefix') if not redis_ok else ('unavailable', 'Redis readiness not proven')
    check('redis.isolation', isolation, {'owner': 'redis lane', 'reason': reason})
else:
    check('redis.readiness', 'passed', {'owner': 'redis lane', 'reason': 'external Redis target not requested'})
    check('redis.isolation', 'passed', {'owner': 'redis lane', 'reason': 'external Redis target not requested'})
if driver == 'sqlite' and checks['database.target']['status'] == 'passed' and safe_environment:
    rc, output, _ = run('php', 'bin/qualification-sqlite-migrations.php')
    try:
        migration_probe = json.loads(output) if rc == 0 else {}
    except ValueError:
        migration_probe = {}
    verified = (migration_probe.get('status') == 'passed' and migration_probe.get('target') == 'sqlite::memory:'
                and len(migration_probe.get('migrations', [])) == 2 and len(migration_probe.get('tables', [])) == 7)
    check('migrations', 'passed' if verified else 'failed', {'owner': 'database lane', 'reason': 'isolated SQLite migrations executed' if verified else 'isolated SQLite migration probe failed', 'probe': migration_probe})
elif driver in ('pgsql', 'mysql', 'mariadb') and checks['database.target']['status'] == 'passed':
    # Read the migration ledger and schema on the exact already-proven test target.
    # No migration or other write is performed against a server.
    target = checks['database.target']['detail']
    code = '''$p=strtoupper($argv[1]); $d=$argv[1]==="pgsql"?"pgsql":"mysql";
      $u=$argv[4]==="replica"?"authority_test":(getenv($p."_USERNAME")?:"azguard");
      $w=$argv[4]==="replica"?"authority_test":(getenv($p."_PASSWORD")?:"azguard");
      try { $c=new PDO("$d:host=127.0.0.1;port=$argv[2];dbname=$argv[3]",$u,$w,[PDO::ATTR_TIMEOUT=>3,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $m=$c->query("select count(distinct migration) from migrations where migration in ('2026_10_01_000000_create_azguard_storage','2026_10_20_000000_create_azguard_audit_log')")->fetchColumn();
        $schema=$d==="pgsql"?"current_schema()":"database()";
        $t=$c->query("select count(*) from information_schema.tables where table_schema=$schema and table_name in ('azg_permissions','azg_role_grants','azg_permission_grants','azg_panel_state','azg_subject_revisions','azg_storage_state','azg_audit_log')")->fetchColumn(); echo "$m:$t";
      } catch (Throwable $e) { exit(1); }'''
    rc, output, _ = run('php', '-r', code, driver, target['port'], target['database'], 'replica' if replica_requested else 'server')
    verified = rc == 0 and output == '2:7'
    if replica_requested and verified:
        replica_rc, replica_output, _ = run('php', '-r', code, driver, ports[1], target['database'], 'replica')
        verified = replica_rc == 0 and replica_output == '2:7'
    check('migrations', 'passed' if verified else 'unavailable', {'owner': 'database lane', 'reason': 'migration ledger and required tables proven on the selected server target(s)' if verified else 'migration ledger or required tables not proven on every selected server target'})
else:
    check('migrations', 'unavailable', {'owner': 'database lane', 'reason': 'migration readiness has not been proven on this server target'})
check('memory', 'unavailable', {'owner': 'orchestration', 'reason': 'project memory connection unavailable in this checkout'})
check('project_launcher', 'unavailable', {'owner': 'orchestration', 'reason': 'project launcher not present; installed router may be used'})
required = [k for k in checks if k not in ('memory', 'project_launcher')]
passed = all(checks[k]['status'] == 'passed' for k in required)
report = {'schema': 'azguard.qualification.preflight.v1', 'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'status': 'passed' if passed else 'failed', 'qualification': 'not performed', 'checks': checks}
path = root / args.report
try:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, prefix='.' + path.name + '.', delete=False) as output:
            temporary = Path(output.name)
            output.write(json.dumps(report, indent=2, sort_keys=True) + '\n')
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
except OSError as e:
    print('preflight: cannot write report:', e, file=sys.stderr)
    sys.exit(1)
for name, result in checks.items():
    if result['status'] != 'passed': print(f"{name}: {result['status']} ({result['detail']})", file=sys.stderr)
print(path)
sys.exit(0 if passed else 1)
PY
