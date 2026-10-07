<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventType;
use AzGuard\Events\PermissionCreated;
use AzGuard\Events\PermissionDeleted;
use AzGuard\Events\PermissionGranted;
use AzGuard\Events\PermissionGrantUpdated;
use AzGuard\Events\PermissionRevoked;
use AzGuard\Events\PermissionUpdated;
use AzGuard\Events\RoleGranted;
use AzGuard\Events\RoleGrantUpdated;
use AzGuard\Events\RoleRevoked;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CrmWorld::seed();
    EventWorld::listen();
    $this->panel = W::panel();
    $this->connection = CrmWorld::storage()->connection();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
});

it('publishes one event per effective change after the root commit, outside any transaction', function (): void {
    $result = W::grant($this->panel, 'analyst', 2, 1, fields: ['region' => 'R1'], actor: ActorRef::of('crm.user', 3, 'onboarding'));
    $event = EventWorld::events()[0];

    expect(EventWorld::types())->toBe(['role.granted'])->and(EventWorld::levels())->toBe([0])
        ->and($event)->toBeInstanceOf(RoleGranted::class)
        ->and($event->eventId)->toBe($result->effects[0]->eventId)
        ->and($event->correlationId)->toBe($result->correlationId)
        ->and($event->panel)->toBe('crm')->and($event->tenant->key())->toBe('crm.organization:1')
        ->and($event->actor?->reason)->toBe('onboarding')->and($event->actor->id)->toBe('3')
        ->and($event->subject->key())->toBe('crm.user:2')->and($event->role->full())->toBe('crm:analyst')
        ->and($event->context->key())->toBe('crm.project:1')->and($event->origin)->toBe('manual')
        ->and($event->fields)->toBe($result->record->fields)
        ->and($event->state)->toBeInstanceOf(StateToken::class)->and($event->state->equals($result->state))->toBeTrue()
        ->and($event->occurredAt->format('c'))->toBe('2026-10-06T12:00:00+00:00');
});

it('publishes nothing for a repeat that changes nothing', function (): void {
    W::grant($this->panel, 'analyst', 2, 1);
    EventWorld::reset();

    $again = W::grant($this->panel, 'analyst', 2, 1, actor: ActorRef::of('crm.user', 4));
    W::revoke($this->panel, 'analyst', 4, 1);

    expect($again->applied())->toBeFalse()->and(EventWorld::events())->toBe([]);
});

it('names the event of each kind of effect on roles and permissions', function (): void {
    $pipeline = W::pipeline();
    $role = W::role('analyst');
    $until = new DateTimeImmutable('2027-01-01T00:00:00Z');
    $granted = W::grant($this->panel, 'analyst', 2, 1);
    W::grant($this->panel, 'analyst', 2, 1, until: $until);
    $update = $pipeline->update($this->panel, W::tenant(), 'manual', $granted->record->id, new GrantDetails($until->modify('+1 day'), []));
    W::revoke($this->panel, 'analyst', 2, 1);
    $permission = W::permission('clients.view');
    $pipeline->grant($this->panel, W::tenant(), W::user(2), $permission, W::project(), until: $until);
    $stored = W::rows('permission')[0]['id'];
    $pipeline->update($this->panel, W::tenant(), 'manual', 'permission:'.$stored, new GrantDetails($until->modify('+2 days'), []));
    $pipeline->revoke($this->panel, W::tenant(), W::user(2), $permission, W::project());

    expect($role->key())->toBe('analyst')->and($update->applied())->toBeTrue()
        ->and(EventWorld::types())->toBe(['role.granted', 'role.grant_updated', 'role.grant_updated', 'role.revoked',
            'permission.granted', 'permission.grant_updated', 'permission.revoked'])
        ->and(array_map(fn (AccessEvent $event): string => $event::class, EventWorld::events()))->toBe([
            RoleGranted::class, RoleGrantUpdated::class, RoleGrantUpdated::class, RoleRevoked::class,
            PermissionGranted::class, PermissionGrantUpdated::class, PermissionRevoked::class,
        ])
        ->and(EventWorld::events()[2]->expiresAt?->format('Y-m-d'))->toBe('2027-01-02')
        ->and(EventWorld::events()[3]->expiresAt?->format('Y-m-d'))->toBe('2027-01-02')
        ->and(EventWorld::events()[4]->permission->full())->toBe('crm:clients.view');
});

