import datetime, hashlib, json, os, pathlib, subprocess, sys, time
ROOT=pathlib.Path('/home/vostrikov/projects/packages/azguard')
BASE=ROOT/'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.8-execution/runs'
ENV={'APP_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','PHP_INI_SCAN_DIR':':/tmp/azguard-p48-php-conf'}
def scoped(name):
    p=pathlib.PurePosixPath(name)
    if p.parts and p.parts[0]=='tests': return not any(q in p.parts for q in ('testbench-core','vendor','.pest','.phpunit.cache'))
    if p.parts and p.parts[0]=='bin': return True
    if len(p.parts)>=3 and p.parts[0]=='packages' and p.parts[2] in ('src','config'): return True
    if len(p.parts)==3 and p.parts[0]=='packages' and p.parts[2] in ('api-manifest.json','composer.json','composer.lock'): return True
    return len(p.parts)==1 and (name.startswith(('composer.','phpstan','phpunit','pint','rector','type-coverage')) or name in ('CHANGELOG.md','coverage.php'))
def snapshot():
    names=set(subprocess.check_output(['git','ls-files','-z'],cwd=ROOT).decode().split(chr(0)))
    names.update(subprocess.check_output(['git','ls-files','--others','--exclude-standard','-z'],cwd=ROOT).decode().split(chr(0)))
    for base in [ROOT/'tests',ROOT/'bin',*ROOT.glob('packages/*/src'),*ROOT.glob('packages/*/config')]:
        if base.exists(): names.update(str(p.relative_to(ROOT)) for p in base.rglob('*') if p.is_file())
    paths=sorted(n for n in names if n and scoped(n))
    files={n:hashlib.sha256((ROOT/n).read_bytes()).hexdigest() if (ROOT/n).is_file() else None for n in paths}
    directories=sorted({str(p) for n in files for p in pathlib.PurePosixPath(n).parents if str(p)!='.'})
    dirs={n:('directory' if (ROOT/n).is_dir() else 'missing') for n in directories}
    data={'files':files,'directories':dirs}
    digest=hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    return {'sha256':digest,'file_count':len(files),'deleted_files':[n for n,h in files.items() if h is None],**data}
if __name__=='__main__':
    run,check,command=sys.argv[1:4]
    out=BASE/run
    out.mkdir(parents=True,exist_ok=True)
    if (out/(check+'.json')).exists(): raise SystemExit('Immutable run already exists')
    before=snapshot()
    start=datetime.datetime.now(datetime.timezone.utc).isoformat()
    clock=time.monotonic()
    (out/(check+'-before.json')).write_text(json.dumps(before,ensure_ascii=False,indent=2)+'\n')
    with (out/(check+'.log')).open('wb') as log:
        result=subprocess.run(command,shell=True,cwd=ROOT,env={**os.environ,**ENV},stdout=log,stderr=subprocess.STDOUT)
    after=snapshot()
    (out/(check+'-after.json')).write_text(json.dumps(after,ensure_ascii=False,indent=2)+'\n')
    row={'check_id':check,'command':command,'cwd':str(ROOT),'environment':ENV,'started_at':start,'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'duration_seconds':round(time.monotonic()-clock,3),'exit_code':result.returncode,'candidate_before':before['sha256'],'candidate_after':after['sha256'],'candidate_stable':before['sha256']==after['sha256'],'log':str((out/(check+'.log')).relative_to(ROOT)),'before_inventory':str((out/(check+'-before.json')).relative_to(ROOT)),'after_inventory':str((out/(check+'-after.json')).relative_to(ROOT)),'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()}
    (out/(check+'.json')).write_text(json.dumps(row,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(row,ensure_ascii=False))
    sys.exit(result.returncode if result.returncode else (0 if row['candidate_stable'] else 90))
