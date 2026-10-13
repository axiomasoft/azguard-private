<?php

declare(strict_types=1);

use AzGuard\Audit\Internal\JournalRow;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventType;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Baseline070\Formats\EventSamples;
use AzGuard\Tests\Fixtures\Baseline070\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;

/*
 * I17, blocking now: the event types, the `AccessEvent::toArray()` envelope and data keys with the exact `state`
 * keys, and the journal row the Audit plugin writes for an event, as 0.7.0 has them. Values of the payloads are pinned
 * by tests/Feature/Events/CatalogTest; this pins the keys per class and the journal row.
 */

/** @param array<mixed> $value */
function baselineSortedKeys(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map(static fn (mixed $item): mixed => is_array($item) ? baselineSortedKeys($item) : $item, $value);
}

it('keeps the type, class, envelope, data and state keys of every event', function (): void {
    $envelope = ['event_id', 'type', 'occurred_at', 'panel', 'tenant', 'actor', 'correlation_id', 'state'];
    $events = [];

    foreach (EventSamples::all() as $type => $event) {
        $payload = $event->toArray();
        $events[$type] = [
            'case' => $event->type()->name,
            'class' => $event::class,
            'envelope' => array_slice(array_keys($payload), 0, 8),
            'data' => array_slice(array_keys($payload), 8),
            'tenant' => array_keys($payload['tenant']),
            'actor' => array_keys($payload['actor']),
            'state' => array_keys($payload['state']),
        ];

        expect(array_slice(array_keys($payload), 0, 8))->toBe($envelope);
    }
    $snapshot = ['types' => array_map(static fn (EventType $type): array => ['case' => $type->name, 'type' => $type->value], EventType::cases()),
        'events' => $events];
    [$actual, $stored] = FormatSnapshot::compare('events', $snapshot);

    expect(array_keys($events))->toBe(array_map(static fn (EventType $type): string => $type->value, EventType::cases()))
        ->and($actual)->toBe($stored);
});

it('keeps the journal row of every event: its columns and the payload equal to toArray()', function (): void {
    $rows = [];

    foreach (EventSamples::all() as $type => $event) {
        $row = JournalRow::of($event);

        expect($row['payload'])->toBe($event->toArray());
        $rows[$type] = $row;
    }
    [$actual, $stored] = FormatSnapshot::compare('audit-rows', ['columns' => JournalRow::COLUMNS, 'rows' => $rows]);

    expect($actual)->toBe($stored);
});

describe('the journal rows the Audit plugin writes', function (): void {
    beforeEach(function (): void {
        CrmWorld::seed();
        EventWorld::listen();
    });
    afterEach(function (): void {
        CrmWorld::resetRuntime();
        EventWorld::reset();
    });

    it('writes for every change event exactly the row of the published event', function (): void {
        $panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]),
            sources: [CrmWorld::database()->dynamicPermissions()]);
        $actor = ActorRef::of('crm.user', 3, 'baseline');
        $soon = new DateTimeImmutable('2026-10-07T00:00:00Z');

        W::createAction($panel, 'campaigns.launch', label: 'Launch', actor: $actor);
        W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.launch', new PermissionDetails('Launch now', 'Marketing', null, []), $actor);
        W::grant($panel, 'analyst', 2, 1, fields: ['region' => 'R1'], actor: $actor);
        W::grant($panel, 'analyst', 2, 1, until: $soon, fields: ['region' => 'R1'], actor: $actor);
        W::revoke($panel, 'analyst', 2, 1);
        W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project(), actor: $actor);
        W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project(), until: $soon, actor: $actor);
        W::pipeline()->revoke($panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project());
        W::deleteAction($panel, 'campaigns.launch');
        W::pipeline()->touch($panel, 'baseline', ActorRef::system('deploy'));
        W::grant($panel, 'seller', 1, 5, until: $soon);
        W::pipeline()->pruneExpired($panel, null, new DateTimeImmutable('2026-10-08T00:00:00Z'), actor: ActorRef::system('azguard:grants:prune'));

        $events = array_values(array_filter(EventWorld::events(), static fn (AccessEvent $event): bool => $event->type() !== EventType::AccessDecided));
        $written = array_map(static fn (array $row): array => [...array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value,
            array_intersect_key($row, array_flip(JournalRow::COLUMNS))), 'payload' => baselineSortedKeys(json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR))],
            EventWorld::auditRows());
        $expected = array_map(static function (AccessEvent $event): array {
            $row = JournalRow::of($event);

            return [...array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, array_diff_key($row, ['payload' => true])),
                'payload' => baselineSortedKeys(json_decode(json_encode($row['payload'], JSON_THROW_ON_ERROR), true))];
        }, $events);
        $sort = static fn (array $rows): array => array_map(static fn (array $row): array => baselineSortedKeys($row), $rows);
        [$actual, $stored] = FormatSnapshot::compare('audit-journaled-types', array_map(static fn (AccessEvent $event): string => $event->type()->value, $events));

        expect($sort($written))->toBe($sort($expected))
            ->and($actual)->toBe($stored);
    });
});
