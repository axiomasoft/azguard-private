<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Events\GrantExpired;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
    $this->soon = new DateTimeImmutable('2026-10-06T13:00:00Z');
    $this->later = new DateTimeImmutable('2026-10-07T12:00:00Z');
    $this->clock = new DateTimeImmutable('2026-10-06T14:00:00Z');
    W::grant($this->panel, 'analyst', 2, 1, until: $this->soon);
    W::pipeline()->grant($this->panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project(), until: $this->soon);
    W::grant($this->panel, 'analyst', 3, 1, until: $this->later);
    W::grant($this->panel, 'analyst', 1, 4, tenant: 2, until: $this->soon);
    W::grant($this->panel, 'seller', 1, 5, until: $this->soon, origin: 'import');
    EventWorld::listen();
    $this->stored = count(W::rows('role')) + count(W::rows('permission'));
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
});

it('removes only the expired grants of every tenant and origin and publishes GrantExpired for each, after the commit', function (): void {
    $version = W::version();
    $removed = W::pipeline()->pruneExpired($this->panel, null, $this->clock);
    $expired = EventWorld::events();

    expect($removed)->toBe(4)->and(count(W::rows('role')) + count(W::rows('permission')))->toBe($this->stored - 4)
        ->and(W::keys())->toContain('crm.organization:1|analyst|3|crm.project:1|manual')
        ->and(W::keys('permission'))->toBe([])
        ->and(EventWorld::types())->toBe(array_fill(0, 4, 'grant.expired'))->and(EventWorld::levels())->toBe([0, 0, 0, 0])
        ->and($expired[0])->toBeInstanceOf(GrantExpired::class)->and($expired[0]->kind)->toBe('role')
        ->and($expired[0]->role?->key())->toBe('analyst')->and($expired[0]->permission)->toBeNull()
        ->and($expired[0]->expiredAt?->format('c'))->toBe('2026-10-06T13:00:00+00:00')
        ->and(collect($expired)->pluck('kind')->all())->toContain('permission')
        ->and(collect($expired)->pluck('origin')->unique()->sort()->values()->all())->toBe(['import', 'manual'])
        ->and(collect($expired)->map(fn (GrantExpired $event): string => $event->tenant->key())->unique()->sort()->values()->all())
        ->toBe(['crm.organization:1', 'crm.organization:2'])
        ->and(W::version())->toBe($version + 2)
        ->and($expired[0]->actor?->reason)->toBe('prune');
});

it('narrows the run to one tenant', function (): void {
    $removed = W::pipeline()->pruneExpired($this->panel, W::tenant(2), $this->clock);

    expect($removed)->toBe(1)->and(count(W::rows('role')) + count(W::rows('permission')))->toBe($this->stored - 1)
        ->and(W::keys())->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});

it('removes a batch at a time, each batch its own mutation, and counts every grant', function (): void {
    $version = W::version();
    $removed = W::pipeline()->pruneExpired($this->panel, W::tenant(), $this->clock, batch: 1);

    expect($removed)->toBe(3)->and(W::version())->toBe($version + 3)->and(EventWorld::types())->toBe(array_fill(0, 3, 'grant.expired'))
        ->and(fn () => W::pipeline()->pruneExpired($this->panel, null, $this->clock, batch: 0))->toThrow(InvalidConfigurationException::class);
});

it('has nothing to do when nothing has expired: no bump, no event, no row', function (): void {
    $version = W::version();

    expect(W::pipeline()->pruneExpired($this->panel, null, new DateTimeImmutable('2026-10-06T12:30:00Z')))->toBe(0)
        ->and(W::version())->toBe($version)->and(EventWorld::events())->toBe([]);
});

it('treats a grant that expires exactly now as expired and a later one as alive', function (): void {
    expect(W::pipeline()->pruneExpired($this->panel, W::tenant(), $this->soon))->toBe(3)
        ->and(W::pipeline()->pruneExpired($this->panel, W::tenant(), $this->soon))->toBe(0)
        ->and(W::keys())->toContain('crm.organization:1|analyst|3|crm.project:1|manual');
});

it('sends each removal through the changing pipes as a revocation and lets a pipe cancel the run', function (): void {
    $seen = [];
    $panel = W::panel([static function (Change $change, Closure $next) use (&$seen): ChangeResult {
        $seen[] = [$change->type->value, $change->expired, $change->context()->phase->value];

        return $next($change);
    }]);
    W::pipeline()->pruneExpired($panel, W::tenant(2), $this->clock);

    expect($seen)->toBe([['revoke_role', true, 'revocation']]);

    $cancelling = W::panel([static fn (Change $change, Closure $next): ChangeResult => $change->expired ? $change->cancel('keep it') : $next($change)]);
    $stored = [W::rows('role'), W::rows('permission')];

    expect(fn () => W::pipeline()->pruneExpired($cancelling, W::tenant(), $this->clock))->toThrow(ChangeCancelledException::class)
        ->and([W::rows('role'), W::rows('permission')])->toBe($stored);
});

it('records an expiry in the journal with the same event id when the audit plugin is on', function (): void {
    $panel = W::panel(configure: static fn ($builder) => $builder->plugins([AuditPlugin::make()]));
    W::pipeline()->pruneExpired($panel, W::tenant(2), $this->clock, actor: ActorRef::system('azguard:grants:prune'));
    $rows = EventWorld::auditRows();

    expect($rows)->toHaveCount(1)->and(array_column($rows, 'type'))->toBe(['grant.expired'])
        ->and(array_column($rows, 'event_id'))->toBe(array_map(fn (GrantExpired $event): string => $event->eventId, EventWorld::events()))
        ->and($rows[0]['actor_reason'])->toBe('azguard:grants:prune');
});
