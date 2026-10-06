import json,os,subprocess,time
from pathlib import Path
B=Path(__file__).resolve().parent
session=json.load((B/'review-launch.json').open())['session']
command=['/home/vostrikov/.local/bin/grok','--cwd',str(B/'review-input'),'--resume',session,'--model','grok-4.7','--reasoning-effort','high','--sandbox','off','--permission-mode','dontAsk','--tools','read_file,grep,list_dir','--deny','Bash','--deny','Edit','--deny','Write','--deny','MCPTool(*)','--no-subagents','--disable-web-search','--max-turns','2','--output-format','json','--debug-file',str(B/'review-finalize-debug.log'),'--prompt-file',str(B/'review-finalize-prompt.txt')]
(B/'review-finalize-launch.json').write_text(json.dumps({'session':session,'provider':'grok','model':'grok-4.7','effort':'high','context_parameter':None,'continuation_of_same_review':True,'command':command},indent=2)+'\n')
print('resume same native reviewer',session,flush=True);start=time.time()
with (B/'review-finalize-result.json').open('w') as out,(B/'review-finalize-stderr.log').open('w') as err:
 code=subprocess.run(command,env={**os.environ,'GROK_AGENT_DASHBOARD':'0'},stdout=out,stderr=err).returncode
(B/'review-finalize-transport.json').write_text(json.dumps({'exit':code,'session':session,'duration_seconds':time.time()-start},indent=2)+'\n')
print('native finalization exit',code,flush=True)
