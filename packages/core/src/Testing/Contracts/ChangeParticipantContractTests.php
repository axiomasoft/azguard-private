<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\StorageDescriptor;
use AzGuard\Testing\Internal\Contracts\ContractPermission;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use AzGuard\Testing\Internal\Contracts\WriteLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * For the authors of change participants: what every participant guarantees, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application with the AzGuard tables and the tables of the
 * participant migrated on the default storage (with migrations, not inside a wrapping transaction: an operation nested
 * in a host transaction is never retried), and implement `azguardParticipant()`. The suite attaches the participant
 * to the panel `contract` with the database writer and grants a permission.
 *
 * - it declares base names of its own tables, never an authority table of the core;
 * - it writes only through the writer it receives, while `record()` runs, and an effective change succeeds with it;
 * - its rows share the transaction of the change: a later failure leaves none of them behind;
 * - a retried operation calls it again, it writes for the new attempt what it wrote for the first, and only the rows of
 *   the attempt that committed remain;
 * - an operation that changed nothing does not call it.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
trait ChangeParticipantContractTests
{
    /** A new instance of the participant under test. */
    abstract protected function azguardParticipant(): ChangeParticipant;

    /** The subject the suite grants to. */
    protected function azguardSubject(): Model
    {
        return ContractWorld::subject(1);
    }

    #[Test]
    public function participantDeclaresItsOwnTables(): void
    {
        $tables = $this->azguardDeclaredTables();

        $this->assertTrue(array_is_list($tables), 'tables() is not a list.');

        $names = [];

        foreach ($tables as $table) {
            if (! is_string($table) || $table === '') {
                $this->fail('tables() lists '.get_debug_type($table).' where a table name belongs.');
            }
            $this->assertNotContains($table, ['permissions', 'role_grants', 'permission_grants', 'panel_state', 'subject_revisions', 'storage_state'],
                'tables() names the authority table '.$table.' of the core.');
            $names[] = $table;
        }
        $this->assertSame(array_values(array_unique($names)), $names, 'tables() lists a table twice.');
        $this->assertCount(1, $this->azguardParticipantPanel([$this->azguardParticipant()])->participants(), 'The panel does not take the participant.');
    }

    #[Test]
    public function participantWritesOnlyThroughItsWriterWhileRecording(): void
    {
        $probe = $this->azguardParticipantProbe($this->azguardParticipant());
        $outside = [];
        app('events')->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($probe, &$outside): void {
            if ($probe->recording && ! $probe->writing && WriteLog::isWrite($query->sql)) {
                $outside[] = $query->sql;
            }
        });

        try {
            $this->azguardParticipantGrant($this->azguardParticipantPanel([$probe]));
        } catch (Throwable $error) {
            $this->fail('An effective change failed with the participant: '.$error::class.': '.$error->getMessage());
        }

        $this->assertSame(1, $probe->calls, 'The participant was not called once for an effective change.');
        $this->assertSame([], $outside, 'The participant wrote around its writer: '.implode('; ', $outside));
        $writer = $probe->writer;

        if ($writer === null) {
            $this->fail('The participant did not receive a writer.');
        }

