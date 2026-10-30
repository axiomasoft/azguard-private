#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.." || exit 1
exec python3 - "$@" <<'PY'
import datetime, hashlib, json, os, re, shutil, subprocess, sys
import argparse
from pathlib import Path

root = Path.cwd()
parser = argparse.ArgumentParser(description='Inspect qualification prerequisites without running tests')
parser.add_argument('--read-only', action='store_true', help='inspect prerequisites without running tests')
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

rc, sha, _ = run('git', 'rev-parse', '--verify', 'HEAD^{commit}')
expected = env('QUALIFICATION_SOURCE_SHA', sha if rc == 0 else '')
check('source', 'passed' if rc == 0 and re.fullmatch('[0-9a-f]{40,64}', sha) and expected == sha else 'failed',
      {'head': sha if rc == 0 else None, 'expected': expected or None, 'owner': 'qualification source'})
rc, dirty, _ = run('git', 'status', '--porcelain', '--untracked-files=normal')
check('worktree', 'passed' if rc == 0 else 'unavailable', {'dirty': bool(dirty) if rc == 0 else None, 'owner': 'qualification source'})
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
for binary in ('php', 'php8.4'):
    code, output, _ = run(binary, '-r', 'echo json_encode(["extensions"=>get_loaded_extensions(),"pcov_enabled"=>ini_get("pcov.enabled"),"xdebug_mode"=>ini_get("xdebug.mode")]);')
    try: candidate = json.loads(output) if code == 0 else {}
    except ValueError: candidate = {}
    drivers = {x.lower() for x in candidate.get('extensions', [])}
    if ('pcov' in drivers and candidate.get('pcov_enabled') not in ('', '0', None)) or ('xdebug' in drivers and 'coverage' in (env('XDEBUG_MODE') or candidate.get('xdebug_mode') or '').split(',')):
        coverage_php = shutil.which(binary)
        break
check('coverage', 'passed' if coverage_php else 'failed', {'owner': 'coverage driver', 'php': coverage_php})
rc, _, _ = run('docker', 'info', '--format', '{{.ServerVersion}}')
check('docker.readiness', 'passed' if rc == 0 else 'unavailable', {'exit': rc, 'owner': 'local test stand'})

driver = env('DB_CONNECTION', 'sqlite')
app_env = env('APP_ENV', 'testing')
database = env('DB_DATABASE', ':memory:')
unsafe_aliases = [n for n in ('DATABASE_URL', 'DB_URL', 'DB_HOST', 'DB_PORT') if env(n)]
if env('TEST_TOKEN') and not env('TEST_TOKEN').isdigit(): unsafe_aliases.append('TEST_TOKEN')
safe_environment = app_env == 'testing' and not unsafe_aliases
check('environment', 'passed' if safe_environment else 'failed', {'app_env': app_env or None, 'unsupported_aliases': unsafe_aliases, 'owner': 'test target'})
driver_ext = {'sqlite': 'pdo_sqlite', 'pgsql': 'pdo_pgsql', 'mysql': 'pdo_mysql', 'mariadb': 'pdo_mysql'}
check('database.driver', 'passed' if driver in driver_ext and driver_ext[driver] in ext else 'failed', {'driver': driver or None, 'required_extension': driver_ext.get(driver), 'owner': 'database lane'})
if driver == 'sqlite':
    ok = database == ':memory:' and not env('AUTHORITY_REPLICA_TEST')
    check('database.target', 'passed' if ok else 'failed', {'driver': driver, 'database': database, 'owner': 'database lane'})
elif driver in ('pgsql', 'mysql', 'mariadb'):
    prefix = driver.upper()
    host, port = env(prefix + '_HOST', '127.0.0.1'), env(prefix + '_PORT', {'pgsql':'5432','mysql':'3306','mariadb':'3307'}[driver])
    db = env(prefix + '_DATABASE', 'azguard_test')
    syntactic = host == '127.0.0.1' and port.isdigit() and 1 <= int(port) <= 65535 and re.fullmatch(r'[A-Za-z0-9_]+_(test|bench)', db) is not None and not re.search(r'(^|_)(prod|production|live)(_|$)', db, re.I)
    # A local port can belong to a host database. Do not infer disposability from its name.
    check('database.target', 'unavailable' if syntactic else 'failed', {'host': host, 'port': port, 'database': db, 'owner': 'database lane', 'reason': 'server ownership, worker databases and migrations are not proven by this preflight'})
else:
    check('database.target', 'failed', {'owner': 'database lane', 'reason': 'unknown DB_CONNECTION'})
check('replica', 'failed' if env('AUTHORITY_REPLICA_TEST') else 'passed', {'owner': 'replica lane', 'reason': 'replica topology requires separate proof' if env('AUTHORITY_REPLICA_TEST') else 'not requested'})
prefix = env('REDIS_PREFIX')
redis_ok = not prefix or (prefix.startswith('azguard-qualification-') and len(prefix) > len('azguard-qualification-') and prefix.endswith(':'))
check('redis.prefix', 'passed' if redis_ok else 'failed', {'owner': 'redis lane', 'reason': 'Redis test uses its own random prefix; bench Redis is not authorized' if redis_ok else 'prefix collides with an unqualified keyspace'})
check('redis.readiness', 'unavailable' if prefix else 'passed', {'owner': 'redis lane', 'reason': 'a supplied prefix does not prove Redis keyspace isolation or readiness' if prefix else 'external Redis target not requested'})
check('migrations', 'passed' if (root / 'vendor/autoload.php').is_file() and any(root.glob('packages/**/database/migrations/*.php')) else 'unavailable', {'owner': 'database lane', 'reason': 'migration files and installed autoloader required'})
check('memory', 'unavailable', {'owner': 'orchestration', 'reason': 'project memory connection unavailable in this checkout'})
check('project_launcher', 'unavailable', {'owner': 'orchestration', 'reason': 'project launcher not present; installed router may be used'})
required = [k for k in checks if k not in ('memory', 'project_launcher')]
passed = all(checks[k]['status'] == 'passed' for k in required)
report = {'schema': 'azguard.qualification.preflight.v1', 'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'status': 'passed' if passed else 'failed', 'qualification': 'not performed', 'checks': checks}
path = root / args.report
try:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, sort_keys=True) + '\n')
except OSError as e:
    print('preflight: cannot write report:', e, file=sys.stderr)
    sys.exit(1)
for name, result in checks.items():
    if result['status'] != 'passed': print(f"{name}: {result['status']} ({result['detail']})", file=sys.stderr)
print(path)
sys.exit(0 if passed else 1)
PY
