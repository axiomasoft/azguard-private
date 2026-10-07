from pathlib import Path
import shutil,tempfile,xml.etree.ElementTree as ET,json,subprocess,os
root=Path('/home/vostrikov/projects/packages/azguard');art=root/'plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.14-execution';snap=Path(tempfile.mkdtemp(prefix='azguard-p414-probes-'))
for name in ['packages','tests']:shutil.copytree(root/name,snap/name)
(snap/'vendor/pestphp/pest/bin').mkdir(parents=True)
shutil.copy2(root/'vendor/pestphp/pest/bin/pest',snap/'vendor/pestphp/pest/bin/pest')
for n in ['composer.json','composer.lock']:shutil.copy2(root/n,snap/n)
boot=r'''<?php
$loader = require '/home/vostrikov/projects/packages/azguard/vendor/autoload.php';
$loader->addPsr4('AzGuard\\', __DIR__.'/packages/core/src', true);
$loader->addPsr4('AzGuard\\Tests\\', __DIR__.'/tests', true);
$loader->addPsr4('AzGuard\\Filament\\', __DIR__.'/packages/filament/src', true);
foreach ($loader->getClassMap() as $class => $file) {
    $real = realpath($file);
    foreach (['packages', 'tests'] as $top) {
        $prefix = '/home/vostrikov/projects/packages/azguard/'.$top.'/';
        if ($real !== false && str_starts_with($real, $prefix)) {
            $loader->addClassMap([$class => __DIR__.'/'.$top.'/'.substr($real, strlen($prefix))]);
        }
    }
}
return $loader;
'''
(snap/'probe-bootstrap.php').write_text(boot)
(snap/'vendor/autoload.php').write_text(boot.replace('__DIR__', "dirname(__DIR__)"))
x=ET.parse(root/'phpunit.xml');x.getroot().set('bootstrap',str(snap/'probe-bootstrap.php'));x.write(snap/'phpunit.xml')
extra=r'''<?php
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
beforeEach(fn () => GateWorld::seed());
afterEach(function (): void { Carbon::setTestNow(); Relation::morphMap([], false); });
it('P4.14 independent deny-hook scratch agrees across scalar batch explain and Gate', function (): void {
    [$engine, $panel, $request] = GateWorld::compile(new GeneratedSource, fn (PanelBuilder $p) => $p->before(fn () => BeforeResult::Deny));
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::Hook)
        ->and($engine->decideMany([$request])->get(0))->toEqual($decision)
        ->and($engine->explain($panel, $request)->decision())->toEqual($decision)
        ->and(Gate::forUser($request->subject())->inspect('admin:orders.view')->allowed())->toBeFalse();
});
'''
(snap/'tests/Feature/Gate/P414ScratchTest.php').write_text(extra)
meta={'scratch':str(snap),'bootstrap':boot,'scratch_test':extra,'mutation':"Visibility.php final addNestedWhereQuery group boolean changed AND->OR on scratch copy only"};(art/'scratch-setup.json').write_text(json.dumps(meta,indent=2))
cmd=['php','-d','memory_limit=1G',str(snap/'vendor/pestphp/pest/bin/pest'),'--configuration='+str(snap/'phpunit.xml'),'tests/Feature/Gate/P414ScratchTest.php','tests/Feature/Visibility/VisibleToTest.php','tests/Feature/Authorization/Cache/IsolationTest.php','--compact'];env=dict(os.environ,APP_ENV='testing',DB_CONNECTION='sqlite',DB_DATABASE=':memory:')
with (art/'scratch-control.log').open('w') as out:code=subprocess.run(cmd,cwd=snap,env=env,stdout=out,stderr=subprocess.STDOUT).returncode
print('scratch control',code,flush=True)
(art/'scratch-control.json').write_text(json.dumps({'argv':cmd,'cwd':str(snap),'exit_code':code},indent=2))
if code:raise SystemExit(1)
p=snap/'packages/core/src/Authorization/Visibility.php';s=p.read_text();a='$query->getQuery()->addNestedWhereQuery($group->getQuery());';b="$query->getQuery()->addNestedWhereQuery($group->getQuery(), 'or');";assert s.count(a)==1;p.write_text(s.replace(a,b))
cmd=['php','-d','memory_limit=1G',str(snap/'vendor/pestphp/pest/bin/pest'),'--configuration='+str(snap/'phpunit.xml'),'tests/Feature/Visibility/VisibleToTest.php','--compact']
with (art/'scratch-mutant.log').open('w') as out:code=subprocess.run(cmd,cwd=snap,env=env,stdout=out,stderr=subprocess.STDOUT).returncode
(art/'scratch-mutant.json').write_text(json.dumps({'argv':cmd,'cwd':str(snap),'exit_code':code,'expected':'nonzero; outer OR bypass must be caught'},indent=2));print('mutant expected failure',code,flush=True)
