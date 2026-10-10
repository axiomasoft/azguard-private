<?php

declare(strict_types=1);

/**
 * @return array<string, string> probe id => expected owning item
 */
function regressionProbeOwners(): array
{
    return [
        'P01a' => 'PLAN2.P4.7',
        'P01b' => 'PLAN2.P4.7',
        'P01c' => 'PLAN2.P2.2',
        'P02' => 'PLAN2.P4.2',
        'P03' => 'PLAN2.P4.11',
        'P04a' => 'PLAN2.P4.12',
        'P04b' => 'PLAN2.P4.12',
        'P04c' => 'PLAN2.P4.12',
        'P05' => 'PLAN2.P2.2',
        'P06' => 'PLAN2.P4.6',
        'P06b' => 'PLAN2.P4.6',
        'P07' => 'PLAN2.P1.1',
        'P08' => 'PLAN2.P4.18',
        'P09' => 'PLAN2.P2.2',
        'P10' => 'PLAN2.P4.8',
        'P10b' => 'PLAN2.P4.8',
        'P11' => 'PLAN2.P5.4',
        'P13' => 'PLAN2.P5.2',
        'P14' => 'PLAN2.P1.2',
    ];
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

    $expected = array_keys(regressionProbeOwners());
    sort($expected);

    expect($found)->toBe($expected)->and($expected)->toHaveCount(19);
});

it('documents the format and the closing rule in the README', function (): void {
    expect(regressionRoot().'/tests/Regression/README.md')->toBeFile();
});

it('keeps every required field filled and consistent for probe', function (string $probe, string $owner): void {
    $fields = regressionSpecFields($probe);

    foreach (['Probe', 'Finding', 'Owning item', 'Scenarios', 'Status'] as $field) {
        expect($fields)->toHaveKey($field);
        expect($fields[$field])->not->toBeEmpty("{$probe}: field {$field} is empty");
    }

    foreach (['Defect in 0.3', 'Required behavior'] as $field) {
        expect($fields)->toHaveKey($field);
        expect(strlen($fields[$field]))->toBeGreaterThan(20, "{$probe}: field {$field} is too short");
    }

    expect($fields['Probe'])->toBe($probe)
        ->and($fields['Finding'])->toMatch('/^[NC]\d{2}(, [NC]\d{2})*$/')
        ->and($fields['Owning item'])->toMatch('/^PLAN2\.P\d+\.\d+$/')
        ->and($fields['Owning item'])->toBe($owner);
})->with(fn (): array => array_map(
    static fn (string $probe, string $owner): array => [$probe, $owner],
    array_keys(regressionProbeOwners()),
    array_values(regressionProbeOwners()),
));

it('references scenario ids that exist in the verification matrix for probe', function (string $probe): void {
    $verification = (string) file_get_contents(regressionRoot().'/audits/2026-09-29-audit/opus/14-verification.md');
    $scenarios = array_map('trim', explode(',', regressionSpecFields($probe)['Scenarios']));

    expect($scenarios)->not->toBeEmpty();

    foreach ($scenarios as $scenario) {
        expect($scenario)->toMatch('/^V\d{2,3}$/')
            ->and(preg_match('/\b'.$scenario.'\b/', $verification))->toBe(1);
    }
})->with(array_keys(regressionProbeOwners()));

it('points the defect description at an existing 0.3 probe test for probe', function (string $probe): void {
    $defect = regressionSpecFields($probe)['Defect in 0.3'];

    expect(preg_match('#`(audits/[^`]+/evidence/probes/[A-Za-z]+\.php)`#', $defect, $file))->toBe(1);
    expect(regressionRoot().'/'.$file[1])->toBeFile();
    expect((string) file_get_contents(regressionRoot().'/'.$file[1]))->toContain("it('{$probe}:");
})->with(array_keys(regressionProbeOwners()));

it('accepts only pending or an existing covering test as status for probe', function (string $probe): void {
    $status = regressionSpecFields($probe)['Status'];

    if ($status === 'pending') {
        expect($status)->toBe('pending');

        return;
    }

    expect(preg_match('/^covered: (\S+)::(.+)$/', $status, $covered))->toBe(1);
    expect(regressionRoot().'/'.$covered[1])->toBeFile();
    expect((string) file_get_contents(regressionRoot().'/'.$covered[1]))->toContain($covered[2]);
})->with(array_keys(regressionProbeOwners()));
