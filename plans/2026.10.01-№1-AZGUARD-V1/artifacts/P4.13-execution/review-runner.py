from pathlib import Path
import os,json,shutil,uuid,subprocess,hashlib,time
root=Path('/home/vostrikov/projects/packages/azguard');art=root/'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.13-execution'
sid=str(uuid.uuid4());snap=Path('/tmp/azguard-p413-review-'+sid);gh=Path('/tmp/azguard-p413-grok-'+sid);snap.mkdir();gh.mkdir(mode=0o700)
for name in ['packages','tests','bin','audits/2026-09-29-audit/opus']:
 shutil.copytree(root/name,snap/name,ignore=shutil.ignore_patterns('.git','node_modules','vendor','evidence') if name.startswith('audits') else None)
for name in ['composer.json','composer.lock','phpunit.xml','phpstan.neon','pint.json']:
 shutil.copy2(root/name,snap/name)
(snap/'contracts').mkdir();context=json.load(open(root/'plans/2026.10.01-№1-AZGUARD-V1/context/P4.13.json'))
for item in [context['item'],*context['upstream']]:
 (snap/'contracts'/f"{item['id']}.json").write_text(json.dumps(item,ensure_ascii=False,indent=2))
for decision in context['decisions']:
 (snap/'contracts'/f"{decision['id']}.json").write_text(json.dumps(decision,ensure_ascii=False,indent=2))
for section in context['brief']['sections']:
 if 'P4' in section['id']:
  (snap/'contracts'/f"{section['id']}.json").write_text(json.dumps(section,ensure_ascii=False,indent=2))
for doc in context['documents']:
 if doc['id']=='findings-P4-execution': (snap/'contracts'/(doc['id']+'.json')).write_text(json.dumps(doc,ensure_ascii=False,indent=2))
