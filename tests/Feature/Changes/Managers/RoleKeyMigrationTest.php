<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantRecord;
use AzGuard\Changes\RoleKeyMigration;
use AzGuard\Events\RoleGrantUpdated;
use AzGuard\Events\RoleRevoked;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\RoleNotGrantableException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Feature\Changes\Managers\LegacyRootRole;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Support\Carbon;

function migration(): RoleKeyMigration
{
    return app(RoleKeyMigration::class);
}

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
    // Grants of the former key `inspector` of AuditorRole, written before the rename.
    $this->rows = [
        'valid' => M::stored('role', 'inspector', 2, meta: ['legacy' => 'kept']),
        'expired' => M::stored('role', 'inspector', 3, origin: 'import', until: '2026-01-01 00:00:00'),
        'unbound scope' => M::stored('role', 'inspector', 2, 'crm.project:3'),
        'missing subject' => M::stored('role', 'inspector', 99),
        'collision' => M::stored('role', 'inspector', 1, until: '2027-06-01 00:00:00', meta: ['eligible' => false]),
        'tenant B' => M::stored('role', 'inspector', 1, tenant: 2),
    ];
    $this->target = M::stored('role', 'auditor', 1, until: '2027-01-01 00:00:00', meta: ['eligible' => true]);
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('V10 gives a grant of a former key no authority and refuses an ordinary grant of it until the explicit migration', function (): void {
    CrmWorld::assertDecision(CrmWorld::decide($this->panel, 6, user: 2), false, DecisionReason::NotGranted);

    expect(fn () => W::grant($this->panel, 'inspector', 2, null))->toThrow(UnknownRoleException::class, 'migrate it explicitly')
        ->and(array_map(fn (GrantRecord $record): string => $record->id,
            M::managers($this->panel)->grants()->page(new GrantFilter(role: W::role('inspector'), state: GrantFilter::ORPHANED))->items))
        ->toContain($this->rows['valid'], $this->rows['unbound scope'], $this->rows['missing subject'], $this->rows['collision']);
});

it('V10 plans the migration under the lock without a write, a version, a pipe, a journal row or an event', function (): void {
    $calls = 0;
    $panel = W::panel([function (Change $change, Closure $next) use (&$calls) {
        $calls++;

        return $next($change);
    }], static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]));
    $before = [W::rows(), W::version()];
    EventWorld::listen();
    $plan = migration()->plan($panel, 'inspector', 'auditor');
    $tenantA = $plan['crm.organization:1'];
    $collision = array_values(array_filter($tenantA, fn (array $entry): bool => $entry['target'] !== null));

    expect(array_keys($plan))->toBe(['crm.organization:1', 'crm.organization:2'])
        ->and(array_map(fn (array $entry): string => $entry['source']->id, $tenantA))
        ->toBe([$this->rows['valid'], $this->rows['expired'], $this->rows['unbound scope'], $this->rows['missing subject'], $this->rows['collision']])
        ->and($collision)->toHaveCount(1)
        ->and($collision[0]['target']?->id)->toBe($this->target)
        ->and($collision[0]['until'])->toEqual(new DateTimeImmutable('2027-06-01T00:00:00Z'))
        ->and([W::rows(), W::version()])->toBe($before)
        ->and($calls)->toBe(0)
        ->and(EventWorld::events())->toBe([])
        ->and(EventWorld::auditRows())->toBe([]);
});

