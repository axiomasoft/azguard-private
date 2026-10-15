<?php

declare(strict_types=1);

use AzGuard\Audit\Internal\JournalRow;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeRecord;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\Internal\OperationRows;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Database\QueryException;

/*
 * I6 and I11 at the participant: the writer a participant receives appends only to its declared tables, with scalar
 * values, while record() runs; core calls participants once per operation with effects, in declaration order, inside
 * the transaction, and again on a retry.
 */

/**
 * A participant that runs `$record` and keeps every call.
 *
 * @param  list<string>  $tables
 * @param  (Closure(list<ChangeRecord>, OwnedRowsWriter): void)|null  $record
 */
function participantProbe(array $tables = [], ?Closure $record = null): ChangeParticipant
{
    return new class($tables, $record) implements ChangeParticipant
    {
        /** @var list<list<ChangeRecord>> */
        public array $calls = [];

        public ?OwnedRowsWriter $writer = null;

        /** @param list<string> $tables */
        public function __construct(private readonly array $tables, private readonly ?Closure $record) {}

        public function tables(): array
        {
            return $this->tables;
        }

        public function record(array $records, OwnedRowsWriter $rows): void
        {
            $this->calls[] = $records;
            $this->writer = $rows;

            if ($this->record !== null) {
                ($this->record)($records, $rows);
            }
        }
    };
}

/** @param list<ChangeParticipant|class-string<ChangeParticipant>> $participants */
function participantPanel(array $participants, array $pipes = []): Panel
{
    return W::panel($pipes, static fn (PanelBuilder $builder): PanelBuilder => $builder->participants($participants));
}

/** One journal row per record, written by a probe that declares the journal table. */
function journalingProbe(): ChangeParticipant
{
    return participantProbe(['audit_log'], static function (array $records, OwnedRowsWriter $rows): void {
        $rows->insert('audit_log', array_map(static fn (ChangeRecord $record): array => JournalRow::stored($record->event, $rows->storage()), $records));
    });
}

beforeEach(function (): void {
    CrmWorld::seed();
    AuditTable::create();
    EventWorld::listen();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
});

describe('compilation', function (): void {
    afterEach(fn () => ContractWorld::reset());

    it('refuses participants on a panel without the database writer', function (): void {
        expect(fn () => ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->participants([participantProbe()])))
            ->toThrow(DefinitionException::class, 'participants need the database writer');
    });

    it('refuses an authority table of the core and a table two participants declare', function (string $table, array $others): void {
        expect(fn () => participantPanel([...$others, participantProbe([$table])]))->toThrow(DefinitionException::class);
    })->with([
        'permissions' => ['permissions', []],
        'role grants' => ['role_grants', []],
        'permission grants' => ['permission_grants', []],
        'panel state' => ['panel_state', []],
        'subject revisions' => ['subject_revisions', []],
        'storage state' => ['storage_state', []],
        'shared table' => ['audit_log', [participantProbe(['audit_log'])]],
    ]);

    it('refuses a table name that is not one canonical lower-case base name', function (string $table): void {
        expect(fn () => participantPanel([participantProbe([$table])]))->toThrow(DefinitionException::class, 'lower-case ASCII');
    })->with(['upper-case core table' => 'ROLE_GRANTS', 'mixed-case core table' => 'Role_Grants', 'qualified' => 'main.rows', 'quoted' => '"rows"', 'with a space' => 'my rows', 'alias' => 'rows as r', 'leading digit' => '1rows', 'leading underscore' => '_rows']);

    it('refuses a table two participants declare in different case', function (): void {
        expect(fn () => participantPanel([participantProbe(['audit_log']), participantProbe(['AUDIT_LOG'])]))->toThrow(DefinitionException::class);
    });

    it('refuses a value that is not a participant', function (mixed $participant): void {
        expect(fn () => participantPanel([$participant]))->toThrow(DefinitionException::class, 'participants() expects');
    })->with(['closure' => [fn (): Closure => static fn (): null => null], 'object' => [fn (): stdClass => new stdClass], 'other class' => [stdClass::class], 'empty name' => ['']]);

    it('keeps a participant named by its class and builds it through the container for every operation', function (): void {
        $built = [];
        app()->bind(ProbeParticipant::class, static function () use (&$built): ProbeParticipant {
            return $built[] = new ProbeParticipant('built');
        });
        $panel = participantPanel([ProbeParticipant::class]);

        expect($panel->participants())->toBe([['participant' => ProbeParticipant::class, 'tables' => ['probe_rows']]])->and($built)->toHaveCount(1);

        W::grant($panel, 'analyst', 2, 1);
        W::grant($panel, 'analyst', 3, 1);

        expect($built)->toHaveCount(3)->and($built[2])->not->toBe($built[1]);
    });

    it('rolls the operation back when the container builds no participant for a declared class', function (): void {
        $panel = participantPanel([ProbeParticipant::class]);
        $keys = W::keys();
        app()->bind(ProbeParticipant::class, static fn (): stdClass => new stdClass);

        expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(DefinitionException::class, 'did not build')
            ->and(W::keys())->toBe($keys)->and(EventWorld::events())->toBe([]);
    });
});

