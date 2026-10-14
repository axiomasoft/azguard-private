<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Parity\ParityLedger;
use AzGuard\Tests\Fixtures\Parity\ParityRunner;
use AzGuard\Tests\Fixtures\Parity\ParityWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * I9 parity matrix (PLAN1 P1.3): scalar, batch and SQL visibility agree for every
 * supported decision, and directory eligibility equals write admission, over the CRM stand with expiry, NULL columns,
 * tenant ownership, global/scoped roles, tenant-wide rows, Audit on/off, two scoped panels on one storage and a panel
 * without a scope declaration (ParityWorld). The matrix compares paths with each other, never with hand-written
 * verdicts; an unsupported path is recorded, never counted as agreement, and the set is frozen in
 * tests/Fixtures/Parity/unsupported.json. An I9 disagreement is a finding for the owner: the ones the code has
 * are logged in the plan and listed by finding in tests/Fixtures/Parity/i9-findings.json, and any other one fails.
 */

beforeEach(fn () => CrmWorld::seed());

afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it(sprintf('I9 scalar batch and SQL agree on %d decision cases per seed, seeds %d-%d (PARITY_SEEDS narrows), Audit off and on', ParityWorld::decisionCount(), ParityWorld::SEEDS[0], ParityWorld::SEEDS[array_key_last(ParityWorld::SEEDS)]), function (): void {
    $ledger = new ParityLedger;

    foreach (ParityWorld::seeds() as $seed) {
        ParityWorld::write($seed);
        $cases = iterator_to_array(ParityWorld::decisionCases($seed), false);

        foreach (ParityWorld::MODULES as $module => $audit) {
            ParityWorld::compile($seed, $audit);
            $ledger->record($cases, (new ParityRunner($module))->decisions($cases));
        }
    }
    $counts = $ledger->counts();
    [$actual, $stored] = $ledger->compare('decisions');

    expect($ledger->cases())->toBe(ParityWorld::decisionCount() * count(ParityWorld::seeds()))
        ->and($ledger->disagreements())->toBe(ParityLedger::findings('decisions'))
        // Both verdicts occur among agreeing cases: the matrix is not vacuously all-deny.
        ->and($counts['allow'])->toBeGreaterThan(0)->and($counts['deny'])->toBeGreaterThan(0)
        // The frozen ledger of unsupported cases covers all seeds: a partial run (PARITY_SEEDS) leaves it to the full one.
        ->and(ParityWorld::isComplete() ? $actual : $stored)->toBe($stored);
});

it(sprintf('I9 directory eligibility equals write admission on %d assignment cases, draw seed %d, Audit off and on', ParityWorld::assignmentCount(), ParityWorld::ASSIGNMENT_SEED), function (): void {
    $ledger = new ParityLedger;
    $cases = iterator_to_array(ParityWorld::assignmentCases(), false);
    $rows = CrmWorld::storage()->table('role_grants')->orderBy('id')->get()->all();

    foreach (ParityWorld::MODULES as $module => $audit) {
        ParityWorld::compile(ParityWorld::ASSIGNMENT_SEED, $audit);
        $ledger->record($cases, (new ParityRunner($module))->assignments($cases));
    }
    $counts = $ledger->counts();
    [$actual, $stored] = $ledger->compare('assignments');

    expect($ledger->cases())->toBe(ParityWorld::assignmentCount())
        ->and($ledger->disagreements())->toBe(ParityLedger::findings('assignments'))
        ->and($counts['allow'])->toBeGreaterThan(0)->and($counts['deny'])->toBeGreaterThan(0)
        // Write admission rolled back every proposed grant.
        ->and(CrmWorld::storage()->table('role_grants')->orderBy('id')->get()->all())->toEqual($rows)
        ->and($actual)->toBe($stored);
});
