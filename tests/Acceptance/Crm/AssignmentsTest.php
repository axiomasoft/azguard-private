<?php

declare(strict_types=1);

use AzGuard\Changes\ActingActor;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\GrantDetails;
use AzGuard\Events\RoleRevoked;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Storage\StorageTouched;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\DelegationPipe;
use AzGuard\Tests\Fixtures\Changes\HostFencePipe;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\InjectedSellerFilter;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    DelegationPipe::reset();
    EventWorld::reset();
});

/** @return list<mixed> rows of both grant tables and the panel version */
function crmWrites(): array
{
    return [W::rows('role'), W::rows('permission'), W::version()];
}

it('R07 refuses a project or a client field of tenant B in tenant A without a partial write', function (): void {
    $panel = W::panel(configure: fn (PanelBuilder $p) => $p->fields(FieldTarget::RoleGrant, [Field::model('client', Client::class)->inMeta()]));
    $before = crmWrites();

    expect(fn () => W::grant($panel, 'analyst', 1, 4, tenant: 1))->toThrow(TenantMismatchException::class)
        ->and(fn () => W::grant($panel, 'seller', 1, 5, fields: ['client' => 5]))->toThrow(InvalidChangeFieldsException::class)
        ->and(fn () => W::pipeline()->sync($panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(4)))
        ->toThrow(TenantMismatchException::class)
        ->and(crmWrites())->toBe($before)
        ->and(W::grant($panel, 'seller', 1, 5, fields: ['client' => 6])->record?->fields['client'])->toBe(6);
});

it('R09 refuses an Assignment into the inactive project P3 whatever existing contributions say', function (): void {
    $panel = W::panel();
    World::assign('tenant-admin', 2, 3);
    $before = crmWrites();

    expect(fn () => W::grant($panel, 'analyst', 2, 3))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project(3)))
        ->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(crmWrites())->toBe($before)
        ->and(W::grant($panel, 'analyst', 2, 1)->applied())->toBeTrue();
});

it('R26 filters the target Anna by her city while delegation sees the admin actor', function (): void {
    DelegationPipe::$delegations = ['crm.user:2' => ['roles' => ['seller'], 'contexts' => ['crm.project:1', 'crm.project:2', 'crm.project:5']]];
    $panel = W::panel([DelegationPipe::class]);
    $boris = ActorRef::of('crm.user', 2);

    expect(W::grant($panel, 'seller', 1, 5, actor: $boris)->applied())->toBeTrue()
        ->and(end(SellerProjects::$observed)->user?->getKey())->toBe(1)
        ->and(end(SellerProjects::$observed)->actorModel?->getKey())->toBe(2)
        ->and(DelegationPipe::$observed[0])->toBe(['actor' => 'crm.user:2', 'user' => 1, 'role' => SellerRole::class])
        ->and(fn () => W::grant($panel, 'seller', 1, 2, actor: $boris))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(end(SellerProjects::$observed)->user?->getKey())->toBe(1);
});

it('R27 refuses escalation by a manager with limited delegation and lets the delegated grant pass', function (string $attempt, Closure $grant): void {
    DelegationPipe::$delegations = ['crm.user:2' => ['roles' => ['seller'], 'contexts' => ['crm.project:1', 'crm.project:5']]];
    $panel = W::panel([DelegationPipe::class]);
    $manager = ActorRef::of('crm.user', 2, 'ticket CRM-27');
    $before = crmWrites();

    if ($attempt === 'allowed') {
        $result = $grant($panel, $manager);

        expect($result->applied())->toBeTrue()
            ->and(W::rows()[array_key_last(W::rows())]['actor_reason'])->toBe('ticket CRM-27');

        return;
    }

    expect(fn () => $grant($panel, $manager))->toThrow(ChangeCancelledException::class)->and(crmWrites())->toBe($before);
})->with([
    'super admin role' => ['escalation', fn ($panel, $actor) => W::grant($panel, 'tenant-admin', 1, 1, actor: $actor)],
    'wildcard' => ['escalation', fn ($panel, $actor) => W::pipeline()->grant($panel, W::tenant(), W::user(1), W::permission('clients.*'), W::project(1), actor: $actor)],
    'cross project' => ['escalation', fn ($panel, $actor) => W::grant($panel, 'seller', 2, 2, actor: $actor)],
    'role not delegated' => ['escalation', fn ($panel, $actor) => W::grant($panel, 'analyst', 1, 5, actor: $actor)],
    'no actor' => ['escalation', fn ($panel, $actor) => app(ActingActor::class)->run(null, fn () => W::grant($panel, 'seller', 1, 5))],
    'allowed' => ['allowed', fn ($panel, $actor) => W::grant($panel, 'seller', 1, 5, actor: $actor)],
]);

