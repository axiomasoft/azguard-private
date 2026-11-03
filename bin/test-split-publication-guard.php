<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/package-evidence-guards.php';

$workflow = Yaml::parseFile(dirname(__DIR__).'/.github/workflows/split.yml');

if (! splitPublicationGuardIsFailClosed($workflow)) {
    throw new RuntimeException('split publication guard is missing or unsafe');
}
foreach (['removed', 'conditional', 'continue', 'success', 'secret', 'checkout', 'git'] as $case) {
    $bad = $workflow;
    $guard = &$bad['jobs']['split']['steps'][0];
    match ($case) {
        'removed' => array_shift($bad['jobs']['split']['steps']),
        'conditional' => $guard['if'] = '${{ false }}',
        'continue' => $guard['continue-on-error'] = true,
        'success' => $guard['run'] = str_replace('exit 1', 'exit 0', $guard['run']),
        'secret' => $guard['env'] = ['TOKEN' => '${{ secrets.MONOREPO_SPLIT_TOKEN }}'],
        'checkout' => $guard['uses'] = 'actions/checkout@'.str_repeat('a', 40),
        'git' => $guard['run'] = "git status\n".$guard['run'],
    };
    unset($guard);

    if (splitPublicationGuardIsFailClosed($bad)) {
        throw new RuntimeException('unsafe split guard accepted: '.$case);
    }
}
$root = dirname(__DIR__);
$core = Yaml::parseFile($root.'/packages/core/.github/workflows/provenance.yml');
$filament = Yaml::parseFile($root.'/packages/filament/.github/workflows/provenance.yml');

if (! guardsHold($workflow, $core, $filament)) {
    throw new RuntimeException('release evidence guard is missing');
}
foreach (['setup-removed', 'setup-duplicate', 'setup-late', 'setup-conditional', 'setup-bypassed'] as $case) {
    $bad = $workflow;
    $steps = &$bad['jobs']['split']['steps'];
    $index = null;
    foreach ($steps as $i => $step) {
        if (($step['name'] ?? null) === 'Require verified release setup') {
            $index = $i;
        }
    }

    if ($index === null) {
        throw new RuntimeException('setup fixture missing');
    }
    match ($case) {
        'setup-removed' => array_splice($steps, $index, 1),
        'setup-duplicate' => $steps[] = $steps[$index],
        'setup-late' => $steps[] = array_splice($steps, $index, 1)[0],
        'setup-conditional' => $steps[$index]['if'] = '${{ false }}',
        'setup-bypassed' => $steps[$index]['run'] = 'true',
    };
    unset($steps);

    if (guardsHold($bad, $core, $filament)) {
        throw new RuntimeException('unsafe setup guard accepted: '.$case);
    }
}
echo "Split publication guard is unconditional and precedes all workflow steps.\n";
