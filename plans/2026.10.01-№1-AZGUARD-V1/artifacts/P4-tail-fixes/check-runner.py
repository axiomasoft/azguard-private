import subprocess,sys,json,os,time,hashlib,datetime,pathlib,shlex
base=pathlib.Path(__file__).resolve().parent
name=sys.argv[1]; argv=sys.argv[2:]
env=dict(os.environ,APP_ENV="testing",DB_CONNECTION="sqlite",DB_DATABASE=":memory:",REDIS_PORT="26379")
start=datetime.datetime.now(datetime.timezone.utc).isoformat(); t=time.monotonic()
with (base/(name+".log")).open("wb") as log:
 result=subprocess.run(argv,stdout=log,stderr=subprocess.STDOUT,env=env)
record={"id":name,"argv":argv,"cmd":shlex.join(argv),"cwd":os.getcwd(),"env":{k:env[k] for k in ["APP_ENV","DB_CONNECTION","DB_DATABASE","REDIS_PORT"]},"exit":result.returncode,"started_at":start,"finished_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),"duration_seconds":round(time.monotonic()-t,2),"log":str((base/(name+".log")).relative_to(pathlib.Path.cwd())),"log_sha256":hashlib.sha256((base/(name+".log")).read_bytes()).hexdigest()}
target=base/(name+".json"); tmp=target.with_suffix(".tmp"); tmp.write_text(json.dumps(record,indent=2)+"\n"); tmp.replace(target)
print(json.dumps(record)); print((base/(name+".log")).read_text()[-3500:]); sys.exit(result.returncode)
