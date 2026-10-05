import json,os,pathlib,subprocess,time,hashlib
root=pathlib.Path('/tmp/azguard-dependency-qualification/candidate')
out=pathlib.Path('/tmp/azguard-dependency-qualification/run-003')
checks=json.loads((out/'checks.json').read_text())
results=[]
env={**os.environ,'APP_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','COMPOSER_PROCESS_TIMEOUT':'0','PHP_INI_SCAN_DIR':'/home/vostrikov/.config/herd-lite/bin:/home/vostrikov/projects/packages/azguard/plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.5-execution/php-validation-environment-001'}
for check in checks:
 key=check['id']; log=out/(key+'.log')
 print('START '+key,flush=True); start=time.time()
 with log.open('x') as f:
  result=subprocess.run(check['argv'],cwd=root,env={**env,**check.get('env',{})},stdout=f,stderr=subprocess.STDOUT)
 row={**check,'exit_code':result.returncode,'log':str(log),'log_sha256':hashlib.sha256(log.read_bytes()).hexdigest(),'seconds':round(time.time()-start,2),'candidate_commit':'a3f996563832861886fbe07fde90c2f705fd3fa5'}
 results.append(row);(out/'results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
 print('END '+key+' exit='+str(result.returncode)+' seconds='+str(row['seconds']),flush=True)
