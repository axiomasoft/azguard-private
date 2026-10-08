<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantPage;
use AzGuard\Changes\GrantRecord;
use AzGuard\Changes\PanelManagers;
use AzGuard\Changes\ScopedGrantManager;
use AzGuard\Changes\ScopedPermissionManager;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Support\Carbon;

/** @return list<string> ids of a page */
function managerIds(GrantPage $page): array
{
    return array_map(fn (GrantRecord $record): string => $record->id, $page->items);
}

/** @return list<string> ids of every page of a filter, following the cursors */
function managerAllIds(GrantManager $grants, GrantFilter $filter): array
{
    $ids = [];
    do {
        $page = $grants->page($filter);
        array_push($ids, ...managerIds($page));
        $filter = $filter->after($page->nextCursor);
    } while ($page->nextCursor !== null);

    return $ids;
}

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
    $this->a = W::grant($this->panel, 'auditor', 2, null)->record?->id;
    $this->import = W::grant($this->panel, 'auditor', 2, null, origin: 'import')->record?->id;
    $this->b = W::grant($this->panel, 'seller', 1, 4, 2)->record?->id;
    $this->permission = W::pipeline()->grant($this->panel, W::tenant(), W::user(2), W::permission('clients.*'), W::project(2))->record?->id;
    $this->grants = M::managers($this->panel)->grants();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('R37 finds only grants of its own panel, tenant and origin', function (): void {
    expect($this->grants->find($this->a)?->role?->key())->toBe('auditor')
        ->and($this->grants->find($this->permission)?->permission?->local())->toBe('clients.*')
        ->and($this->grants->find($this->b))->toBeNull()
        ->and($this->grants->find($this->import))->toBeNull()
        ->and(M::managers($this->panel, origin: 'import')->grants()->find($this->import)?->origin)->toBe('import')
        ->and(M::managers($this->panel, 2)->grants()->find($this->b)?->scope->tenant->key())->toBe('crm.organization:2')
        ->and($this->grants->find('role:999999'))->toBeNull()
        ->and($this->grants->find('role:0'))->toBeNull()
        ->and($this->grants->find('1'))->toBeNull()
        ->and($this->grants->find("role:1' or 1=1"))->toBeNull();
});

it('R37 refuses an update of a foreign id as a stale selection and writes nothing', function (): void {
    $rows = [W::rows(), W::version()];

    expect(fn () => $this->grants->update($this->b, new GrantDetails(null, ['region' => 'R1'])))->toThrow(StaleSelectionException::class)
        ->and(fn () => $this->grants->update($this->import, new GrantDetails(null, ['region' => 'R1'])))->toThrow(StaleSelectionException::class)
        ->and([W::rows(), W::version()])->toBe($rows);
});

it('C13 updates expiry and fields only while the form fingerprint still matches; identity never changes', function (): void {
    $read = $this->grants->find($this->a);
    Carbon::setTestNow('2026-10-06T12:00:05Z');
    $until = new DateTimeImmutable('2027-03-01T10:00:00Z');
    $result = $this->grants->update($this->a, new GrantDetails($until, ['eligible' => true]), $read?->fingerprint);
    $after = $this->grants->find($this->a);

    expect($result->status)->toBe(ChangeStatus::Applied)
        ->and($result->effects[0]->type)->toBe(ChangeType::UpdateGrant)
        ->and($after?->until)->toEqual($until)->and($after?->fields)->toMatchArray(['eligible' => true])
        ->and([$after?->id, $after?->role?->key(), $after?->subject->key(), $after?->scope->context->key(), $after?->origin])
        ->toBe([$read?->id, 'auditor', 'crm.user:2', $read?->scope->context->key(), 'manual'])
        ->and(fn () => $this->grants->update($this->a, new GrantDetails(null, []), $read?->fingerprint))->toThrow(StaleSelectionException::class)
        ->and($this->grants->update($this->a, new GrantDetails($until, ['eligible' => true]), $after?->fingerprint)->status)->toBe(ChangeStatus::Unchanged);
});

