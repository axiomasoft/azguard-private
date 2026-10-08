<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('lists every stored grant of the subject in the tenant, in every origin, expired ones marked', function (): void {
    $panel = W::panel();
    W::grant($panel, 'auditor', 1, null, origin: 'import');
    W::grant($panel, 'seller', 1, 5, until: new DateTimeImmutable('2026-10-06T12:30:00Z'), fields: ['region' => 'R2']);
    Carbon::setTestNow('2026-10-06T13:00:00Z');

    expect(Artisan::call('azguard:grants:list', ['subject' => 'crm.user:1', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--json' => true]))->toBe(0);
    $list = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $grants = array_map(static fn (array $grant): string => implode('|', [$grant['kind'], $grant['key'], $grant['context'], $grant['origin'], $grant['expired'] ? 'expired' : 'active']), $list['grants']);
    sort($grants);

    // Anna's analyst grant on P4 is in tenant B and is not listed.
    expect($list)->toMatchArray(['panel' => 'crm', 'tenant' => 'crm.organization:1', 'subject' => 'crm.user:1'])
        ->and($grants)->toBe([
            'role|analyst|crm.project:2|manual|active',
            'role|auditor|global|import|active',
            'role|seller|crm.project:1|manual|active',
            'role|seller|crm.project:5|manual|expired',
        ])
        ->and(array_values(array_filter($list['grants'], static fn (array $grant): bool => $grant['context'] === 'crm.project:5'))[0])
        ->toMatchArray(['until' => '2026-10-06T12:30:00+00:00', 'fields' => ['region' => 'R2', 'eligible' => null]]);
});

it('prints grants as a table and an empty list as a message', function (): void {
    W::panel();

    expect(Artisan::call('azguard:grants:list', ['subject' => 'crm.user:1', '--panel' => 'crm', '--tenant' => 'crm.organization:2']))->toBe(0)
        ->and(Artisan::output())->toContain('analyst', 'crm.project:4', 'never')
        ->and(Artisan::call('azguard:grants:list', ['subject' => 'crm.user:4', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(Artisan::output())->toContain('has no stored grants');
});
