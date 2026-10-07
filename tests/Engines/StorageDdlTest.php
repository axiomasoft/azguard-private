<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Fixtures\Storage\DdlSnapshot;

it('matches actual engine columns indexes constraints and collations for all host key types', function (): void {
    DdlSnapshot::verify();
})->group('engines');

it('writes the journal in the transaction of the change, prunes expired grants and touches the panel on the engine', function (): void {
    $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
    CrmWorld::seed();

    try {
        EventWorld::listen();
        $panel = W::panel(configure: static fn ($builder) => $builder->plugins([AuditPlugin::make()]));
        $until = new DateTimeImmutable('2026-10-06T13:00:00Z');
        $granted = W::grant($panel, 'analyst', 2, 1, until: $until, actor: ActorRef::of('crm.user', 3, 'onboarding'));
        $connection = CrmWorld::storage()->connection();
        $connection->beginTransaction();
        W::grant($panel, 'auditor', 2, null);
        $connection->rollBack();
        $state = W::pipeline()->touch($panel, 'engine touch');
        $removed = W::pipeline()->pruneExpired($panel, null, new DateTimeImmutable('2026-10-06T14:00:00Z'));
        $rows = EventWorld::auditRows();

        expect($removed)->toBe(1)->and(array_column($rows, 'type'))->toBe(['role.granted', 'panel.touched', 'grant.expired'])
            ->and($rows[0]['event_id'])->toBe($granted->effects[0]->eventId)
            ->and(array_column($rows, 'event_id'))->toBe(array_map(fn ($event): string => $event->eventId, EventWorld::events()))
            ->and($rows[0]['tenant_id'])->toBe('1')->and($rows[0]['subject_id'])->toBe('2')->and($rows[0]['actor_reason'])->toBe('onboarding')
            ->and(json_decode($rows[1]['payload'], true)['previous_version'])->toBe($state->version - 1)
            ->and(EventWorld::levels())->toBe([0, 0, 0]);
    } finally {
        EventWorld::reset();
        foreach (['crm_weight', 'crm_active_build', 'crm_project_revisions', 'clients', 'project_members', 'projects', 'organization_user', 'users', 'cities', 'organizations'] as $table) {
            $schema->dropIfExists($table);
        }
        app(StorageSchema::class)->drop('default');
        CrmWorld::resetRuntime();
    }
})->group('engines');
