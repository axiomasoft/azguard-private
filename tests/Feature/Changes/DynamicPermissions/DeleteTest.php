<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    CrmWorld::seed();
    $this->seen = [];
    $seen = &$this->seen;
    $this->panel = W::dynamicPanel([static function (Change $change, Closure $next) use (&$seen) {
        $seen[] = [$change->type->value, $change->origin, $change->scope->tenant->key(), $change->scope->context->key(),
            $change->subject?->key(), $change->permission?->local() ?? $change->name, $change->grantId];

        return $next($change);
    }]);
    W::createAction($this->panel, 'campaigns.view', label: 'A');
    W::createAction($this->panel, 'campaigns.view', tenant: 2, label: 'B');
    W::createAction($this->panel, 'campaigns.edit');
    $pipeline = W::pipeline();
    $pipeline->grant($this->panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));
    $pipeline->grant($this->panel, W::tenant(), W::user(1), W::permission('campaigns.view'), W::project(null), origin: 'import');
    $pipeline->grant($this->panel, W::tenant(), W::user(3), W::permission('campaigns.view'), W::project(5));
    $pipeline->grant($this->panel, W::tenant(), W::user(1), W::permission('campaigns.*'), W::project(null));
    $pipeline->grant($this->panel, W::tenant(), W::user(2), W::permission('campaigns.edit'), W::project(2));
    $pipeline->grant($this->panel, W::tenant(2), W::user(1), W::permission('campaigns.view'), W::project(4));
    $this->seen = [];
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

/** @return list<string> */
function dynamicGrants(): array
{
    return W::keys('permission');
}

it('removes the exact grants of the name in the tenant with every origin and context, and keeps patterns, other names and tenant B', function (): void {
    $before = W::rows('permission');
    $version = W::version();
    $result = W::deleteAction($this->panel, 'campaigns.view');

    expect(dynamicGrants())->toBe([
        'crm.organization:1|campaigns.*|1|global|manual',
        'crm.organization:1|campaigns.edit|2|crm.project:2|manual',
        'crm.organization:2|campaigns.view|1|crm.project:4|manual',
    ])
        ->and(W::actionNames())->toBe(['crm.organization:2|campaigns.view', 'crm.organization:1|campaigns.edit'])
        ->and($result->applied())->toBeTrue()
        ->and(W::version())->toBe($version + 1)
        ->and($result->state->version)->toBe($version + 1);

    $removed = array_values(array_filter($before, fn (array $row): bool => $row['tenant_key'] === 'crm.organization:1' && $row['permission'] === 'campaigns.view'));

    expect($removed)->toHaveCount(3)
        ->and($result->removedGrantIds())->toBe(array_map(fn (array $row): string => 'permission:'.$row['id'], $removed));
});

it('reports one authoritative Deleted effect per grant before the Deleted effect of the permission', function (): void {
    $result = W::deleteAction($this->panel, 'campaigns.view');

    expect(array_map(fn ($effect) => [$effect->kind, $effect->type], $result->effects))->toBe([
        [EffectKind::Deleted, ChangeType::RevokePermission], [EffectKind::Deleted, ChangeType::RevokePermission],
        [EffectKind::Deleted, ChangeType::RevokePermission], [EffectKind::Deleted, ChangeType::DeletePermission],
    ])
        ->and($result->records)->toHaveCount(4)
        ->and($result->record)->toBe($result->effects[3]->before)
        ->and($result->effects[0]->before?->permission?->local())->toBe('campaigns.view')
        ->and(array_unique(array_map(fn ($effect) => $effect->eventId, $result->effects)))->toHaveCount(4);
});

it('shows the pipes one revocation per stored row with its own scope, subject and origin, then the permission delete', function (): void {
    W::deleteAction($this->panel, 'campaigns.view');

    expect(array_map(fn (array $seen): array => array_slice($seen, 0, 6), $this->seen))->toBe([
        ['revoke_permission', 'manual', 'crm.organization:1', 'crm.project:2', 'crm.user:2', 'campaigns.view'],
        ['revoke_permission', 'import', 'crm.organization:1', 'global', 'crm.user:1', 'campaigns.view'],
        ['revoke_permission', 'manual', 'crm.organization:1', 'crm.project:5', 'crm.user:3', 'campaigns.view'],
        ['delete_permission', 'dynamic', 'crm.organization:1', 'global', null, 'campaigns.view'],
    ])
        ->and(array_map(fn (array $seen): ?string => $seen[6], $this->seen))->toMatchArray([0 => 'permission:1', 1 => 'permission:2', 2 => 'permission:3', 3 => null]);
});

it('rolls back the grants, the permission and the version when a pipe cancels one revocation', function (int $cancelled): void {
    $panel = W::dynamicPanel([static function (Change $change, Closure $next) use ($cancelled) {
        static $count = 0;

        if ($change->type === ChangeType::RevokePermission && ++$count === $cancelled) {
            $change->cancel('keep it');
        }

        return $next($change);
    }]);
    $before = [W::actions(), W::rows('permission'), W::version()];

    expect(fn () => W::deleteAction($panel, 'campaigns.view'))->toThrow(ChangeCancelledException::class, 'keep it')
        ->and([W::actions(), W::rows('permission'), W::version()])->toBe($before);
})->with(['first revocation' => [1], 'last revocation' => [3]]);

it('deletes grants only through per-row statements by id, never by a name predicate', function (): void {
    $deletes = [];
    CrmWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$deletes): void {
        if (preg_match('/\Adelete from "?azg_permission_grants"?/i', $event->sql) === 1) {
            $deletes[] = [$event->sql, $event->bindings];
        }
    });
    W::deleteAction($this->panel, 'campaigns.view');

    expect($deletes)->toHaveCount(3)
        ->and(array_map(fn (array $delete): int => count($delete[1]), $deletes))->toBe([2, 2, 2])
        ->and(array_map(fn (array $delete): bool => str_contains($delete[0], '"id"') || str_contains($delete[0], '`id`'), $deletes))->toBe([true, true, true]);
});

it('deletes a permission without grants as a single Deleted effect', function (): void {
    $result = W::deleteAction($this->panel, 'campaigns.edit');

    expect($result->effects)->toHaveCount(2)
        ->and(array_map(fn ($effect) => $effect->type, $result->effects))->toBe([ChangeType::RevokePermission, ChangeType::DeletePermission]);

    $plain = W::deleteAction($this->panel, 'campaigns.view', tenant: 2);

    expect($plain->effects)->toHaveCount(2)
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view']);
});

it('keeps the grant of a deleted name from reappearing: the same name created again starts with no grants', function (): void {
    W::deleteAction($this->panel, 'campaigns.view');
    W::createAction($this->panel, 'campaigns.view', label: 'Новое');

    expect(array_values(array_filter(dynamicGrants(), fn (string $key): bool => str_starts_with($key, 'crm.organization:1|campaigns.view'))))->toBe([]);
});
