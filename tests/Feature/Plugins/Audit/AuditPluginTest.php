<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeJournal;
use AzGuard\Changes\ChangeResult;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Database\QueryException;

function auditedPanel(array $pipes = [], bool $dynamic = false): Panel
{
    $audit = static fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([AuditPlugin::make()]);

    return $dynamic
        ? W::panel($pipes, $audit, [CrmWorld::database()->dynamicPermissions()])
        : W::panel($pipes, $audit);
}

beforeEach(function (): void {
    CrmWorld::seed();
    EventWorld::listen();
    $this->connection = CrmWorld::storage()->connection();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
});

it('is the azguard/audit plugin with a retention of at least one day, copied on change', function (): void {
    $plugin = AuditPlugin::make();
    $longer = $plugin->retention(365);

    expect($plugin->id())->toBe('azguard/audit')->and($plugin->retentionDays())->toBe(90)
        ->and($longer->retentionDays())->toBe(365)->and($plugin->retentionDays())->toBe(90)->and($longer)->not->toBe($plugin)
        ->and(fn () => AuditPlugin::make(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $plugin->retention(-1))->toThrow(InvalidArgumentException::class);
});

it('writes nothing while the plugin is not attached', function (): void {
    $panel = W::panel();
    W::grant($panel, 'analyst', 2, 1);

    expect(EventWorld::auditRows())->toBe([])->and(EventWorld::types())->toBe(['role.granted']);
});

it('writes one row per effect with the id, envelope and payload of the published event', function (): void {
    $panel = auditedPanel();
    $result = W::grant($panel, 'analyst', 2, 1, fields: ['region' => 'R1'], actor: ActorRef::of('crm.user', 3, 'onboarding'));
    $rows = EventWorld::auditRows();
    $event = EventWorld::events()[0];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['event_id'])->toBe($event->eventId)->and($rows[0]['event_id'])->toBe($result->effects[0]->eventId)
        ->and($rows[0]['type'])->toBe('role.granted')->and($rows[0]['panel'])->toBe('crm')
        ->and($rows[0]['tenant_key'])->toBe('crm.organization:1')->and($rows[0]['tenant_type'])->toBe('crm.organization')->and($rows[0]['tenant_id'])->toBe('1')
        ->and($rows[0]['subject_type'])->toBe('crm.user')->and($rows[0]['subject_id'])->toBe('2')
        ->and($rows[0]['actor_type'])->toBe('crm.user')->and($rows[0]['actor_id'])->toBe('3')->and($rows[0]['actor_reason'])->toBe('onboarding')
        ->and($rows[0]['correlation_id'])->toBe($result->correlationId)
        ->and($rows[0]['occurred_at'])->toBe('2026-10-06 12:00:00')
        // A MySQL JSON column stores the keys of an object in its own order.
        ->and(sortedKeys(json_decode($rows[0]['payload'], true)))->toBe(sortedKeys(json_decode(json_encode($event->toArray(), JSON_THROW_ON_ERROR), true)));
});

it('writes a row for every change of an operation with one correlation id, and none for a repeat', function (): void {
    $panel = auditedPanel();
    $sync = W::pipeline()->sync($panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(5));
    $again = W::pipeline()->sync($panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(5));
    $rows = EventWorld::auditRows();

    expect($again->applied())->toBeFalse()->and($rows)->toHaveCount(2)
        ->and(array_unique(array_column($rows, 'correlation_id')))->toBe([$sync->correlationId])
        ->and(array_column($rows, 'event_id'))->toBe(array_map(fn ($effect): string => $effect->eventId, $sync->effects));
});

it('shares the transaction of the change: a rollback of the host leaves no row and a commit keeps it', function (bool $commit): void {
    $panel = auditedPanel();
    $this->connection->beginTransaction();
    W::grant($panel, 'analyst', 2, 1);

    expect(EventWorld::auditRows())->toHaveCount(1);
    $commit ? $this->connection->commit() : $this->connection->rollBack();

    expect(EventWorld::auditRows())->toHaveCount($commit ? 1 : 0)->and(EventWorld::events())->toHaveCount($commit ? 1 : 0);
})->with(['commit' => [true], 'rollback' => [false]]);

it('leaves no row when a later pipe cancels after the write, or when the operation fails after an earlier change', function (): void {
    $panel = auditedPanel([static function (Change $change, Closure $next): ChangeResult {
        $result = $next($change);

        if ($change->role?->key() === 'analyst') {
            $change->cancel('after the write');
        }

        return $result;
    }]);
    $keys = W::keys();

    expect(fn () => W::pipeline()->sync($panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(5)))
        ->toThrow(ChangeCancelledException::class)
        ->and(EventWorld::auditRows())->toBe([])->and(EventWorld::events())->toBe([])->and(W::keys())->toBe($keys);
});