it('V10 R66 moves every grant of the former key with its id, scope, origin, expiry and fields; collisions keep the current grant', function (): void {
    $before = array_map(fn (string $id): ?array => M::row($id), $this->rows);
    $version = W::version();
    Carbon::setTestNow('2026-10-06T12:30:00Z');
    EventWorld::listen();
    $results = migration()->run($this->panel, 'inspector', 'auditor', actor: ActorRef::system('roles:rename-key'));
    $tenantA = $results['crm.organization:1'];

    expect(array_keys($results))->toBe(['crm.organization:1', 'crm.organization:2'])
        ->and(W::version())->toBe($version + 2)
        ->and(array_map(fn ($effect): array => [$effect->kind, $effect->type, $effect->before?->id, $effect->before?->role?->key(), $effect->after?->role?->key()], $tenantA->effects))
        ->toBe([
            [EffectKind::Updated, ChangeType::MigrateRoleGrant, $this->rows['valid'], 'inspector', 'auditor'],
            [EffectKind::Updated, ChangeType::MigrateRoleGrant, $this->rows['expired'], 'inspector', 'auditor'],
            [EffectKind::Updated, ChangeType::MigrateRoleGrant, $this->rows['unbound scope'], 'inspector', 'auditor'],
            [EffectKind::Updated, ChangeType::MigrateRoleGrant, $this->rows['missing subject'], 'inspector', 'auditor'],
            [EffectKind::Updated, ChangeType::MigrateRoleGrant, $this->target, 'auditor', 'auditor'],
            [EffectKind::Deleted, ChangeType::MigrateRoleGrant, $this->rows['collision'], 'inspector', null],
        ]);

    foreach (['valid', 'expired', 'unbound scope', 'missing subject', 'tenant B'] as $name) {
        $row = M::row($this->rows[$name]);
        $old = $before[$name];

        expect($row['role'])->toBe('auditor')
            ->and(array_diff_key($row, ['role' => 0, 'updated_at' => 0]))->toBe(array_diff_key($old, ['role' => 0, 'updated_at' => 0]))
            ->and($row['updated_at'])->not->toBe($old['updated_at']);
    }

    expect(M::row($this->rows['collision']))->toBeNull()
        ->and(M::row($this->target)['expires_at'])->toStartWith('2027-06-01 00:00:00')
        ->and(json_decode((string) M::row($this->target)['meta'], true))->toBe(['eligible' => true])
        ->and(json_decode((string) M::row($this->rows['valid'])['meta'], true))->toBe(['legacy' => 'kept'])
        ->and(array_values(array_filter(W::rows(), fn (array $row): bool => $row['role'] === 'inspector')))->toBe([]);

    $events = EventWorld::events();
    $moved = array_values(array_filter($events, fn ($event): bool => $event instanceof RoleGrantUpdated && $event->previousRole !== null));

    expect(EventWorld::types())->toBe([...array_fill(0, 5, 'role.grant_updated'), 'role.revoked', 'role.grant_updated'])
        ->and($moved)->toHaveCount(5)
        ->and(array_unique(array_map(fn (RoleGrantUpdated $event): string => $event->previousRole?->full() ?? '', $moved)))->toBe(['crm:inspector'])
        ->and($events[5])->toBeInstanceOf(RoleRevoked::class)->and($events[5]->role->key())->toBe('inspector')
        ->and($events[0]->actor?->reason)->toBe('roles:rename-key');
});

it('R66 restores the authority of the current role but keeps the refusal of expired, unbound and missing grants', function (): void {
    CrmWorld::assertDecision(CrmWorld::decide($this->panel, 6, user: 3), false, DecisionReason::NotGranted);
    migration()->run($this->panel, 'inspector', 'auditor');
    app()->forgetInstance(Authorizer::class);

    // Unknown fields of the old schema do not deny on their own; the current conditions decide.
    CrmWorld::assertDecision(CrmWorld::decide($this->panel, 6, user: 2), true, DecisionReason::Granted);
    CrmWorld::assertDecision(CrmWorld::decide($this->panel, 6, user: 3), false, DecisionReason::NotGranted);

    $grants = M::managers($this->panel)->grants();
    $orphaned = array_map(fn (GrantRecord $record): string => $record->id, $grants->page(new GrantFilter(state: GrantFilter::ORPHANED))->items);

    expect($orphaned)->toContain($this->rows['unbound scope'])
        ->and(M::managers($this->panel, origin: 'import')->grants()->page(new GrantFilter(state: GrantFilter::EXPIRED))->items[0]->id)->toBe($this->rows['expired'])
        ->and(fn () => $grants->update($this->rows['unbound scope'], new GrantDetails(null, [])))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and($grants->revokeMany([$this->rows['unbound scope'], $this->rows['missing subject']])->removedGrantIds())
        ->toBe([$this->rows['unbound scope'], $this->rows['missing subject']]);
});

it('lets a pipe see and cancel each historical move but never change it', function (string $attempt): void {
    $seen = [];
    $panel = W::panel([function (Change $change, Closure $next) use (&$seen, $attempt) {
        $context = $change->context();
        $seen[] = [$change->type, $change->previousRole?->key(), $change->role?->key(), $context->phase->value, $context->proposed];

        return match ($attempt) {
            'cancel' => $change->cancel('migration needs approval'),
            'until' => $next($change->withUntil(new DateTimeImmutable('2030-01-01T00:00:00Z'))),
            'fields' => $next($change->withFields(['eligible' => true])),
        };
    }]);
    $rows = W::rows();

    expect(fn () => migration()->run($panel, 'inspector', 'auditor', W::tenant()))
        ->toThrow($attempt === 'cancel' ? ChangeCancelledException::class : InvalidConfigurationException::class)
        ->and($seen[0])->toBe([ChangeType::MigrateRoleGrant, 'inspector', 'auditor', 'inspection', []])
        ->and(W::rows())->toBe($rows);
})->with(['cancel', 'until', 'fields']);

