<?php

declare(strict_types=1);

/*
 * The versions the documentation promises equal the constraints of the package: the PHP floor and the Laravel majors in
 * the README and the getting-started pages are read from packages/core/composer.json.
 */

/** @return array{php: string, laravel: list<int>} */
function documentedConstraints(): array
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    preg_match('/\^(\d+\.\d+)/', $composer['require']['php'], $php);
    $laravel = [];

    foreach ($composer['require'] as $package => $constraint) {
        if (str_starts_with($package, 'illuminate/')) {
            preg_match_all('/\^(\d+)\.0/', $constraint, $majors);
            $laravel[$package] = array_map(intval(...), $majors[1]);
        }
    }
    $sets = array_unique(array_map(static fn (array $majors): string => implode(',', $majors), $laravel));

    return ['php' => $php[1], 'laravel' => array_map(intval(...), explode(',', (string) array_values($sets)[0]))];
}

it('declares one Laravel range for every illuminate package of the core', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $ranges = [];

    foreach ($composer['require'] as $package => $constraint) {
        if (str_starts_with($package, 'illuminate/')) {
            $ranges[$constraint][] = $package;
        }
    }

    expect(array_keys($ranges))->toHaveCount(1)->and(documentedConstraints()['laravel'])->toBe([12, 13]);
});

it('documents the supported Laravel majors and the PHP floor of the package', function (): void {
    $constraints = documentedConstraints();
    $root = dirname(__DIR__, 2);
    $laravel = implode(' or ', $constraints['laravel']);
    $range = $constraints['laravel'][0].'–'.$constraints['laravel'][array_key_last($constraints['laravel'])];

    $readme = (string) file_get_contents($root.'/README.md');
    $introduction = (string) file_get_contents($root.'/docs/getting-started/introduction.md');

    expect($readme)->toContain("Laravel {$range}")->toContain("Laravel {$laravel}")->toContain('PHP '.$constraints['php'].'–')
        ->and($introduction)->toContain('| Laravel | '.implode(', ', $constraints['laravel']).' |')->toContain('| PHP | '.$constraints['php'].',');
});

it('mentions no Laravel major outside the supported range in the README and the getting-started pages', function (): void {
    $supported = documentedConstraints()['laravel'];
    $root = dirname(__DIR__, 2);
    $files = array_merge([$root.'/README.md'], glob($root.'/docs/getting-started/*.md') ?: []);
    $found = [];

    foreach ($files as $file) {
        preg_match_all('/Laravel (\d{2})\b/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $major) {
            if (! in_array((int) $major, $supported, true)) {
                $found[basename($file)][] = (int) $major;
            }
        }
    }

    expect($found)->toBe([]);
});
