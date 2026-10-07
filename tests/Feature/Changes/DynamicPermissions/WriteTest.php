<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Changes\PermissionRecord;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Carbon;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('creates a dynamic permission with one Created effect, one bump and a committed state', function (): void {
    $panel = W::dynamicPanel();
    $before = W::version();
    $result = W::createAction($panel, 'campaigns.view', label: 'Просмотр кампаний', group: 'Кампании', description: 'Только чтение');
    $effect = $result->effects[0];

    expect($result->status)->toBe(ChangeStatus::Applied)
        ->and($result->committed)->toBeTrue()
        ->and($result->correlationId)->toMatch('/\A[0-9a-z]{26}\z/')
        ->and($result->record)->toBeInstanceOf(PermissionRecord::class)
        ->and($result->records)->toBe([$result->record])
        ->and($result->record?->id)->toMatch('/\Aaction:[1-9][0-9]*\z/')
        ->and($result->record?->panel)->toBe('crm')
        ->and($result->record?->tenant)->toEqual(TenantRef::of('crm.organization', 1))
        ->and($result->record?->key->full())->toBe('crm:campaigns.view')
        ->and($result->record?->label)->toBe('Просмотр кампаний')
        ->and($result->record?->group)->toBe('Кампании')
        ->and($result->record?->description)->toBe('Только чтение')
        ->and($result->record?->fields)->toBe([])
        ->and($result->record?->createdAt?->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:00:00')
        ->and($result->record?->updatedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:00:00')
        ->and($effect->kind)->toBe(EffectKind::Created)
        ->and($effect->type)->toBe(ChangeType::CreatePermission)
        ->and($effect->before)->toBeNull()
        ->and($effect->after)->toBe($result->record)
        ->and($effect->eventId)->toMatch('/\A[0-9a-z]{26}\z/')
        ->and($result->state->version)->toBe($before + 1)
        ->and(W::version())->toBe($before + 1)
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view']);
});

it('stores one row of the tenant with its details and no fields', function (): void {
    W::createAction(W::dynamicPanel(), 'campaigns.view', label: 'Просмотр', group: 'Кампании');
    $row = W::actions()[0];

    expect($row['panel'])->toBe('crm')
        ->and([$row['tenant_key'], $row['tenant_type'], $row['tenant_id']])->toBe(['crm.organization:1', 'crm.organization', '1'])
        ->and([$row['name'], $row['label'], $row['group'], $row['description']])->toBe(['campaigns.view', 'Просмотр', 'Кампании', null])
        ->and($row['meta'])->toBeNull();
});

it('puts the new permission into the tenant catalog as a Grants permission and into no other tenant', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view', label: 'Просмотр');
    CrmWorld::storage()->mutate('crm', function () use ($panel): void {
        $reads = $panel->writer()->lockedReads($panel);
        $definition = $reads->catalog(W::tenant(1))->get('campaigns.view');

        expect($definition->authority)->toBe(PermissionAuthority::Grants)
            ->and($definition->label)->toBe('Просмотр')
            ->and($reads->catalog(W::tenant(2))->has('campaigns.view'))->toBeFalse()
            ->and($reads->action(W::tenant(2), 'campaigns.view'))->toBeNull();
    });
});

it('updates label, group and description as one Updated effect and keeps identity and creation time', function (): void {
    $panel = W::dynamicPanel();
    $created = W::createAction($panel, 'campaigns.view', label: 'Просмотр', group: 'Кампании');
    Carbon::setTestNow('2026-10-06T12:30:00Z');
    $version = W::version();
    $updated = W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails('Чтение кампаний', null, 'Описание'));
    $effect = $updated->effects[0];

    expect($updated->status)->toBe(ChangeStatus::Applied)
        ->and($effect->kind)->toBe(EffectKind::Updated)
        ->and($effect->type)->toBe(ChangeType::UpdatePermission)
        ->and($effect->before)->toEqual($created->record)
        ->and($effect->after)->toBe($updated->record)
        ->and($updated->record?->id)->toBe($created->record?->id)
        ->and([$updated->record?->label, $updated->record?->group, $updated->record?->description])->toBe(['Чтение кампаний', null, 'Описание'])
        ->and($updated->record?->createdAt?->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:00:00')
        ->and($updated->record?->updatedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:30:00')
        ->and(W::version())->toBe($version + 1)
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view']);
});

it('treats an update equal to the stored details as Unchanged without a write or a bump', function (): void {
    $panel = W::dynamicPanel();
    $created = W::createAction($panel, 'campaigns.view', label: 'Просмотр', group: 'Кампании');
    $version = W::version();
    $rows = W::actions();
    Carbon::setTestNow('2026-10-06T13:00:00Z');
    $again = W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails('Просмотр', 'Кампании', null));

    expect($again->status)->toBe(ChangeStatus::Unchanged)
        ->and($again->effects)->toBe([])
        ->and($again->record?->id)->toBe($created->record?->id)
        ->and($again->state->version)->toBe($version)
        ->and(W::version())->toBe($version)
        ->and(W::actions())->toBe($rows);
});

it('deletes the permission row with one Deleted effect carrying the stored record', function (): void {
    $panel = W::dynamicPanel();
    $created = W::createAction($panel, 'campaigns.view', label: 'Просмотр');
    $version = W::version();
    $deleted = W::deleteAction($panel, 'campaigns.view');
    $effect = $deleted->effects[0];

    expect($deleted->status)->toBe(ChangeStatus::Applied)
        ->and($deleted->effects)->toHaveCount(1)
        ->and($effect->kind)->toBe(EffectKind::Deleted)
        ->and($effect->type)->toBe(ChangeType::DeletePermission)
        ->and($effect->before)->toEqual($created->record)
        ->and($effect->after)->toBeNull()
        ->and($deleted->record)->toBe($effect->before)
        ->and($deleted->removedGrantIds())->toBe([])
        ->and(W::version())->toBe($version + 1)
        ->and(W::actions())->toBe([]);
});

it('allows the name again after its deletion with a new id', function (): void {
    $panel = W::dynamicPanel();
    $first = W::createAction($panel, 'campaigns.view');
    W::deleteAction($panel, 'campaigns.view');
    $second = W::createAction($panel, 'campaigns.view', label: 'Снова');

    expect($second->status)->toBe(ChangeStatus::Applied)
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view'])
        ->and($second->record?->label)->toBe('Снова')
        ->and($first->record?->label)->toBeNull();
});

it('keeps the same name in tenant A and tenant B as two independent permissions', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view', tenant: 1, label: 'A');
    W::createAction($panel, 'campaigns.view', tenant: 2, label: 'B');
    W::pipeline()->updatePermission($panel, W::tenant(2), 'campaigns.view', new PermissionDetails('B2'));
    $a = array_values(array_filter(W::actions(), fn (array $row): bool => $row['tenant_key'] === 'crm.organization:1'))[0];
    $b = array_values(array_filter(W::actions(), fn (array $row): bool => $row['tenant_key'] === 'crm.organization:2'))[0];

    expect([$a['label'], $b['label']])->toBe(['A', 'B2']);

    W::deleteAction($panel, 'campaigns.view', tenant: 1);

    expect(W::actionNames())->toBe(['crm.organization:2|campaigns.view']);
});
