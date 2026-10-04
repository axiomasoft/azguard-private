#!/usr/bin/env python3
"""Serial validation logs, each bound to its complete product candidate."""
import datetime, fcntl, hashlib, json, os, pathlib, subprocess, sys, time
root = pathlib.Path.cwd()
out = pathlib.Path(__file__).resolve().parent
lock = (out / '.validation.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
sys.path[:0] = ['/home/vostrikov/projects/packages/swissknifeman', '/home/vostrikov/projects/packages/swissknifeman/packages/task/lib']
from task import task_contract

def candidate():
    raw = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'])
    paths = sorted(set(x.decode() for x in raw.split(b'\0') if x))
    rows = {}
    for name in paths:
        if name.startswith(('packages/', 'tests/', 'bin/')) or name in ('composer.json', 'composer.lock', 'phpunit.xml', 'phpstan.neon', 'pint.json', 'docker-compose.yml', 'CHANGELOG.md'):
            p = root / name
            rows[name] = hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None
    digest = hashlib.sha256(json.dumps(rows, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    return digest, rows

slug, command = sys.argv[1:]
seq = 1
while (out / f'{seq:03d}-{slug}.json').exists() or list(out.glob(f'{seq:03d}-*.json')):
    seq += 1
prefix = out / f'{seq:03d}-{slug}'
before, rows = candidate()
validation_context = task_contract.current_validation_context(out.parent.parent, 'P4.4')
start = datetime.datetime.now(datetime.timezone.utc).isoformat()
t0 = time.monotonic()
with prefix.with_suffix('.log').open('wb') as log:
    result = subprocess.run(['bash', '-c', command], stdout=log, stderr=subprocess.STDOUT)
after, _ = candidate()
record = dict(command=command, cwd=str(root), start=start, duration_s=round(time.monotonic()-t0, 3), exit_code=result.returncode,
              candidate_sha256=before, candidate_after_sha256=after, stable=before == after, product=rows,
              validation_context=validation_context,
              environment={k:v for k,v in os.environ.items() if k in ('APP_ENV','DB_CONNECTION','DB_DATABASE','PGSQL_PORT','MYSQL_PORT','MARIADB_PORT','__PEST_PLUGIN_ENV')})
prefix.with_suffix('.json').write_text(json.dumps(record, ensure_ascii=False, indent=2)+'\n')
print(json.dumps({k:record[k] for k in ('exit_code','candidate_sha256','stable','duration_s')}))
print('log: '+str(prefix.with_suffix('.log').relative_to(root)))
if result.returncode:
    lines = prefix.with_suffix('.log').read_text(errors='replace').splitlines()
    print('\n'.join(x[:600] for x in lines[-18:]))
sys.exit(result.returncode)