it('R28 lets an admin revoke grants of a deactivated, expired or deleted context', function (): void {
    $panel = W::panel();
    W::grant($panel, 'analyst', 2, 5, until: new DateTimeImmutable('2026-10-06T12:10:00Z'));
    W::grant($panel, 'seller', 1, 5);
    Project::query()->whereKey(5)->update(['is_active' => false]);
    World::storage()->connection()->table('clients')->where('project_id', 2)->delete();
    Project::query()->whereKey(2)->delete();
    Carbon::setTestNow('2026-10-06T13:00:00Z');
    World::decide($panel);

    expect(W::revoke($panel, 'analyst', 2, 5)->effects)->toHaveCount(1)
        ->and(W::revoke($panel, 'seller', 1, 5)->effects)->toHaveCount(1)
        ->and(W::revoke($panel, 'seller', 2, 2)->effects)->toHaveCount(1)
        ->and(W::revokeEverywhere($panel, 'analyst', 1)->effects)->toHaveCount(1)
        ->and(W::keys())->toBe(['crm.organization:1|seller|1|crm.project:1|manual', 'crm.organization:2|analyst|1|crm.project:4|manual',
            'crm.organization:1|tenant-admin|3|crm.project:1|manual']);
});

it('R30 repeats final validation after a changing pipe, rolls back nested work and keeps a no-op silent', function (): void {
    $touched = 0;
    Event::listen(StorageTouched::class, function () use (&$touched): void {
        $touched++;
    });
    $panel = W::panel([static fn (Change $c, Closure $next): ChangeResult => $next($c->fields === ['region' => 'past']
        ? $c->withUntil(new DateTimeImmutable('2026-10-01T00:00:00Z')) : $c)]);
    $record = W::grant($panel, 'seller', 1, 5)->record;
    $touched = 0;
    $before = crmWrites();

    expect(fn () => W::pipeline()->update($panel, W::tenant(), 'manual', $record->id ?? '', new GrantDetails(null, ['region' => 'past'])))
        ->toThrow(InvalidChangeFieldsException::class);

    $connection = World::storage()->connection();
    $connection->beginTransaction();
    $pending = W::pipeline()->update($panel, W::tenant(), 'manual', $record->id ?? '', new GrantDetails(new DateTimeImmutable('2027-01-01T00:00:00Z'), ['region' => 'R1']));
    $connection->rollBack();
    $noop = W::grant($panel, 'seller', 1, 5);

    expect($pending->committed)->toBeFalse()->and($pending->applied())->toBeTrue()
        ->and($noop->status)->toBe(ChangeStatus::Unchanged)
        ->and(crmWrites())->toBe($before)
        ->and($touched)->toBe(0);
});

it('R47 gives Assignment capabilities the target user, BaseRole, validated proposal, actor, scope and phase through DI', function (): void {
    InjectedSellerFilter::$observed = [];
    $panel = W::panel(configure: fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(InjectedSellerFilter::class))));
    W::grant($panel, 'seller', 1, 5, until: new DateTimeImmutable('2026-12-01T00:00:00.5Z'), fields: ['region' => 'R2'], actor: ActorRef::of('crm.user', 3));
    $runtime = InjectedSellerFilter::$observed[0];

    expect($runtime->phase)->toBe(AssignmentScopePhase::Assignment)
        ->and($runtime->user)->toBeInstanceOf(User::class)->and($runtime->user?->getKey())->toBe(1)
        ->and($runtime->role)->toBeNull()
        ->and(end(SellerProjects::$observed)->role)->toBeInstanceOf(SellerRole::class)
        ->and($runtime->proposed['until']?->format(DATE_ATOM))->toBe('2026-12-01T00:00:00+00:00')
        ->and($runtime->proposed['fields'])->toBe(['region' => 'R2'])
        ->and($runtime->actor)->toEqual(ActorRef::of('crm.user', 3))->and($runtime->actorModel?->getKey())->toBe(3)
        ->and($runtime->scope->context->key())->toBe('crm.project:5')
        ->and(app()->bound(User::class))->toBeFalse();
});

it('R63 rejects an exact grant of the PolicyOnly action and keeps wildcards from authorizing it', function (): void {
    $panel = W::panel();
    $before = crmWrites();

    expect(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::pipeline()->permission($panel, Action::ViewOwnProfile), W::project()))
        ->toThrow(PermissionNotGrantableException::class)
        ->and(crmWrites())->toBe($before);

    W::pipeline()->grant($panel, W::tenant(), W::user(1), W::permission('clients.*'), W::project());
    World::assertDecision(World::decide($panel, 6, Action::ViewOwnProfile, 1), false, DecisionReason::Policy);
    World::assertDecision(World::decide($panel, 6, Action::View, 1), true, DecisionReason::Granted);
});

