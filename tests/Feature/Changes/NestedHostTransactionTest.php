<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Support\AuditTable;

/*
 * Read / writer / host invariant map:
 * Read snapshot: one PDO and repeatable read; BatchFenceRaceTest interleaves a committed revoke between chunks.
 * Writer: panel_state lock precedes grant/action changes, version and subject revision or epoch advance on commit.
 * Host: the lock and journal remain in the outer transaction; savepoint rollback drops its rows and callbacks.
 * Joint host writes require that same connection and lock order; another connection is not made atomic by a wrapper.
 */
beforeEach(function (): void {
    CrmWorld::seed();
    AuditTable::create();
    EventWorld::listen();
    $this->panel = W::panel(configure: static fn ($panel) => $panel->plugins([AuditPlugin::make()]));
    $this->connection = CrmWorld::storage()->connection();
});

afterEach(function (): void {
    if ($this->connection->transactionLevel() > 0) {
        $this->connection->rollBack(0);
    }
    EventWorld::reset();
    CrmWorld::resetRuntime();
});

it('discards an inner savepoint while publishing only the outer committed grant and its journal', function (): void {
    $version = W::version();
    $before = W::keys();
    $epoch = CrmWorld::storage()->state('crm')->epoch;
    $this->connection->beginTransaction();
    $kept = W::grant($this->panel, 'analyst', 2, 1);
    $this->connection->beginTransaction();
    $discarded = W::grant($this->panel, 'auditor', 2, null);
    expect($kept->committed)->toBeFalse()->and($discarded->committed)->toBeFalse()
        ->and(EventWorld::events())->toBeEmpty();
    $this->connection->rollBack();
    expect(EventWorld::events())->toBeEmpty();
    $this->connection->commit();

    expect(W::version())->toBe($version + 1)
        ->and(CrmWorld::storage()->state('crm')->epoch)->toBe($epoch)
        ->and((int) CrmWorld::storage()->table('subject_revisions')->where('panel', 'crm')->where('subject_type', 'crm.user')->where('subject_id', '2')->value('revision'))->toBe(1)
        ->and(W::keys())->toContain('crm.organization:1|analyst|2|crm.project:1|manual')
        ->and(W::keys())->not->toContain('crm.organization:1|auditor|2|global|manual')
        ->and(W::keys())->toHaveCount(count($before) + 1)
        ->and(EventWorld::auditRows())->toHaveCount(1)
        ->and(EventWorld::events())->toHaveCount(1)
        ->and(EventWorld::levels())->toBe([0])
        ->and(EventWorld::auditRows()[0]['event_id'])->toBe($kept->effects[0]->eventId)
        ->and(EventWorld::events()[0]->eventId)->toBe($kept->effects[0]->eventId)
        ->and(json_decode(EventWorld::auditRows()[0]['payload'], true, flags: JSON_THROW_ON_ERROR))->toEqual(EventWorld::events()[0]->toArray());
});

it('discards released savepoints and their notifications when the host rolls back', function (): void {
    $version = W::version();
    $before = W::keys();
    $state = CrmWorld::storage()->state('crm');
    $this->connection->beginTransaction();
    $this->connection->beginTransaction();
    $result = W::grant($this->panel, 'analyst', 2, 1);
    $this->connection->commit();
    expect($result->committed)->toBeFalse()->and(EventWorld::events())->toBeEmpty();
    $this->connection->rollBack();

    expect(W::version())->toBe($version)->and(W::keys())->toBe($before)
        ->and(CrmWorld::storage()->state('crm')->epoch)->toBe($state->epoch)
        ->and(CrmWorld::storage()->table('subject_revisions')->where('panel', 'crm')->where('subject_type', 'crm.user')->where('subject_id', '2')->count())->toBe(0)
        ->and(EventWorld::auditRows())->toBeEmpty()->and(EventWorld::events())->toBeEmpty();
});