it('keeps one row after a retried root', function (): void {
    $attempts = 0;
    $panel = auditedPanel([static function (Change $change, Closure $next) use (&$attempts): ChangeResult {
        $result = $next($change);

        if (++$attempts === 1) {
            throw new QueryException('testbench', 'update azg_panel_state', [], new PDOException('Deadlock found when trying to get lock'));
        }

        return $result;
    }]);
    $result = W::grant($panel, 'analyst', 2, 1);
    $rows = EventWorld::auditRows();

    expect($attempts)->toBe(2)->and($rows)->toHaveCount(1)->and($rows[0]['event_id'])->toBe($result->effects[0]->eventId);
});

it('rolls the change back with it when the journal cannot be written', function (): void {
    W::grant(W::panel(), 'analyst', 3, 1);
    EventWorld::reset();
    $keys = W::keys();
    $version = W::version();
    $failing = auditedPanel([static function (Change $change, Closure $next): ChangeResult {
        $result = $next($change);
        $journal = app(ChangeJournal::class);
        $row = ['event_id' => $result->effects[0]->eventId, 'type' => 'role.granted', 'panel' => 'crm', 'tenant_key' => 'global', 'tenant_type' => null,
            'tenant_id' => null, 'subject_type' => null, 'subject_id' => null, 'actor_type' => null, 'actor_id' => null, 'actor_reason' => null,
            'correlation_id' => $result->correlationId, 'payload' => [], 'occurred_at' => '2026-10-06 12:00:00'];
        $journal->append($row);
        $journal->append($row);

        return $result;
    }]);

    expect(fn () => W::grant($failing, 'analyst', 2, 1))->toThrow(QueryException::class)
        ->and(W::keys())->toBe($keys)->and(W::version())->toBe($version)->and(EventWorld::auditRows())->toBe([])->and(EventWorld::events())->toBe([]);
});

it('refuses a journal write outside the pipes of a change, a malformed row and a row of another panel', function (): void {
    $journal = app(ChangeJournal::class);
    $row = ChangeJournal::row((function () {
        W::grant(auditedPanel(), 'analyst', 2, 1);

        return EventWorld::events()[0];
    })());
    EventWorld::reset();

    expect(fn () => $journal->append($row))->toThrow(UnsupportedDirectWriteException::class);

    $panel = auditedPanel([static function (Change $change, Closure $next) use ($row): ChangeResult {
        $journal = app(ChangeJournal::class);

        try {
            $journal->append([...$row, 'panel' => 'other']);
        } catch (UnsupportedDirectWriteException) {
            $journal->append(array_diff_key($row, ['payload' => 1]));
        }

        return $next($change);
    }]);

    expect(fn () => W::grant($panel, 'analyst', 3, 1))->toThrow(InvalidArgumentException::class)
        ->and(EventWorld::auditRows())->toHaveCount(1);
});

it('returns the writer result untouched', function (): void {
    $results = [];
    $panel = auditedPanel([static function (Change $change, Closure $next) use (&$results): ChangeResult {
        return $results[] = $next($change);
    }]);
    $outcome = W::grant($panel, 'analyst', 2, 1);

    expect($results)->toHaveCount(1)->and($outcome->effects)->toBe($results[0]->effects)->and($outcome->record)->toBe($results[0]->record);
});

it('records a dynamic permission lifecycle and the grants a deletion removed in the payload', function (): void {
    $panel = auditedPanel(dynamic: true);
    W::createAction($panel, 'campaigns.launch', label: 'Launch');
    $grant = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.launch'), W::project(1));
    $deleted = W::deleteAction($panel, 'campaigns.launch');
    $rows = EventWorld::auditRows();
    $payload = json_decode($rows[3]['payload'], true);

    expect(array_column($rows, 'type'))->toBe(['permission.created', 'permission.granted', 'permission.revoked', 'permission.deleted'])
        ->and($rows[0]['subject_type'])->toBeNull()->and($rows[1]['subject_id'])->toBe('2')
        ->and($payload['removed_grants'])->toBe($deleted->removedGrantIds())->and($payload['permission'])->toBe('crm:campaigns.launch')
        ->and($grant->applied())->toBeTrue()->and(array_column($rows, 'event_id'))->toBe(array_map(fn ($event): string => $event->eventId, EventWorld::events()));
});

it('never leaves a journal write to an update of an existing storage root: nested children share one commit', function (): void {
    $panel = auditedPanel();
    CrmWorld::storage()->mutate('crm', function (StorageMutation $mutation) use ($panel): void {
        W::grant($panel, 'analyst', 2, 1);
        W::grant($panel, 'auditor', 2, null);
    });

    expect(EventWorld::auditRows())->toHaveCount(2)->and(array_unique(array_column(EventWorld::auditRows(), 'correlation_id')))->toHaveCount(2);
});

function sortedKeys(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(sortedKeys(...), $value);
}