it('migrates only from a former key of a registered grantable role and gives ordinary grants no bypass', function (): void {
    $rows = W::rows();

    expect(fn () => migration()->run($this->panel, 'inspector', 'ghost'))->toThrow(UnknownRoleException::class)
        ->and(fn () => migration()->run($this->panel, 'ghost', 'auditor'))->toThrow(UnknownRoleException::class, 'not a former key')
        ->and(fn () => migration()->plan($this->panel, 'seller', 'auditor'))->toThrow(UnknownRoleException::class)
        ->and(fn () => W::pipeline()->migrateRoleKey($this->panel, W::tenant(), 'inspector', RoleKey::of('backoffice', 'auditor')))
        ->toThrow(UnknownRoleException::class)
        ->and(fn () => W::grant($this->panel, 'auditor', 2, null, fields: ['maintenance' => true]))->toThrow(InvalidChangeFieldsException::class)
        ->and(W::rows())->toBe($rows)
        ->and(migration()->run($this->panel, 'inspector', 'auditor', W::tenant(2))['crm.organization:2']->effects)->toHaveCount(1)
        ->and(migration()->run($this->panel, 'inspector', 'auditor', W::tenant(2))['crm.organization:2']->effects)->toBe([]);

    $legacy = W::panel(configure: static fn (PanelBuilder $builder) => $builder->roles([LegacyRootRole::class]));
    M::stored('role', 'old-root', 2);
    $rows = W::rows();

    expect(fn () => migration()->run($legacy, 'old-root', LegacyRootRole::class))->toThrow(RoleNotGrantableException::class)
        ->and(W::rows())->toBe($rows);
});

it('keeps the later expiry on a collision, no expiry winning, and only then updates the current grant', function (): void {
    $pairs = [];
    foreach ([5 => ['2027-03-01 00:00:00', '2027-09-01 00:00:00'], 6 => [null, '2027-01-01 00:00:00'], 7 => ['2026-01-01 00:00:00', null]] as $user => [$from, $to]) {
        $pairs[$user] = [M::stored('role', 'inspector', $user, origin: 'legacy', until: $from), M::stored('role', 'auditor', $user, origin: 'legacy', until: $to)];
    }
    $result = W::pipeline()->migrateRoleKey($this->panel, W::tenant(), 'inspector', W::role('auditor'));
    $legacy = array_values(array_filter($result->effects, fn ($effect): bool => $effect->before?->origin === 'legacy'));

    expect(array_map(fn ($effect): array => [$effect->kind, $effect->before?->id], $legacy))->toBe([
        [EffectKind::Deleted, $pairs[5][0]],
        [EffectKind::Updated, $pairs[6][1]], [EffectKind::Deleted, $pairs[6][0]],
        [EffectKind::Deleted, $pairs[7][0]],
    ])
        ->and(M::row($pairs[5][1])['expires_at'])->toStartWith('2027-09-01 00:00:00')
        ->and(M::row($pairs[6][1])['expires_at'])->toBeNull()
        ->and(M::row($pairs[7][1])['expires_at'])->toBeNull()
        ->and(array_map(fn (array $pair): ?array => M::row($pair[0]), $pairs))->toBe([5 => null, 6 => null, 7 => null]);
});

it('records each move in the audit journal with both role keys', function (): void {
    $panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]));
    EventWorld::listen();
    migration()->run($panel, 'inspector', 'auditor', W::tenant(2), ActorRef::system('roles:rename-key'));
    $rows = EventWorld::auditRows();

    expect(array_column($rows, 'type'))->toBe(['role.grant_updated'])
        ->and(json_decode((string) $rows[0]['payload'], true))->toMatchArray(['role' => 'crm:auditor', 'previous_role' => 'crm:inspector'])
        ->and($rows[0]['actor_reason'])->toBe('roles:rename-key')
        ->and(array_column($rows, 'event_id'))->toBe(array_map(fn ($event): string => $event->eventId, EventWorld::events()));
});
