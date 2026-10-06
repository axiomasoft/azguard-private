import datetime
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time

ROOT = pathlib.Path('/home/vostrikov/projects/packages/azguard')
EVIDENCE = ROOT / 'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.7-execution'

def candidate():
    paths = set()
    for base in ('packages/core/src', 'packages/filament/src', 'tests', 'bin'):
        paths.update(p for p in (ROOT/base).rglob('*') if p.is_file() and p.suffix in ('.php', '.md', '.json', '.sh') and 'testbench-core' not in p.parts)
    for pattern in ('composer.*', 'phpunit.xml', 'phpstan*', 'rector.php', 'pint.json', 'CHANGELOG.md', 'packages/*/api-manifest.json', 'packages/*/composer.json'):
        paths.update(p for p in ROOT.glob(pattern) if p.is_file())
    manifest = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(paths)}
    digest = hashlib.sha256(json.dumps(manifest, sort_keys=True).encode()).hexdigest()
    return {'sha256': digest, 'files': manifest}

if __name__ == '__main__':
    if sys.argv[1] == 'hash':
        print(candidate()['sha256'])
        sys.exit(0)
    run, check, command = sys.argv[1:4]
    out = EVIDENCE/run
    out.mkdir(parents=True, exist_ok=True)
    receipt = out/(check+'.json')
    if receipt.exists():
        raise SystemExit('Immutable attempt already exists: '+str(receipt))
    before = candidate()
    start = datetime.datetime.now(datetime.timezone.utc).isoformat()
    clock = time.monotonic()
    environment = {'APP_ENV':'testing', 'DB_CONNECTION':'sqlite', 'DB_DATABASE':':memory:', 'PHP_INI_SCAN_DIR':':/tmp/azguard-p47-php-conf'}
    with (out/(check+'.log')).open('wb') as log:
        result = subprocess.run(command, shell=True, cwd=ROOT, env={**os.environ, **environment}, stdout=log, stderr=subprocess.STDOUT)
    after = candidate()
    row = {'command':command, 'environment':environment, 'exit_code':result.returncode, 'started_at':start, 'duration_seconds':round(time.monotonic()-clock,3), 'candidate_before':before['sha256'], 'candidate_after':after['sha256'], 'candidate_stable':before['sha256']==after['sha256'], 'log':str((out/(check+'.log')).relative_to(ROOT)), 'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()}
    row.update({'$schema':'urn:swissknifeman:orch:exec:check-evidence:v1', 'contract_version':'exec/v1', 'check_id':check, 'candidate':{'head':row['head'], 'product_sha256':before['sha256']}, 'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat(), 'evidence_id':'azguard-p47:'+run+':'+check, 'subject':'PLAN2.P4.7', 'procedure':{'cmd':command, 'cwd':str(ROOT)}, 'producer':{'kind':'executor', 'actor':{'provider':'codex', 'model':'gpt-6.1-sol', 'effort':'medium'}}, 'exit':result.returncode})
    receipt.write_text(json.dumps(row,ensure_ascii=False,indent=2)+'\n')
    manifest_path=out/(check+'-candidate.json')
    manifest_path.write_text(json.dumps(before,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(row,ensure_ascii=False))
    sys.exit(result.returncode)