it('gives every event of one operation the correlation id and the one resulting state, and every event its own id', function (): void {
    $result = W::pipeline()->sync($this->panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(5));
    $events = EventWorld::events();

    expect($events)->toHaveCount(2)
        ->and(array_unique(array_map(fn (AccessEvent $event): string => $event->correlationId, $events)))->toBe([$result->correlationId])
        ->and(count(array_unique(array_map(fn (AccessEvent $event): string => $event->eventId, $events))))->toBe(2)
        ->and($events[0]->state->equals($result->state))->toBeTrue()->and($events[1]->state->equals($result->state))->toBeTrue()
        ->and(array_map(fn ($effect): string => $effect->eventId, $result->effects))->toBe(array_map(fn (AccessEvent $event): string => $event->eventId, $events));
});

it('delivers nothing from a rolled back host transaction and delivers after the host commit', function (bool $commit): void {
    $this->connection->beginTransaction();
    W::grant($this->panel, 'analyst', 2, 1);

    expect(EventWorld::events())->toBe([]);
    $commit ? $this->connection->commit() : $this->connection->rollBack();

    expect(EventWorld::types())->toBe($commit ? ['role.granted'] : [])->and(EventWorld::levels())->toBe($commit ? [0] : []);
})->with(['commit' => [true], 'rollback' => [false]]);

it('keeps the historical state of each storage root of one host transaction: v+1 and v+2, one moment of publication', function (): void {
    $version = W::version();
    $this->connection->transaction(function (): void {
        W::grant($this->panel, 'analyst', 2, 1);
        W::grant($this->panel, 'auditor', 2, null);

        expect(EventWorld::events())->toBe([]);
    });

    expect(array_map(fn (AccessEvent $event): int => $event->state->version, EventWorld::events()))->toBe([$version + 1, $version + 2])
        ->and(EventWorld::levels())->toBe([0, 0]);
});

it('joins children of one active storage root into one token and delivers after its commit', function (): void {
    $version = W::version();
    CrmWorld::storage()->mutate('crm', function (StorageMutation $mutation): void {
        W::grant($this->panel, 'analyst', 2, 1);
        W::grant($this->panel, 'auditor', 2, null);

        expect(EventWorld::events())->toBe([]);
    });

    expect(array_map(fn (AccessEvent $event): int => $event->state->version, EventWorld::events()))->toBe([$version + 1, $version + 1]);
});

it('delivers nothing when the operation is cancelled or fails after a write', function (): void {
    $panel = W::panel([static function (Change $change, Closure $next): ChangeResult {
        $result = $next($change);

        if ($change->role?->key() === 'analyst') {
            $change->cancel('after the write');
        }

        return $result;
    }]);

    $keys = W::keys();

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(ChangeCancelledException::class)
        ->and(EventWorld::events())->toBe([])->and(W::keys())->toBe($keys);
});

it('publishes once after a retried root: the failed attempt leaves no event and no duplicate', function (): void {
    $attempts = 0;
    $panel = W::panel([static function (Change $change, Closure $next) use (&$attempts): ChangeResult {
        $result = $next($change);

        if (++$attempts === 1) {
            throw new QueryException('testbench', 'update azg_panel_state', [], new PDOException('Deadlock found when trying to get lock'));
        }

        return $result;
    }]);
    $result = W::grant($panel, 'analyst', 2, 1);

    expect($attempts)->toBe(2)->and(EventWorld::types())->toBe(['role.granted'])
        ->and(EventWorld::events()[0]->eventId)->toBe($result->effects[0]->eventId);
});

