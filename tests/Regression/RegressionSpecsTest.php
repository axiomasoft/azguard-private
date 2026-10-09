<?php

declare(strict_types=1);

/**
 * @return list<string> ids of the 0.3 probes that have a regression on the 1.0 API
 */
function regressionProbes(): array
{
    return ['P01a', 'P01b', 'P01c', 'P02', 'P03', 'P04a', 'P04b', 'P04c', 'P05', 'P06', 'P06b', 'P07', 'P08', 'P09', 'P10', 'P10b', 'P11', 'P13', 'P14'];
}

function regressionRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, string> field name => value (first occurrence per field)
 */
function regressionSpecFields(string $probe): array
{
    $path = regressionRoot().'/tests/Regression/specs/'.$probe.'.md';
    expect($path)->toBeFile();

    preg_match_all('/^\*\*([A-Za-z0-9. ]+):\*\*\s*(.+)$/m', (string) file_get_contents($path), $matches, PREG_SET_ORDER);

    $fields = [];
    foreach ($matches as $match) {
        $fields[$match[1]] ??= trim($match[2]);
    }

    return $fields;
}

it('has a spec file for exactly the 19 probes', function (): void {
    $found = array_map(
        static fn (string $path): string => basename($path, '.md'),
        glob(regressionRoot().'/tests/Regression/specs/*.md') ?: [],
    );
    sort($found);

    $expected = regressionProbes();
    sort($expected);

    expect($found)->toBe($expected)->and($expected)->toHaveCount(19);
});

it('documents the format and the closing rule in the README', function (): void {
    expect(regressionRoot().'/tests/Regression/README.md')->toBeFile();
});

it('keeps every required field filled and consistent for probe', function (string $probe): void {
    $fields = regressionSpecFields($probe);

    foreach (['Probe', 'Finding', 'Scenarios', 'Status'] as $field) {
        expect($fields)->toHaveKey($field);
        expect($fields[$field])->not->toBeEmpty("{$probe}: field {$field} is empty");
    }

    foreach (['Defect in 0.3', 'Required behavior'] as $field) {
        expect($fields)->toHaveKey($field);
        expect(strlen($fields[$field]))->toBeGreaterThan(20, "{$probe}: field {$field} is too short");
    }

    expect($fields['Probe'])->toBe($probe)
        ->and($fields['Finding'])->toMatch('/^[NC]\d{2}(, [NC]\d{2})*$/');
})->with(regressionProbes());

it('references scenario ids that exist in the verification matrix for probe', function (string $probe): void {
    $verification = (string) file_get_contents(regressionRoot().'/audits/2026-09-29-audit/opus/14-verification.md');
    $scenarios = array_map('trim', explode(',', regressionSpecFields($probe)['Scenarios']));

    expect($scenarios)->not->toBeEmpty();

    foreach ($scenarios as $scenario) {
        expect($scenario)->toMatch('/^V\d{2,3}$/')
            ->and(preg_match('/\b'.$scenario.'\b/', $verification))->toBe(1);
    }
})->with(regressionProbes());

it('points the defect description at an existing 0.3 probe test for probe', function (string $probe): void {
    $defect = regressionSpecFields($probe)['Defect in 0.3'];

    expect(preg_match('#`(audits/[^`]+/evidence/probes/[A-Za-z]+\.php)`#', $defect, $file))->toBe(1);
    expect(regressionRoot().'/'.$file[1])->toBeFile();
    expect((string) file_get_contents(regressionRoot().'/'.$file[1]))->toContain("it('{$probe}:");
})->with(regressionProbes());

it('accepts only pending or an existing covering test as status for probe', function (string $probe): void {
    $status = regressionSpecFields($probe)['Status'];

    if ($status === 'pending') {
        expect($status)->toBe('pending');

        return;
    }

    expect(preg_match('/^covered: (\S+)::(.+)$/', $status, $covered))->toBe(1);
    expect(regressionRoot().'/'.$covered[1])->toBeFile();
    expect((string) file_get_contents(regressionRoot().'/'.$covered[1]))->toContain($covered[2]);
})->with(regressionProbes());
