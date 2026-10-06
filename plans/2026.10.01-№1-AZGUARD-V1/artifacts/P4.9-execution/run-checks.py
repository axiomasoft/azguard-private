import hashlib,json,os,subprocess,sys,time
from pathlib import Path
BASE=Path(__file__).resolve().parent
ROOT=BASE.parents[3]
os.chdir(ROOT)
FILES=json.loads((BASE/'product-files.json').read_text())
PHP=[p for p in FILES if p.endswith('.php')]
PEST=['php','-d','memory_limit=1G','vendor/bin/pest']
COMMANDS={
'V1':PEST+['tests/Feature/Authorization/Batch','tests/Acceptance/Crm','--compact','--fail-on-skipped'],
'V3':PEST+['tests/Arch','--compact','--fail-on-skipped'],
'V4':['php','bin/api-manifest.php','--check'],
'V5':['composer','api:manifest'],
'V6':PEST+['tests/Feature/Authorization','tests/Feature/Scopes','tests/Feature/Sources','tests/Feature/Policies','tests/Regression','tests/Unit/Authorization','tests/Unit/Kernel/Decision','--exclude-group=batch,engines,redis','--compact','--fail-on-skipped'],
'V7':['vendor/bin/pint','--test']+PHP,
'V8':['vendor/bin/phpstan','analyse','packages/core/src','--memory-limit=1G','--no-progress','--error-format=json'],
'V9':['php','-d','memory_limit=1G','-d','variables_order=EGPCS','vendor/bin/pest','--configuration='+str(BASE/'phpunit-core.xml'),'--type-coverage','--min=98','--no-cache','--type-coverage-json='+str(BASE/'types.json')],
'V10':['git','diff','--check'],
}
def snapshot():
 return {p:hashlib.sha256(Path(p).read_bytes()).hexdigest() for p in FILES}
def run(key,command,env):
 before=snapshot();start=time.time()
 with (BASE/(key+'.log')).open('w') as output:
  code=subprocess.run(command,env={**os.environ,**env},stdout=output,stderr=subprocess.STDOUT).returncode
 after=snapshot()
 proof={'id':key,'command':command,'env':env,'exit':code,'duration_seconds':round(time.time()-start,3),'candidate_sha256':hashlib.sha256(json.dumps(before,sort_keys=True).encode()).hexdigest(),'before':before,'after':after,'fresh':before==after,'log':str(BASE/(key+'.log'))}
 (BASE/(key+'.json')).write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
 print(key,code,'fresh' if before==after else 'CHANGED',flush=True)
 if code or before!=after:sys.exit(code or 98)
for key in sys.argv[1:]:
 if key=='V2':
  gates=[]
  for name,var,port in [('pgsql','PGSQL_PORT','25432'),('mysql','MYSQL_PORT','23306'),('mariadb','MARIADB_PORT','23307')]:
   gate='V2-'+name;run(gate,PEST+['tests/Engines/BatchFenceRaceTest.php','--compact','--fail-on-skipped'],{'DB_CONNECTION':name,var:port})
   gates.append(json.loads((BASE/(gate+'.json')).read_text()))
  (BASE/'V2.json').write_text(json.dumps({'id':'V2','exit':0,'engines':gates},indent=2)+'\n')
 else:run(key,COMMANDS[key],{'__PEST_PLUGIN_ENV':'1'} if key=='V9' else {})