        try {
            $writer->insert($this->azguardParticipant()->tables()[0] ?? 'contract_none', [['probe' => 1]]);
            $this->fail('The writer of the participant still writes after record() returned.');
        } catch (UnsupportedDirectWriteException) {
            // The window closed when record() returned.
        }
    }

    #[Test]
    public function participantRowsRollBackWithTheOperation(): void
    {
        $failing = new class implements ChangeParticipant
        {
            public function tables(): array
            {
                return [];
            }

            public function record(array $records, OwnedRowsWriter $rows): void
            {
                throw new RuntimeException('contract: the operation fails after the participant');
            }
        };
        $panel = $this->azguardParticipantPanel([$this->azguardParticipant(), $failing]);
        $before = ContractWorld::rowCounts();
        $failure = null;

        try {
            $this->azguardParticipantGrant($panel);
        } catch (RuntimeException $error) {
            $failure = $error->getMessage();
        }

        $this->assertSame('contract: the operation fails after the participant', $failure, 'The operation did not end with the failure after the participant.');

        $this->assertSame($before, ContractWorld::rowCounts(), 'A failed operation left rows of the participant behind.');
    }

    #[Test]
    public function participantRunsAgainOnARetryAndKeepsOnlyTheCommittedAttempt(): void
    {
        $probe = $this->azguardParticipantProbe($this->azguardParticipant());
        $deadlock = new class(app('db')->connection()->getName() ?? 'default') implements ChangeParticipant
        {
            public int $attempts = 0;

            public function __construct(private readonly string $connection) {}

            public function tables(): array
            {
                return [];
            }

            public function record(array $records, OwnedRowsWriter $rows): void
            {
                if (++$this->attempts === 1) {
                    throw new QueryException($this->connection, 'update panel_state', [], new PDOException('Deadlock found when trying to get lock'));
                }
            }
        };
        $panel = $this->azguardParticipantPanel([$probe, $deadlock]);
        $before = ContractWorld::rowCounts();
        $this->azguardParticipantGrant($panel);
        $after = ContractWorld::rowCounts();

        $this->assertSame(2, $probe->calls, 'The participant was not called again for the retried operation.');
        $this->assertSame($probe->attempts[0], $probe->attempts[1], 'The participant wrote differently for the retried attempt: it keeps state between attempts.');
        $prefix = $probe->writer?->storage()->prefix ?? '';
        $connection = app('db')->connection()->getName() ?? 'default';
        $last = end($probe->attempts) ?: [];

        foreach ($this->azguardParticipant()->tables() as $table) {
            $key = $connection.'.'.$prefix.$table;
            $this->assertSame(($before[$key] ?? 0) + ($last[$table] ?? 0), $after[$key] ?? 0,
                'Table '.$prefix.$table.' does not hold exactly the rows of the attempt that committed.');
        }
    }

    #[Test]
    public function participantIsNotCalledWithoutAnEffect(): void
    {
        $probe = $this->azguardParticipantProbe($this->azguardParticipant());
        $panel = $this->azguardParticipantPanel([$probe]);
        $this->azguardParticipantGrant($panel);
        $before = ContractWorld::rowCounts();
        $this->azguardParticipantGrant($panel);

        $this->assertSame(1, $probe->calls, 'The participant was called for a repeat that changed nothing.');
        $this->assertSame($before, ContractWorld::rowCounts(), 'A repeat that changed nothing wrote rows.');
    }

    /**
     * The tables as the participant declares them: its documented type is what the suite checks, not a fact.
     *
     * @return array<mixed>
     */
    private function azguardDeclaredTables(): array
    {
        return $this->azguardParticipant()->tables();
    }

    /** @param list<ChangeParticipant> $participants */
    private function azguardParticipantPanel(array $participants): Panel
    {
        return ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([DatabaseSource::make()])->participants($participants));
    }

    private function azguardParticipantGrant(Panel $panel): void
    {
        $subject = $this->azguardSubject();
        AzGuard::actingAs('contract', static fn () => AzGuard::panel($panel->id())->for($subject)->grantPermission(ContractPermission::View));
    }

    /**
     * The participant of the author behind a probe that counts its calls, the rows it inserts per attempt and whether
     * a statement comes from its writer.
     *
     * @return ChangeParticipant&object{calls: int, recording: bool, writing: bool, writer: ?OwnedRowsWriter, attempts: list<array<string, int>>}
     */
    private function azguardParticipantProbe(ChangeParticipant $participant): ChangeParticipant
    {
        return new class($participant) implements ChangeParticipant
        {
            public int $calls = 0;

            public bool $recording = false;

            public bool $writing = false;

            public ?OwnedRowsWriter $writer = null;

            /** @var list<array<string, int>> rows inserted per table, one entry per call */
            public array $attempts = [];

            public function __construct(private readonly ChangeParticipant $participant) {}

            public function tables(): array
            {
                return $this->participant->tables();
            }

            public function record(array $records, OwnedRowsWriter $rows): void
            {
                $this->calls++;
                $this->writer = $rows;
                $this->attempts[] = [];
                $attempt = count($this->attempts) - 1;
                $counted = new readonly class($rows, function (Closure $insert, string $table, int $count) use ($attempt): void {
                    $this->writing = true;

                    try {
                        $insert();
                        $this->attempts[$attempt][$table] = ($this->attempts[$attempt][$table] ?? 0) + $count;
                    } finally {
                        $this->writing = false;
                    }
                }) implements OwnedRowsWriter
                {

                    /** @param Closure(Closure(): void, string, int): void $through */
                    public function __construct(private OwnedRowsWriter $rows, private Closure $through) {}

                    public function storage(): StorageDescriptor
                    {
                        return $this->rows->storage();
                    }

                    public function insert(string $table, array $rows): void
                    {
                        ($this->through)(fn () => $this->rows->insert($table, $rows), $table, count($rows));
                    }
                };
                $this->recording = true;

                try {
                    $this->participant->record($records, $counted);
                } finally {
                    $this->recording = false;
                }
            }
        };
    }
}
