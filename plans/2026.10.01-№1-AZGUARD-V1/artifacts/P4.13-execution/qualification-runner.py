from pathlib import Path
import subprocess,json,os,time,hashlib,concurrent.futures,xml.etree.ElementTree as ET
root=Path('/home/vostrikov/projects/packages/azguard'); art=root/'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.13-execution'
def inventory():
 paths=subprocess.check_output(['git','ls-files','packages','tests','bin','composer.json','composer.lock','phpunit.xml','phpstan.neon'],cwd=root,text=True).splitlines()
 return {p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in paths if (root/p).is_file()}
before=inventory();(art/'product-before.json').write_text(json.dumps(before,indent=2));(art/'baseline-head.txt').write_text(subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True))
corepaths=[]
for base in ['tests/Acceptance','tests/Unit','tests/Feature','tests/Regression']:
 for p in sorted((root/base).glob('*')):
  if 'Filament' not in str(p): corepaths.append(str(p.relative_to(root)))
typeconfig=ET.parse(root/'phpunit.xml');source=typeconfig.getroot().find('source/include')
for element in list(source):
 if 'filament' in (element.text or ''):source.remove(element)
for element in typeconfig.getroot().iter('directory'):
 element.text=str(root/element.text)
typeconfig.getroot().set('bootstrap',str(root/'vendor/autoload.php'))
typefile=Path('/tmp/azguard-p413-types.xml');typeconfig.write(typefile)
def run(k,args,env=None):
 start=time.monotonic(); merged=dict(os.environ,APP_ENV='testing',DB_CONNECTION='sqlite',DB_DATABASE=':memory:');merged.update(env or {})
 with (art/(k+'.log')).open('w') as log:
  code=subprocess.run(args,cwd=root,env=merged,stdout=log,stderr=subprocess.STDOUT).returncode
 data={'id':k,'argv':args,'environment':{n:merged[n] for n in ['APP_ENV','DB_CONNECTION','DB_DATABASE',*(env or {}).keys()]},'exit_code':code,'duration_seconds':round(time.monotonic()-start,2)}
 (art/(k+'.json')).write_text(json.dumps(data,ensure_ascii=False,indent=2)); print(k,code,data['duration_seconds'],flush=True);return data
checks={}
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
 jobs={
 'V1':pool.submit(run,'V1',['php','-d','memory_limit=1G','vendor/bin/pest',*corepaths,'--exclude-group=engines','--fail-on-skipped','--compact']),
 'V4':pool.submit(run,'V4',['vendor/bin/pint','--test','packages/core','tests/Acceptance/Crm','tests/Feature/Authorization','tests/Feature/Scopes','tests/Feature/Sources','tests/Fixtures/Crm']),
 'V5':pool.submit(run,'V5',['vendor/bin/phpstan','analyse','packages/core/src','--memory-limit=1G','--no-progress']),
 'V7':pool.submit(run,'V7',['php','-d','memory_limit=1G','vendor/bin/pest','--configuration='+str(typefile),'--type-coverage','--min=98','--compact'])}
 for k,future in jobs.items(): checks[k]=future.result()
checks['arch']=run('arch',['php','-d','memory_limit=1G','vendor/bin/pest','tests/Arch','--compact'])
checks['V6']=run('V6',['php','bin/api-manifest.php','--check'])
for driver,env in [('pgsql',{'PGSQL_PORT':'25432','PGSQL_DATABASE':'azguard_test'}),('mysql',{'MYSQL_PORT':'23306','MYSQL_DATABASE':'azguard_test'}),('mariadb',{'MARIADB_PORT':'23307','MARIADB_DATABASE':'azguard_test'})]:
 env['DB_CONNECTION']=driver
 checks['engine-'+driver]=run('engine-'+driver,['php','-d','memory_limit=1G','vendor/bin/pest','tests/Engines/DatabaseSourceEngineTest.php','tests/Engines/ScopedSourceIntegrationTest.php','--fail-on-skipped','--compact'],env)
checks['mutations']=run('mutations',['python3',str(root/'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.15-execution/mutations.py')])
checks['V8']=run('V8',['git','status','--porcelain','--','packages','tests','bin'])
after=inventory();(art/'product-after.json').write_text(json.dumps(after,indent=2));summary={'checks':checks,'product_unchanged':before==after,'excluded':['Filament tests/static/type','ecosystem package checks','unrelated P3 engine suites','P4.20/P4.21 freshness and P4.12/P4.23 visibility'],'core_test_paths':corepaths}
(art/'qualification-summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2));print('product unchanged',before==after,flush=True)
