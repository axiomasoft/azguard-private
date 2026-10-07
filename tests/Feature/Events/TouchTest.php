<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeType;
use AzGuard\Events\PanelStateTouched;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Fixtures\Panels\ArraySource;

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

it('raises the version by one without a grant row and publishes PanelStateTouched after the commit', function (): void {
    $version = W::version();
    $rows = [W::rows('role'), W::rows('permission')];
    $state = W::pipeline()->touch($this->panel, 'host import finished');
    $event = EventWorld::events()[0] ?? null;

    expect($state->version)->toBe($version + 1)->and(W::version())->toBe($version + 1)
        ->and([W::rows('role'), W::rows('permission')])->toBe($rows)
        ->and(EventWorld::types())->toBe(['panel.touched'])->and(EventWorld::levels())->toBe([0])
        ->and($event)->toBeInstanceOf(PanelStateTouched::class)
        ->and($event->previousVersion)->toBe($version)->and($event->reason)->toBe('host import finished')
        ->and($event->tenant->isGlobal())->toBeTrue()->and($event->subject())->toBeNull()
        ->and($event->state->equals($state))->toBeTrue()->and($event->actor?->reason)->toBe('console');
});

it('takes the explicit actor and keeps its reason', function (): void {
    W::pipeline()->touch($this->panel, 'manual', ActorRef::of('crm.user', 3, 'maintenance'));

    expect(EventWorld::events()[0]->actor?->id)->toBe('3')->and(EventWorld::events()[0]->actor->reason)->toBe('maintenance');
});

it('commits and rolls back with the host transaction like any other change', function (bool $commit): void {
    $version = W::version();
    $this->connection->beginTransaction();
    $state = W::pipeline()->touch($this->panel, 'inside host');

    expect(W::version())->toBe($version + 1)->and(EventWorld::events())->toBe([]);
    $commit ? $this->connection->commit() : $this->connection->rollBack();

    expect(W::version())->toBe($commit ? $version + 1 : $version)->and(EventWorld::types())->toBe($commit ? ['panel.touched'] : [])
        ->and($state->version)->toBe($version + 1);
})->with(['commit' => [true], 'rollback' => [false]]);

it('shares one bump with a grant inside one active storage root, and both events carry that revision', function (): void {
    $version = W::version();
    CrmWorld::storage()->mutate('crm', function (StorageMutation $mutation): void {
        W::grant($this->panel, 'analyst', 2, 1);
        W::pipeline()->touch($this->panel, 'in the same root');
    });

    expect(W::version())->toBe($version + 1)->and(EventWorld::types())->toBe(['role.granted', 'panel.touched'])
        ->and(array_map(fn ($event): int => $event->state->version, EventWorld::events()))->toBe([$version + 1, $version + 1])
        ->and(EventWorld::events()[1]->previousVersion)->toBe($version);
});

it('gives two touches in one host transaction two bumps and two historical previous versions', function (): void {
    $version = W::version();
    $this->connection->transaction(function (): void {
        W::pipeline()->touch($this->panel, 'first');
        W::pipeline()->touch($this->panel, 'second');
    });

    expect(W::version())->toBe($version + 2)
        ->and(array_map(fn (PanelStateTouched $event): array => [$event->previousVersion, $event->state->version], EventWorld::events()))
        ->toBe([[$version, $version + 1], [$version + 1, $version + 2]]);
});

it('runs through the changing pipes as a global inspection change with its own typed values', function (): void {
    $seen = [];
    $panel = W::panel([static function (Change $change, Closure $next) use (&$seen): ChangeResult {
        $context = $change->context();
        $seen[] = [$change->type, $change->scope->tenant->isGlobal(), $change->scope->context->isGlobal(), $change->subject, $change->reason,
            $context->phase, $context->proposed, $context->user, $context->role, $context->actor?->reason];

        return $next($change);
    }]);
    $result = W::pipeline()->run($panel, TenantRef::global(), static fn ($reads, $actor): array => [Change::touchPanel('crm', 'seen by pipes', $actor)]);

    expect($seen)->toBe([[ChangeType::TouchPanel, true, true, null, 'seen by pipes', AssignmentScopePhase::Inspection, [], null, null, 'console']])
        ->and($result->applied())->toBeTrue()->and($result->record)->toBeNull()->and($result->records)->toBe([])
        ->and($result->effects)->toHaveCount(1)->and($result->effects[0]->previousVersion)->toBe(W::version() - 1)
        ->and($result->effects[0]->reason)->toBe('seen by pipes')->and($result->effects[0]->before)->toBeNull()->and($result->effects[0]->after)->toBeNull();
});

it('lets a pipe cancel a touch and then neither bumps nor publishes', function (): void {
    $panel = W::panel([static fn (Change $change, Closure $next): ChangeResult => $change->type->isTouch() ? $change->cancel('no touches now') : $next($change)]);
    $version = W::version();

    expect(fn () => W::pipeline()->touch($panel, 'cancelled'))->toThrow(ChangeCancelledException::class)
        ->and(W::version())->toBe($version)->and(EventWorld::events())->toBe([]);
});

it('does not let a pipe change a touch', function (): void {
    $panel = W::panel([static fn (Change $change, Closure $next): ChangeResult => $next($change->withUntil(new DateTimeImmutable('2030-01-01')))]);

    expect(fn () => W::pipeline()->touch($panel, 'x'))->toThrow(InvalidConfigurationException::class);
});

it('refuses an empty or oversized reason', function (string $reason): void {
    expect(fn () => W::pipeline()->touch($this->panel, $reason))->toThrow(InvalidIdentityException::class);
})->with(['empty' => [''], 'blank' => ['  '], 'oversized' => [str_repeat('r', 256)]]);

it('refuses a touch of a panel without a database writer', function (): void {
    $panel = W::panel(sources: [new ArraySource('readonly')]);

    expect(fn () => W::pipeline()->touch($panel, 'x'))->toThrow(PanelNotWritableException::class);
});

it('writes a journal row for a touch only when the audit plugin is on, with the actor reason', function (bool $audit): void {
    $panel = W::panel(configure: static fn ($builder) => $audit ? $builder->plugins([AuditPlugin::make()]) : null);
    W::pipeline()->touch($panel, 'because', ActorRef::system('deploy'));
    $rows = EventWorld::auditRows();

    expect($rows)->toHaveCount($audit ? 1 : 0);

    if ($audit) {
        expect($rows[0]['type'])->toBe('panel.touched')->and($rows[0]['event_id'])->toBe(EventWorld::events()[0]->eventId)
            ->and($rows[0]['actor_reason'])->toBe('deploy')->and($rows[0]['subject_type'])->toBeNull()
            ->and(json_decode($rows[0]['payload'], true)['reason'])->toBe('because');
    }
})->with(['audit on' => [true], 'audit off' => [false]]);
