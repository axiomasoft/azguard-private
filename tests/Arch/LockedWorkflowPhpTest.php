<?php

declare(strict_types=1);

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Symfony\Component\Yaml\Yaml;

/** @return list<string> */
function lockedWorkflowPhpViolations(array $workflow, array $lock): array
{
    $parser = new VersionParser;
    $violations = [];
    foreach ($workflow['jobs'] ?? [] as $id => $job) {
        $php = null;
        foreach ($job['steps'] ?? [] as $step) {
            if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
                $php = (string) ($step['with']['php-version'] ?? '');
            }

            if (! preg_match('/\bcomposer\s+install\b/', $step['run'] ?? '') || isset($step['working-directory'])) {
                continue;
            }

            if ($php === null || ! preg_match('/^\d+\.\d+(?:\.\d+)?$/', $php)) {
                $violations[] = $id.': root lock install has no concrete PHP version';

                continue;
            }
            // setup-php resolves a minor to a current patch; the minor must admit the locked graph.
            $runtime = $parser->parseConstraints(substr_count($php, '.') === 1 ? '~'.$php.'.0' : $php);
            $packages = $lock['packages'] ?? [];

            if (! str_contains($step['run'], '--no-dev')) {
                array_push($packages, ...($lock['packages-dev'] ?? []));
            }
            foreach ($packages as $package) {
                $required = $package['require']['php'] ?? '*';

                if (! Intervals::haveIntersections($runtime, $parser->parseConstraints($required))) {
                    $violations[] = $id.': PHP '.$php.' cannot install '.$package['name'].' ('.$required.')';
                }
            }
        }
    }

    return $violations;
}

it('uses a PHP runtime compatible with the root lock in every locked workflow job', function (): void {
    $root = dirname(__DIR__, 2);
    $lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $violations = [];
    foreach (glob($root.'/.github/workflows/*.yml') as $file) {
        foreach (lockedWorkflowPhpViolations(Yaml::parseFile($file), $lock) as $violation) {
            $violations[] = basename($file).': '.$violation;
        }
    }
    expect($violations)->toBe([]);
});

it('rejects a locked install on an older PHP even when the root manifest allows it', function (): void {
    $workflow = ['jobs' => ['locked' => ['steps' => [
        ['uses' => 'shivammathur/setup-php@fixture', 'with' => ['php-version' => '8.3']],
        ['run' => 'composer install --no-dev --no-interaction'],
    ]]]];
    $lock = ['packages' => [['name' => 'fixture/runtime', 'require' => ['php' => '>=8.4.1']]]];
    expect(lockedWorkflowPhpViolations($workflow, $lock))->toBe(['locked: PHP 8.3 cannot install fixture/runtime (>=8.4.1)']);
    $workflow['jobs']['locked']['steps'][0]['with']['php-version'] = '8.4';
    expect(lockedWorkflowPhpViolations($workflow, $lock))->toBe([]);
});

it('checks development dependencies only for installs that include them', function (): void {
    $workflow = ['jobs' => ['locked' => ['steps' => [
        ['uses' => 'shivammathur/setup-php@fixture', 'with' => ['php-version' => '8.3']],
        ['run' => 'composer install'],
    ]]]];
    $lock = ['packages-dev' => [['name' => 'fixture/dev', 'require' => ['php' => '^8.4']]]];
    expect(lockedWorkflowPhpViolations($workflow, $lock))->toBe(['locked: PHP 8.3 cannot install fixture/dev (^8.4)']);
    $workflow['jobs']['locked']['steps'][1]['run'] .= ' --no-dev';
    expect(lockedWorkflowPhpViolations($workflow, $lock))->toBe([]);
});
