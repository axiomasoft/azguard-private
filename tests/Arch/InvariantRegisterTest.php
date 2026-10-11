<?php

declare(strict_types=1);

/*
 * The invariant register (PLAN1 P1.4, phase exit criterion P1#exit): tests/Arch/invariants.json has one entry per
 * invariant I1–I22 of knowledge/architecture#invariants, and every entry either names executable checks that exist or
 * names the phase that builds them. Nothing in the register is a check that does not run.
 *
 * Fields: `status` — `exists` when the table gives a check present at 0.7.0 that keeps running (also when a later
 * phase adds to it), `build` when the check is created by a phase; `checks` — repository paths of the suites, fixtures
 * and workflows that prove the invariant today; `blocking` — `now`, or `after Pn`: the point from which the complete
 * check set fails the build (a scan in report mode fails on a new violation already, with an allowlist that empties by
 * Pn); `owner_phase` — the last phase the status cell of the table names (null when it names none).
 */

/** @return array<string, array{status: string, owner_phase: string|null}> the invariants table of the plan, by id */
function invariantTable(): array
{
    $root = dirname(__DIR__, 2);
    $documents = array_merge(
        glob($root.'/plans/*/knowledge/architecture.json') ?: [],
        glob($root.'/plans/archive/*/knowledge/architecture.json') ?: [],
    );
    $table = [];

    foreach ($documents as $document) {
        $architecture = json_decode((string) file_get_contents($document), true, flags: JSON_THROW_ON_ERROR);

        foreach ($architecture['sections'] ?? [] as $section) {
            if (($section['id'] ?? null) !== 'invariants') {
                continue;
            }

            foreach (explode("\n", (string) $section['text']) as $line) {
                if (preg_match('/\A\|\s*(I\d+)\s*\|.*\|\s*([^|]+?)\s*\|\z/', $line, $row) !== 1) {
                    continue;
                }
                $cell = $row[2];
                $table[$row[1]] = [
                    'status' => str_starts_with($cell, 'Build') ? 'build' : 'exists',
                    'owner_phase' => preg_match_all('/P\d+/', $cell, $phases) > 0 ? end($phases[0]) : null,
                ];
            }
        }
    }

    return $table;
}

/** @return list<array<string, mixed>> */
function invariantRegister(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/invariants.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('registers every invariant I1–I22 once, in order', function (): void {
    expect(array_column(invariantRegister(), 'id'))->toBe(array_map(static fn (int $n): string => 'I'.$n, range(1, 22)));
});

it('keeps each entry well formed', function (): void {
    foreach (invariantRegister() as $entry) {
        expect($entry)->toHaveKeys(['id', 'status', 'checks', 'blocking', 'owner_phase'], $entry['id'].' is incomplete')
            ->and(array_keys($entry))->toBe(['id', 'status', 'checks', 'blocking', 'owner_phase'])
            ->and($entry['status'])->toBeIn(['exists', 'build'])
            ->and($entry['blocking'])->toMatch('/\A(?:now|after P[1-4])\z/')
            ->and($entry['owner_phase'])->toBeIn([null, 'P1', 'P2', 'P3', 'P4'])
            ->and(array_unique($entry['checks']))->toBe($entry['checks'], $entry['id'].' lists a check twice');
    }
});

it('lists only checks that exist', function (): void {
    $missing = [];

    foreach (invariantRegister() as $entry) {
        foreach ($entry['checks'] as $check) {
            if (! is_file(dirname(__DIR__, 2).'/'.$check)) {
                $missing[] = $entry['id'].': '.$check;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('names an owner phase for every invariant without a check, and invents no check for it', function (): void {
    $without = [];

    foreach (invariantRegister() as $entry) {
        if ($entry['checks'] === []) {
            $without[] = $entry['id'];
            expect($entry['owner_phase'])->toBeIn(['P2', 'P3', 'P4'], $entry['id'].' has no check and no phase that builds it')
                ->and($entry['blocking'])->toBe('after '.$entry['owner_phase']);
        }
    }

    // I4 and I20 are built by P2, I18 and I22 by P4: no check of them exists in P1.
    expect($without)->toBe(['I4', 'I18', 'I20', 'I22']);
});

it('states for every build entry with a check the phase from which it blocks', function (): void {
    foreach (invariantRegister() as $entry) {
        if ($entry['status'] === 'build' && $entry['checks'] !== []) {
            expect($entry['owner_phase'])->not->toBeNull($entry['id'].' is built by no phase')
                ->and($entry['blocking'])->toMatch('/\A(?:now|after P[1-4])\z/');
        }
    }
});

it('agrees with the invariants table of the architecture knowledge', function (): void {
    $table = invariantTable();
    $register = array_column(invariantRegister(), null, 'id');

    expect(array_keys($table))->toBe(array_keys($register), 'the plan holds no invariants table: its knowledge/architecture.json is the source of the register');

    foreach ($table as $id => $expected) {
        expect($register[$id]['status'])->toBe($expected['status'], $id.' status disagrees with the table')
            ->and($register[$id]['owner_phase'])->toBe($expected['owner_phase'], $id.' owner phase disagrees with the table');
    }
});

it('keeps each allowlist inside the phase from which its invariant blocks', function (): void {
    $lists = [
        'I2' => __DIR__.'/Allowlists/i2.json', 'I3' => __DIR__.'/Allowlists/i3.json', 'I5' => __DIR__.'/Allowlists/i5.json',
        'I13' => dirname(__DIR__).'/Fixtures/InactiveCost/allowlist.json', 'I7' => dirname(__DIR__).'/Fixtures/Parity/i7-allowlist.json',
    ];
    $register = array_column(invariantRegister(), null, 'id');

    foreach ($lists as $id => $file) {
        $entries = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        foreach ($entries as $entry) {
            expect($register[$id]['blocking'])->toMatch('/\Aafter P\d\z/', $id.' has an allowlist and must name the phase it empties by');
            expect((int) substr($entry['owner_phase'], 1))->toBeLessThanOrEqual((int) substr($register[$id]['blocking'], -1), $id.' lists a violation owned later than the phase it blocks from');
        }
    }

});
