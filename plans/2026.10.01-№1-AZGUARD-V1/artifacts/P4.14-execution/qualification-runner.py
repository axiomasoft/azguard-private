from pathlib import Path
import os,json,subprocess,hashlib,time,concurrent.futures,datetime
root=Path('/home/vostrikov/projects/packages/azguard');art=root/'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.14-execution'
def inventory():
 paths=subprocess.check_output(['git','ls-files','packages','tests','bin','composer.json','composer.lock','phpunit.xml','phpstan.neon','pint.json','docker-compose.yml'],cwd=root,text=True).splitlines()
 return {p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in paths if (root/p).is_file()}
before=inventory();(art/'product-before.json').write_text(json.dumps(before,indent=2))
env=dict(os.environ,APP_ENV='testing',DB_CONNECTION='sqlite',DB_DATABASE=':memory:',PGSQL_PORT='25432',PGSQL_DATABASE='azguard_test',MYSQL_PORT='23306',MYSQL_DATABASE='azguard_test',MARIADB_PORT='23307',MARIADB_DATABASE='azguard_test',REDIS_PORT='26379')
def run(k,args,extra=None):
 t=time.monotonic(); merged=dict(env); merged.update(extra or {});stamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
 with (art/(k+'.log')).open('w') as log: code=subprocess.run(args,cwd=root,env=merged,stdout=log,stderr=subprocess.STDOUT).returncode
 data={'id':k,'argv':args,'env':{n:merged[n] for n in ['APP_ENV','DB_CONNECTION','DB_DATABASE','PGSQL_PORT','MYSQL_PORT','MARIADB_PORT','REDIS_PORT',*(extra or {}).keys()]},'exit_code':code,'started_at':stamp,'duration_seconds':round(time.monotonic()-t,2)}
 (art/(k+'.json')).write_text(json.dumps(data,indent=2)); print(k,code,data['duration_seconds'],flush=True);return data
checks={}
checks['V2']=run('V2',['docker','compose','up','-d','--wait','postgres','mysql','mariadb','redis'])
if checks['V2']['exit_code']: raise SystemExit(1)
php=r"""<?php
$out=[];
foreach (['pgsql'=>25432,'mysql'=>23306,'mariadb'=>23307] as $d=>$p) {
 $pdo=new PDO(($d==='pgsql'?'pgsql':'mysql').':host=127.0.0.1;port='.$p.';dbname=azguard_test','azguard','azguard',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db=$pdo->query($d==='pgsql'?'select current_database()':'select database()')->fetchColumn();
 if($db!=='azguard_test')throw new RuntimeException('test target mismatch');
 $out[$d]=['host'=>'127.0.0.1','port'=>$p,'database'=>$db,'version'=>$pdo->query('select version()')->fetchColumn()];
}
$r=new Redis();$r->connect('127.0.0.1',26379);$out['redis']=['port'=>26379,'version'=>$r->info('server')['redis_version'],'test_database'=>15,'cleanup'=>'unique qualification prefix only'];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
"""
(art/'database-preflight.php').write_text(php)
checks['preflight']=run('preflight',['php',str(art/'database-preflight.php')])
if checks['preflight']['exit_code']: raise SystemExit(1)
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
 jobs={
 'V1':pool.submit(run,'V1',['composer','test']),
 'V4':pool.submit(run,'V4',['vendor/bin/pint','--test']),
 'V5':pool.submit(run,'V5',['vendor/bin/phpstan','analyse','--memory-limit=1G','--no-progress'])}
 for k,f in jobs.items():checks[k]=f.result()
# Expanded V5 was run separately; its exact command and exit are in V5-expanded.json.
for d in ['pgsql','mysql','mariadb']:
 checks['engine-'+d]=run('engine-'+d,['php','-d','memory_limit=1G','vendor/bin/pest','--group=engines','--fail-on-skipped','--compact'],{'DB_CONNECTION':d,'VISIBILITY_EVIDENCE_DIR':str(art)})
checks['V3']=run('V3',['composer','test:redis'])
checks['replica']=run('replica',['bash','tests/Engines/Support/replica-fixture.sh'],{'AUTHORITY_PRIMARY_PORT':'25433','AUTHORITY_REPLICA_PORT':'25434','AZGUARD_QUALIFICATION_ARTIFACT_DIR':str(art)})
checks['repeat-fence']=run('repeat-fence',['php','-d','memory_limit=1G','vendor/bin/pest','tests/Engines/BatchFenceRaceTest.php','--fail-on-skipped','--compact'],{'DB_CONNECTION':'pgsql'})
checks['V6']=run('V6',['php','bin/api-manifest.php','--check'])
checks['V7']=run('V7',['php','-d','memory_limit=1G','-d','variables_order=EGPCS','vendor/bin/pest','--type-coverage','--min=98','--no-cache','--type-coverage-json='+str(art/'type-coverage.json')],{'__PEST_PLUGIN_ENV':'1'})
checks['V8']=run('V8',['git','status','--porcelain','--','packages','tests','bin'])
after=inventory();(art/'product-after.json').write_text(json.dumps(after,indent=2))
summary={'checks':checks,'product_unchanged':before==after,'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'baseline':'f6c83a1','read_only_status_empty':not (art/'V8.log').read_text().strip()}
(art/'qualification-summary.json').write_text(json.dumps(summary,indent=2));print('product unchanged',before==after,flush=True)