it('reports a failing listener, delivers the other events and keeps the committed change', function (): void {
    $delivered = [];
    Event::listen(RoleGranted::class, static function () use (&$delivered): never {
        $delivered[] = 'throwing';

        throw new RuntimeException('listener failed');
    });
    Event::listen(PermissionGranted::class, static function (PermissionGranted $event) use (&$delivered): void {
        $delivered[] = $event->type()->value;
    });
    $reported = [];
    $handler = app(ExceptionHandler::class);
    $this->app->instance(ExceptionHandler::class, new class($handler, $reported) implements ExceptionHandler
    {
        /** @param list<Throwable> $reported */
        public function __construct(private readonly object $inner, public array &$reported) {}

        public function report(Throwable $e): void
        {
            $this->reported[] = $e;
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });
    $pipeline = W::pipeline();
    $result = CrmWorld::storage()->mutate('crm', fn (): ChangeResult => $pipeline->sync($this->panel, W::tenant(), W::user(2), 'role', [W::role('analyst')], W::project(1)));
    $pipeline->grant($this->panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project());

    expect($result->applied())->toBeTrue()->and($delivered)->toBe(['throwing', 'permission.granted'])
        ->and($reported)->toHaveCount(1)->and($reported[0]->getMessage())->toBe('listener failed')
        ->and(W::keys())->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});

it('publishes through the host dispatcher so a faked dispatcher receives the events', function (): void {
    Event::fake([RoleGranted::class]);
    W::grant($this->panel, 'analyst', 2, 1);

    Event::assertDispatchedTimes(RoleGranted::class, 1);
});

it('carries values only: no model, request, container or closure reaches an event', function (): void {
    $this->panel = W::dynamicPanel();
    W::grant($this->panel, 'analyst', 2, 1, fields: ['region' => 'R1'], actor: ActorRef::of('crm.user', 3));
    W::createAction($this->panel, 'campaigns.launch', label: 'Launch');
    W::pipeline()->grant($this->panel, W::tenant(), W::user(2), W::permission('campaigns.launch'), W::project());
    W::deleteAction($this->panel, 'campaigns.launch');

    foreach (EventWorld::events() as $event) {
        expect(EventWorld::plain($event->toArray()))->toBeTrue()
            ->and(json_encode($event->toArray(), JSON_THROW_ON_ERROR))->toBeString();
    }
    expect(EventWorld::events())->not->toBe([]);
});

it('publishes the dynamic permission events in order and reports the grants a deletion removed once', function (): void {
    $this->panel = W::dynamicPanel();
    $created = W::createAction($this->panel, 'campaigns.launch', label: 'Launch', group: 'Marketing');
    $grant = W::pipeline()->grant($this->panel, W::tenant(), W::user(2), W::permission('campaigns.launch'), W::project(1));
    W::pipeline()->updatePermission($this->panel, W::tenant(), 'campaigns.launch', new PermissionDetails('Launch now', 'Marketing', null, []));
    $deleted = W::deleteAction($this->panel, 'campaigns.launch');
    [$created_event, , , $revoked, $deleted_event] = array_pad(EventWorld::events(), 5, null);

    expect(EventWorld::types())->toBe(['permission.created', 'permission.granted', 'permission.updated', 'permission.revoked', 'permission.deleted'])
        ->and($created_event)->toBeInstanceOf(PermissionCreated::class)->and($created_event->label)->toBe('Launch')
        ->and($created_event->eventId)->toBe($created->effects[0]->eventId)
        ->and(EventWorld::events()[2])->toBeInstanceOf(PermissionUpdated::class)->and(EventWorld::events()[2]->label)->toBe('Launch now')
        ->and($revoked)->toBeInstanceOf(PermissionRevoked::class)
        ->and($deleted_event)->toBeInstanceOf(PermissionDeleted::class)
        ->and($deleted_event->removedGrantIds)->toBe($deleted->removedGrantIds())->and($deleted->removedGrantIds())->toHaveCount(1)
        ->and($grant->applied())->toBeTrue();
});

it('has a catalog of exactly twelve event types', function (): void {
    expect(array_map(fn (EventType $type): string => $type->value, EventType::cases()))->toBe([
        'permission.created', 'permission.updated', 'permission.deleted', 'role.granted', 'role.revoked', 'role.grant_updated',
        'permission.granted', 'permission.revoked', 'permission.grant_updated', 'grant.expired', 'panel.touched', 'access.decided',
    ]);
});
