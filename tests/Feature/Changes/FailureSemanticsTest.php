<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeRecord;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Internal\StorageTouched;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;

/*
 * Failure semantics of a change (docs/advanced/consistency.md § Failure semantics, knowledge/qualification#failures):
 * a participant that fails rolls the whole change back (no rows, no bump, no event, no journal row); a listener that
 * fails after the commit leaves the state committed and surfaces its exception.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    $connection = CrmWorld::storage()->connection();
    $connection->getSchemaBuilder()->create(CrmWorld::storage()->prefix().'failure_rows', static function (Blueprint $table): void {
        $table->string('event_id');
    });
    EventWorld::listen();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
});

/** A participant that journals the records, then fails. */
function failingJournal(): ChangeParticipant
{
    return new class implements ChangeParticipant
    {
        public function tables(): array
        {
            return ['failure_rows'];
        }

        public function record(array $records, OwnedRowsWriter $rows): void
        {
            $rows->insert('failure_rows', array_map(static fn (ChangeRecord $record): array => ['event_id' => $record->event->eventId], $records));

            throw new RuntimeException('journal sink failed');
        }
    };
}

it('rolls the whole change back when a participant fails after writing its rows', function (): void {
    $panel = W::panel([], static fn (PanelBuilder $builder): PanelBuilder => $builder->participants([failingJournal()]));
    $version = W::version();
    $keys = W::keys();

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(RuntimeException::class, 'journal sink failed')
        ->and(W::version())->toBe($version)
        ->and(W::keys())->toBe($keys)
        ->and(EventWorld::events())->toBe([])
        ->and(CrmWorld::storage()->connection()->table(CrmWorld::storage()->prefix().'failure_rows')->count())->toBe(0);
});

it('keeps the committed state and surfaces the exception of a listener that fails after the commit', function (): void {
    $panel = W::panel();
    $version = W::version();
    Event::listen(StorageTouched::class, static function (): never {
        throw new RuntimeException('listener failed');
    });

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(RuntimeException::class, 'listener failed')
        ->and(W::version())->toBe($version + 1)
        ->and(W::keys())->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});
