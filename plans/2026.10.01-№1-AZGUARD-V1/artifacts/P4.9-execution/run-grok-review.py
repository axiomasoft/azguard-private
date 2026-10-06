import hashlib,json,os,subprocess,time,uuid
from pathlib import Path
B=Path(__file__).resolve().parent
session=str(uuid.uuid4()); scope=json.load((B/'final-candidate-files.json').open())
command=['/home/vostrikov/.local/bin/grok','--cwd',str(B/'review-input'),'--model','grok-4.7','--reasoning-effort','high','--session-id',session,'--sandbox','off','--permission-mode','dontAsk','--tools','read_file,grep,list_dir','--deny','Bash','--deny','Edit','--deny','Write','--deny','MCPTool(*)','--no-subagents','--disable-web-search','--max-turns','35','--output-format','json','--debug-file',str(B/'review-debug.log'),'--prompt-file',str(B/'review-prompt.txt')]
(B/'review-launch.json').write_text(json.dumps({'session':session,'provider':'grok','model':'grok-4.7','effort':'high','context_parameter':None,'command':command,'scope':scope},ensure_ascii=False,indent=2)+'\n')
print('native reviewer session',session,flush=True)
start=time.time()
with (B/'review-result.json').open('w') as out,(B/'review-stderr.log').open('w') as err:
 code=subprocess.run(command,env={**os.environ,'GROK_AGENT_DASHBOARD':'0'},stdout=out,stderr=err).returncode
(B/'review-transport.json').write_text(json.dumps({'exit':code,'session':session,'duration_seconds':time.time()-start},indent=2)+'\n')
print('native reviewer exit',code,flush=True)