shutil.copytree(art,snap/'validation');(snap/'diff').mkdir()
base='f6c83a1';diff=subprocess.check_output(['git','diff',base+'..HEAD','--','packages/core','tests','bin','composer.json','phpunit.xml'],cwd=root)
(snap/'diff/full-slice.patch').write_bytes(diff)
(snap/'diff/stat.txt').write_bytes(subprocess.check_output(['git','diff','--stat',base+'..HEAD','--','packages/core','tests','bin','composer.json','phpunit.xml'],cwd=root))
(snap/'diff/cache-out-of-scope.patch').write_bytes(subprocess.check_output(['git','show','7201c3a','--','packages/core','tests'],cwd=root))
# No hooks, MCP, inherited instructions, history, active plugins or subagents.
shutil.copy2(Path('/tmp/azguard-p415-grok-e1efa223-6d01-4ff6-8c55-524cc51cfbce/auth.json'),gh/'auth.json');os.chmod(gh/'auth.json',0o600)
(gh/'config.toml').write_text('[cli]\nauto_update = false\n[models]\ndefault = "grok-4.7"\n[model."grok-4.7"]\nmodel = "grok-4.7"\ndefault_reasoning_effort = "high"\n[plugins]\nenabled = []\n[memory]\nenabled = false\n[marketplace]\ndefault_skills_installs_purged = true\nofficial_marketplace_auto_installed = true\n')
prompt='''Perform ONE independent read-only final review of PLAN2 P4.13 (AzGuard 1.0 authorization slice). Return actual verdict and stop. No code/test/plan changes; no subagents, web, extra tests or deployment. The owner requests efficient focused review and no context parameter. This is the final review of the ENTIRE scope, not another review of each historic item. Fresh Grok4.7/high native session. All files are in this snapshot; do not follow project-hub/Brain links or legacy workflow instructions.

Start with contracts/P4.13.json, diff/stat.txt, validation/qualification-summary.json; then read D14/D15/D16 and brief-P4-acceptance-matrix.md.json as needed. Read mandatory source docs audits/2026-09-29-audit/opus/{09-authorization-semantics,18-contexts-and-runtime-inputs,17-crm-acceptance-tests,14-verification}.md and packages/core/src/{Contracts/Authorization/EvaluationContext.php,Catalog/PanelCatalog.php,Catalog/RoleCompiler.php,Panels/PanelBuilder.php}. Contracts/P4.1-P4.7,P4.15-P4.19 are full item contracts and historic execution findings are available, but historic GREEN is not proof of current code. Review current implementation against contract and concrete scenarios; use current evidence.

Included owners P4.1-P4.7,P4.15-P4.19: pipeline stage ordering and failclosed; PolicyOnly no resolving/reading assignment services/state/dynamic; Grants policy true cannot replace grants; conditions, role-filters, scope, exact expiry AND within each witness / OR whole contributions; all four scope policies and authoritative resource/tenant owner; ordinary global cannot flow into tenant, invalid explicit refs deny; superadmin only Grants + qualified role witness/scope/expiry/conditions, exclusions/membership/common eligibility/mandatory restrictions; every source consumed independent of source order, source failure not hidden; early raw T_before/T_after incl dynamic Prepare overlay, max3 whole-attempt retries; role key unknown/former/NotGrantable no authority; no FQCN storage; built-in sources obey public D15 section7 allowlist and arch tests are effective; generic model=null scopes resolve without querying and typed/native/external adapter/filter DI correct; CRM positives/literal expectations/negative controls not reimplementation; explicit residual owners and V15 properties completeness. Include malformed capabilities, lazy generators, nested/Fiber finally, grant-condition runtime context if evidence warrants. Each actual finding MUST include severity blocker/major/minor, path:line, violated normative source/section, concrete reproducible trigger or existing test/scratch command, consequence and owning item. Uncertainty is not a defect; no cosmetic preferences.

Excluded P4.8-P4.12/P4.20-P4.23: already committed cache P4.8 appears in full-slice.patch but cache implementation/freshness issues belong to final P4.14. Read cache interaction if needed to understand present pipeline; do not report absent future APIs, transaction/freshness/Redis/visibility/batch/Gate/explain capabilities as slice defects. diff/cache-out-of-scope.patch identifies the P4.8 contribution. Review the current core + CRM and relevant tests; unchanged Filament/ecosystem packages excluded by direct owner instruction.

Checks were run once across all relevant core tests (2529 /372805 assertions, no skips), arch (72/314), PHPStan(core0errors), API, core type coverage fresh no-cache, pgsql/mysql/mariadb DatabaseSource+ScopedSource engines, and scratch CRM R11/R13/R14 mutation controls. Raw evidence validation/*.log/*.json. Product hashes identical before/after. One known FAILED acceptance gate: Pint V4 reports tests/Acceptance/Crm/ContextRolesTest.php trailing_comma_in_multiline. Do not hide this or demand editing during review; owning item P4.15. Optional PHPStan turbo loading warning is environmental; analysis completed errors0. V7 initial used plugin cache, fresh no-cache report also supplied. V2 covered by merged V1 run (avoid duplicate suite), V1/V4/V5/V7 scoped to core by owner. No unchanged Filament/ecosystem or unrelated P3 engine reruns.

Use read_file/grep/list_dir efficiently, inspect full relevant implementation and trace evidence; prefer targeted source reads over extensive historic artifacts. Return self-contained JSON object as final response: verdict GREEN if no blocker/major in code else RED; validation_verdict GREEN or RED (failed Pint currently RED), findings array with fields id,severity,location,source,scenario,impact,owning_item; checklist array for ten main dimensions with status/evidence; checks summary; residual_owners; reviewed_files; explain if lint failure prevents overall acceptance. Do not invent tests run by you; cite executor evidence explicitly. A failed/skipped gate cannot be called GREEN. No further tools after final response.'''
(art/'review-prompt.txt').write_text(prompt)
command=['/home/vostrikov/.local/bin/grok','--cwd',str(snap),'--model','grok-4.7','--reasoning-effort','high','--session-id',sid,'--sandbox','off','--permission-mode','dontAsk','--tools','read_file,grep,list_dir','--deny','Bash','--deny','Edit','--deny','Write','--deny','MCPTool(*)','--no-subagents','--disable-web-search','--output-format','json','--prompt-file',str(art/'review-prompt.txt')]
meta={'session':sid,'provider':'grok','model':'grok-4.7','effort':'high','cwd':str(snap),'home':str(gh),'command':command,'context_parameter':None,'native_tool_allowlist':['read_file','grep','list_dir'],'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()};(art/'review-launch.json').write_text(json.dumps(meta,indent=2));print(json.dumps(meta),flush=True)
env=dict(os.environ,GROK_HOME=str(gh));t=time.monotonic()
with (art/'review-response.json').open('w') as out,(art/'review-stderr.log').open('w') as err:
 code=subprocess.run(command,cwd=snap,env=env,stdout=out,stderr=err).returncode
(art/'review-exit.json').write_text(json.dumps({'session':sid,'exit_code':code,'duration_seconds':round(time.monotonic()-t,2)},indent=2));print('native review exit',code,flush=True)