it('R15 validates an update again as an assignment: an inactive project refuses it', function (): void {
    $id = W::grant($this->panel, 'seller', 1, 5)->record?->id ?? '';
    Project::query()->whereKey(5)->update(['is_active' => false]);
    $rows = W::rows();

    expect(fn () => $this->grants->update($id, new GrantDetails(null, ['region' => 'R2'])))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(W::rows())->toBe($rows);
});

it('R37 revokes many ids in one mutation and refuses the whole operation for one foreign id', function (): void {
    $version = W::version();
    $rows = W::rows();

    expect(fn () => $this->grants->revokeMany([$this->a, $this->b]))->toThrow(StaleSelectionException::class)
        ->and(fn () => $this->grants->revokeMany([$this->a, 'role:999999']))->toThrow(StaleSelectionException::class)
        ->and(fn () => $this->grants->revokeMany([$this->a, 7]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->grants->revokeMany(['x' => $this->a]))->toThrow(InvalidArgumentException::class)
        ->and([W::rows(), W::version()])->toBe([$rows, $version]);

    $result = $this->grants->revokeMany([$this->a, $this->permission, $this->a]);

    expect(array_map(fn ($effect): array => [$effect->kind, $effect->type, $effect->before?->id], $result->effects))->toBe([
        [EffectKind::Deleted, ChangeType::RevokeRole, $this->a], [EffectKind::Deleted, ChangeType::RevokePermission, $this->permission],
    ])
        ->and(W::version())->toBe($version + 1)
        ->and($this->grants->find($this->a))->toBeNull()
        ->and(M::managers($this->panel, origin: 'import')->grants()->find($this->import))->not->toBeNull()
        ->and(M::managers($this->panel, 2)->grants()->find($this->b))->not->toBeNull()
        ->and($this->grants->revokeMany([])->status)->toBe(ChangeStatus::Unchanged);
});

it('runs every revocation of a bulk through the pipes, and a cancel keeps every grant', function (): void {
    $seen = [];
    $panel = W::panel([function (Change $change, Closure $next) use (&$seen) {
        $seen[] = [$change->type, $change->grantId, $change->context()->phase->value];

        return $change->grantId === $this->permission ? $change->cancel('kept by policy') : $next($change);
    }]);
    $rows = W::rows();

    expect(fn () => M::managers($panel)->grants()->revokeMany([$this->a, $this->permission]))->toThrow(ChangeCancelledException::class)
        ->and($seen)->toBe([[ChangeType::RevokeRole, $this->a, 'revocation'], [ChangeType::RevokePermission, $this->permission, 'revocation']])
        ->and(W::rows())->toBe($rows);
});

it('pages the partition by keyset, role grants first, and follows the cursor to the end', function (): void {
    foreach ([3, 1] as $user) {
        W::grant($this->panel, 'auditor', $user, null);
    }
    $all = managerIds($this->grants->page(new GrantFilter(limit: 500)));
    $paged = managerAllIds($this->grants, new GrantFilter(limit: 2));
    $roles = array_values(array_filter($all, fn (string $id): bool => str_starts_with($id, 'role:')));

    expect($paged)->toBe($all)
        ->and($all)->toBe([...$roles, ...array_values(array_diff($all, $roles))])
        ->and(count($all))->toBe(count(array_filter(W::rows(), fn (array $row): bool => $row['tenant_key'] === 'crm.organization:1' && $row['origin'] === 'manual'))
            + count(array_filter(W::rows('permission'), fn (array $row): bool => $row['tenant_key'] === 'crm.organization:1' && $row['origin'] === 'manual')))
        ->and($all)->not->toContain($this->b, $this->import)
        ->and($this->grants->page(new GrantFilter(limit: count($all)))->nextCursor)->toBeNull();
});

it('filters by kind, subject, context, role and permission without leaving the partition', function (): void {
    $grants = $this->grants;

    expect(managerIds($grants->page(new GrantFilter(kind: 'permission'))))->toBe([$this->permission])
        ->and(managerIds($grants->page(new GrantFilter(permission: W::permission('clients.*')))))->toBe([$this->permission])
        ->and(managerIds($grants->page(new GrantFilter(permission: W::permission('clients.view')))))->toBe([])
        ->and(managerIds($grants->page(new GrantFilter(role: W::role('auditor')))))->toBe([$this->a])
        ->and(managerIds($grants->page(new GrantFilter(subject: W::user(2), context: W::project()))))->toBe([$this->a])
        ->and(managerIds($grants->page(new GrantFilter(subject: W::user(2), context: AnyAssignmentScope::all()))))->toHaveCount(3)
        ->and(managerIds($grants->page(new GrantFilter(context: W::project(4)))))->toBe([])
        ->and(managerIds(M::managers($this->panel, 2)->grants()->page(new GrantFilter(role: W::role('seller')))))->toContain($this->b);
});

it('lists expired grants apart from active ones', function (): void {
    Carbon::setTestNow('2026-10-06T12:00:00Z');
    $soon = W::grant($this->panel, 'auditor', 1, null, until: new DateTimeImmutable('2026-10-06T13:00:00Z'))->record?->id;
    Carbon::setTestNow('2026-10-06T14:00:00Z');

    expect(managerIds($this->grants->page(new GrantFilter(state: GrantFilter::EXPIRED))))->toBe([$soon])
        ->and(managerIds($this->grants->page(new GrantFilter(state: GrantFilter::ACTIVE))))->toContain($this->a)->not->toContain($soon)
        ->and($this->grants->find($soon ?? '')?->until)->toEqual(new DateTimeImmutable('2026-10-06T13:00:00Z'));
});

it('filters by an expiry before a moment and by the actor who granted', function (): void {
    $admin = ActorRef::of('crm.user', 9);
    $soon = W::grant($this->panel, 'auditor', 1, null, until: new DateTimeImmutable('2026-11-01T10:00:00Z'), actor: $admin)->record?->id;
    $later = W::grant($this->panel, 'auditor', 3, null, until: new DateTimeImmutable('2027-01-01T10:00:00Z'))->record?->id;
    $system = W::grant($this->panel, 'seller', 1, 5, actor: ActorRef::system('import job'))->record?->id;
    $moscow = new DateTimeImmutable('2026-11-01T13:00:01', new DateTimeZone('Europe/Moscow'));

    expect(managerIds($this->grants->page(new GrantFilter(expiresBefore: new DateTimeImmutable('2026-12-01T00:00:00Z')))))->toBe([$soon])
        ->and(managerIds($this->grants->page(new GrantFilter(expiresBefore: new DateTimeImmutable('2026-11-01T10:00:00Z')))))->toBe([])
        ->and(managerIds($this->grants->page(new GrantFilter(expiresBefore: $moscow))))->toBe([$soon])
        ->and(managerIds($this->grants->page(new GrantFilter(expiresBefore: new DateTimeImmutable('2028-01-01T00:00:00Z')))))->toBe([$soon, $later])
        ->and(managerIds($this->grants->page(new GrantFilter(grantedBy: $admin))))->toBe([$soon])
        ->and(managerIds($this->grants->page(new GrantFilter(grantedBy: ActorRef::of('crm.user', '9')))))->toBe([$soon])
        ->and(managerIds($this->grants->page(new GrantFilter(grantedBy: ActorRef::of('crm.user', 8)))))->toBe([])
        ->and(managerIds($this->grants->page(new GrantFilter(grantedBy: ActorRef::system()))))->toContain($system)->not->toContain($soon, $later)
        ->and(managerIds(M::managers($this->panel, 2)->grants()->page(new GrantFilter(grantedBy: $admin))))->toBe([]);
});

it('binds a cursor to the expiry and actor conditions of the filter and keeps them on the next page', function (): void {
    $admin = ActorRef::of('crm.user', 9);
    $ids = [];
    foreach ([['auditor', 1, null], ['auditor', 3, null], ['seller', 1, 5]] as [$role, $user, $project]) {
        $ids[] = W::grant($this->panel, $role, $user, $project, until: new DateTimeImmutable('2026-11-01T10:00:00Z'), actor: $admin)->record?->id;
    }
    $filter = new GrantFilter(limit: 2, expiresBefore: new DateTimeImmutable('2026-12-01T00:00:00Z'), grantedBy: $admin);
    $page = $this->grants->page($filter);

    expect(managerAllIds($this->grants, $filter))->toBe($ids)
        ->and($filter->after('x')->expiresBefore)->toBe($filter->expiresBefore)
        ->and($filter->after('x')->grantedBy)->toBe($admin)
        ->and(fn () => $this->grants->page(new GrantFilter(limit: 2, cursor: $page->nextCursor, grantedBy: $admin)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->grants->page(new GrantFilter(limit: 2, cursor: $page->nextCursor, expiresBefore: new DateTimeImmutable('2026-12-01T00:00:00Z'))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->grants->page(new GrantFilter(limit: 2, cursor: $page->nextCursor, expiresBefore: new DateTimeImmutable('2026-12-02T00:00:00Z'), grantedBy: $admin)))
        ->toThrow(InvalidArgumentException::class);
});

it('binds a cursor to the partition, the filter and the code build', function (): void {
    $page = $this->grants->page(new GrantFilter(limit: 1));
    $cursor = $page->nextCursor ?? '';
    [$id, $digest] = json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true);
    $forged = rtrim(strtr(base64_encode((string) json_encode(['role:1', $digest])), '+/', '-_'), '=');

    expect($cursor)->not->toBe('')
        ->and($id)->toBe(managerIds($page)[0])
        ->and(managerIds($this->grants->page(new GrantFilter(limit: 1, cursor: $cursor))))->not->toBe(managerIds($page))
        ->and(managerIds($this->grants->page(new GrantFilter(limit: 1, cursor: $forged))))->toHaveCount(1)
        ->and(fn () => $this->grants->page(new GrantFilter(kind: 'role', limit: 1, cursor: $cursor)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->grants->page(new GrantFilter(state: GrantFilter::ACTIVE, limit: 1, cursor: $cursor)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => M::managers($this->panel, 2)->grants()->page(new GrantFilter(limit: 1, cursor: $cursor)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => M::managers($this->panel, origin: 'import')->grants()->page(new GrantFilter(limit: 1, cursor: $cursor)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->grants->page(new GrantFilter(limit: 1, cursor: 'not-a-cursor')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->grants->page(new GrantFilter(limit: 1, cursor: rtrim(strtr(base64_encode((string) json_encode(['role:1', str_repeat('0', 64)])), '+/', '-_'), '='))))
        ->toThrow(InvalidArgumentException::class);

    $rebuilt = W::panel([static fn (Change $change, Closure $next) => $next($change)]);

    expect(fn () => M::managers($rebuilt)->grants()->page(new GrantFilter(limit: 1, cursor: $cursor)))->toThrow(InvalidArgumentException::class);
});

it('refuses a filter that contradicts itself, leaves the panel or asks for too many grants', function (): void {
    expect(fn () => new GrantFilter(kind: 'group'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GrantFilter(kind: 'permission', role: W::role('auditor')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GrantFilter(role: W::role('auditor'), permission: W::permission('clients.view')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GrantFilter(state: 'deleted'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GrantFilter(limit: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GrantFilter(limit: 501))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GrantFilter(cursor: ''))->toThrow(InvalidArgumentException::class)
        ->and((new GrantFilter)->limit)->toBe(50)
        ->and(fn () => $this->grants->page(new GrantFilter(role: RoleKey::of('backoffice', 'auditor'))))->toThrow(InvalidArgumentException::class);
});

it('fixes panel, tenant and origin when the managers are made and accepts them nowhere else', function (): void {
    $partition = [Panel::class, TenantRef::class];
    foreach ([ScopedGrantManager::class, ScopedPermissionManager::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal() && $reflection->isReadOnly())->toBeTrue();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                expect(in_array((string) $parameter->getType(), $partition, true) || $parameter->getName() === 'origin' || $parameter->getName() === 'tenant')
                    ->toBeFalse();
            }
        }
    }

    expect(fn () => PanelManagers::for($this->panel, TenantRef::of('crm.project', 1)))->toThrow(TenantMismatchException::class)
        ->and(fn () => PanelManagers::for($this->panel, W::tenant(), 'Not An Origin'))->toThrow(InvalidIdentityException::class)
        ->and(PanelManagers::for($this->panel, W::tenant(2), 'import')->tenant()->key())->toBe('crm.organization:2');
});