it('R29 refuses a change prepared against an old build or owner revision through the cooperating host fence', function (): void {
    $panel = W::panel([HostFencePipe::class]);
    $fingerprint = app(PanelRegistry::class)->fingerprint('crm');

    try {
        HostFencePipe::install($fingerprint);
        $before = crmWrites();
        HostFencePipe::$preparedBuild = 'old-build';

        expect(fn () => W::grant($panel, 'seller', 1, 5))->toThrow(ChangeCancelledException::class, 'active build');

        HostFencePipe::$preparedBuild = $fingerprint;
        World::storage()->connection()->table('crm_project_revisions')->where('project_id', '5')->update(['revision' => 2]);

        expect(fn () => W::grant($panel, 'seller', 1, 5))->toThrow(ChangeCancelledException::class, 'changed owner')
            ->and(crmWrites())->toBe($before);

        HostFencePipe::$preparedRevisions['5'] = 2;

        expect(W::grant($panel, 'seller', 1, 5)->applied())->toBeTrue();
    } finally {
        HostFencePipe::uninstall();
    }
});

it('R27 keeps the actor identity and reason in the journal and the event of a delegated grant, and writes nothing for a refused escalation', function (): void {
    DelegationPipe::$delegations = ['crm.user:2' => ['roles' => ['seller'], 'contexts' => ['crm.project:5']]];
    $panel = W::panel([DelegationPipe::class], static fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([AuditPlugin::make()]));
    $manager = ActorRef::of('crm.user', 2, 'ticket CRM-27');
    EventWorld::listen();

    expect(fn () => W::grant($panel, 'tenant-admin', 2, 5, actor: $manager))->toThrow(ChangeCancelledException::class)
        ->and(EventWorld::events())->toBe([])->and(EventWorld::auditRows())->toBe([]);

    $result = W::grant($panel, 'seller', 1, 5, actor: $manager);
    $rows = EventWorld::auditRows();

    expect($rows)->toHaveCount(1)->and($rows[0]['event_id'])->toBe($result->effects[0]->eventId)->and($rows[0]['type'])->toBe('role.granted')
        ->and($rows[0]['actor_type'])->toBe('crm.user')->and($rows[0]['actor_id'])->toBe('2')->and($rows[0]['actor_reason'])->toBe('ticket CRM-27')
        ->and($rows[0]['subject_id'])->toBe('1')->and($rows[0]['correlation_id'])->toBe($result->correlationId)
        ->and(EventWorld::events()[0]->actor?->reason)->toBe('ticket CRM-27')->and(EventWorld::events()[0]->eventId)->toBe($rows[0]['event_id']);
});

it('R30 publishes no event and writes no journal row for rolled back nested work or a silent repeat, and one for the real change', function (): void {
    $panel = W::panel(configure: static fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([AuditPlugin::make()]));
    $record = W::grant($panel, 'seller', 1, 5)->record;
    EventWorld::listen();
    $connection = World::storage()->connection();

    $connection->beginTransaction();
    W::pipeline()->update($panel, W::tenant(), 'manual', $record->id ?? '', new GrantDetails(new DateTimeImmutable('2027-01-01T00:00:00Z'), ['region' => 'R1']));
    $connection->rollBack();
    $noop = W::grant($panel, 'seller', 1, 5);

    expect($noop->status)->toBe(ChangeStatus::Unchanged)->and(EventWorld::events())->toBe([])->and(count(EventWorld::auditRows()))->toBe(1);

    $update = W::pipeline()->update($panel, W::tenant(), 'manual', $record->id ?? '', new GrantDetails(new DateTimeImmutable('2027-01-01T00:00:00Z'), ['region' => 'R1']));

    expect(EventWorld::types())->toBe(['role.grant_updated'])->and(EventWorld::events()[0]->eventId)->toBe($update->effects[0]->eventId)
        ->and(array_column(EventWorld::auditRows(), 'type'))->toBe(['role.granted', 'role.grant_updated']);
});

it('R42 publishes the revocation of one origin only: the grant of another origin stays and stays silent', function (): void {
    $panel = W::panel();
    W::grant($panel, 'seller', 1, 5);
    W::grant($panel, 'seller', 1, 5, origin: 'import');
    EventWorld::listen();
    $result = W::revoke($panel, 'seller', 1, 5);
    $event = EventWorld::events()[0] ?? null;

    expect($result->effects)->toHaveCount(1)->and(EventWorld::types())->toBe(['role.revoked'])
        ->and($event)->toBeInstanceOf(RoleRevoked::class)->and($event->origin)->toBe('manual')
        ->and(array_values(array_filter(W::keys(), fn (string $key): bool => str_contains($key, '|seller|1|crm.project:5|'))))
        ->toBe(['crm.organization:1|seller|1|crm.project:5|import']);
});
