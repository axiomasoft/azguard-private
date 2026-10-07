import subprocess,pathlib,json,datetime
p=pathlib.Path(__file__).parent
f=p/"review-transport.json"
d=json.loads(f.read_text())
with (p/"review-stdout.json").open("w") as out,(p/"review-stderr.log").open("w") as err:
 r=subprocess.run(d["command"],stdout=out,stderr=err)
d.update(exit_code=r.returncode,finished_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
f.write_text(json.dumps(d,ensure_ascii=False,indent=2)+"\n")
print("review exited",r.returncode,"session",d["session_id"])