it('calls each participant once per operation with effects, in declaration order, with the records in effect order', function (): void {
    $order = [];
    $first = participantProbe(record: static function () use (&$order): void {
        $order[] = 'first';
    });
    $second = participantProbe(record: static function () use (&$order): void {
        $order[] = 'second';
    });
    $panel = participantPanel([$first, $second]);
    $result = W::pipeline()->sync($panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(5));

    expect($order)->toBe(['first', 'second'])->and($first->calls)->toHaveCount(1)->and($second->calls)->toBe($first->calls)
        ->and(array_map(static fn (ChangeRecord $record) => $record->effect, $first->calls[0]))->toBe($result->effects)
        ->and(array_map(static fn (ChangeRecord $record) => $record->event, $first->calls[0]))->toBe(EventWorld::events());
});

it('does not call a participant for an operation without effects', function (): void {
    $probe = participantProbe();
    $panel = participantPanel([$probe]);
    W::grant($panel, 'analyst', 2, 1);
    W::grant($panel, 'analyst', 2, 1);
    W::revoke($panel, 'seller', 9, 1);

    expect($probe->calls)->toHaveCount(1);
});

it('hands the record the final change the pipes passed to the writer', function (): void {
    $probe = participantProbe();
    $until = new DateTimeImmutable('2030-01-01T00:00:00Z');
    $panel = participantPanel([$probe], [static fn (Change $change, Closure $next): ChangeResult => $next($change->withUntil($until))]);
    W::grant($panel, 'analyst', 2, 1);

    expect($probe->calls[0][0]->change->until)->toEqual($until);
});

it('closes the writer when record() returns', function (): void {
    $probe = journalingProbe();
    W::grant(participantPanel([$probe]), 'analyst', 2, 1);

    expect(fn () => $probe->writer?->insert('audit_log', [['event_id' => 'x']]))->toThrow(UnsupportedDirectWriteException::class, 'only while record() runs')
        ->and(EventWorld::auditRows())->toHaveCount(1);
});

it('refuses an undeclared table, a non-scalar value and a malformed row list, rolling the operation back', function (string $table, array $rows, string $message): void {
    $keys = W::keys();
    $panel = participantPanel([participantProbe(['audit_log'], static fn (array $records, OwnedRowsWriter $writer) => $writer->insert($table, $rows))]);

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(UnsupportedDirectWriteException::class, $message)
        ->and(W::keys())->toBe($keys)->and(EventWorld::events())->toBe([])->and(EventWorld::auditRows())->toBe([]);
})->with([
    'core table' => ['role_grants', [['role' => 'x']], 'only to the tables it declares'],
    'other table' => ['audit_logs', [['event_id' => 'x']], 'only to the tables it declares'],
    'array value' => ['audit_log', [['payload' => ['a' => 1]]], 'takes a scalar or null'],
    'object value' => ['audit_log', [['occurred_at' => new DateTimeImmutable]], 'takes a scalar or null'],
    'no rows' => ['audit_log', [], 'non-empty list of rows'],
    'map of rows' => ['audit_log', ['first' => ['event_id' => 'x']], 'non-empty list of rows'],
    'empty row' => ['audit_log', [[]], 'non-empty map of columns'],
    'column by position' => ['audit_log', [['x']], 'takes a scalar or null'],
]);

it('gives no builder, connection or mutation to a participant', function (): void {
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(OperationRows::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($methods);

    expect($methods)->toBe(['__construct', 'close', 'insert', 'storage']);
});

it('runs participants again on a retried root and keeps only the rows of the committed attempt', function (): void {
    $probe = journalingProbe();
    $attempts = 0;
    $panel = participantPanel([$probe, participantProbe(record: static function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new QueryException('testbench', 'update azg_panel_state', [], new PDOException('Deadlock found when trying to get lock'));
        }
    })]);
    $result = W::grant($panel, 'analyst', 2, 1);
    $rows = EventWorld::auditRows();

    expect($probe->calls)->toHaveCount(2)->and($probe->calls[0][0]->event->eventId)->not->toBe($probe->calls[1][0]->event->eventId)
        ->and($rows)->toHaveCount(1)->and($rows[0]['event_id'])->toBe($result->effects[0]->eventId)
        ->and(array_map(static fn ($event): string => $event->eventId, EventWorld::events()))->toBe([$result->effects[0]->eventId]);
});

it('writes on the storage of the panel with its prefix and canonical host keys', function (): void {
    $probe = journalingProbe();
    W::grant(participantPanel([$probe]), 'analyst', 2, 1);
    $storage = $probe->writer?->storage();

    expect($storage?->id)->toBe(CrmWorld::storage()->id())->and($storage?->prefix)->toBe(CrmWorld::storage()->prefix())
        ->and(EventWorld::auditRows()[0]['subject_id'])->toBe('2');
});

final class ProbeParticipant implements ChangeParticipant
{
    public function __construct(public readonly string $origin = 'new') {}

    public function tables(): array
    {
        return ['probe_rows'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void {}
}
