<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Events\AccessDecided;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Backoffice\BackofficePanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

/** @param list<mixed>|null $sources */
function tracedPanel(bool $trace, ?array $sources = null): Panel
{
    $panel = W::panel(sources: $sources);
    $registry = new PanelRegistry(app(), new PanelCompiler(static fn (): array => ['trace_decisions' => $trace]));
    $registry->register(CrmGuardPanelProvider::class);
    $registry->register(BackofficePanelProvider::class);
    $registry->freeze();
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return $registry->get($panel->id());
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

it('publishes the decision with its values when the panel traces decisions', function (): void {
    $panel = tracedPanel(true);
    $version = W::version();
    $decision = CrmWorld::decide($panel, 1, ClientPermission::View, 3, actor: ActorRef::of('crm.user', 3, 'check'));
    $event = EventWorld::events()[0] ?? null;

    expect(EventWorld::types())->toBe(['access.decided'])->and($event)->toBeInstanceOf(AccessDecided::class)
        ->and($event->subject->key())->toBe('crm.user:3')->and($event->permission->full())->toBe('crm:'.ClientPermission::View->value)
        ->and($event->effect)->toBe($decision->effect)->and($event->reason)->toBe($decision->reason)->and($event->component)->toBe($decision->component)
        ->and($event->tenant->key())->toBe('crm.organization:1')->and($event->context->key())->toBe($decision->scope->context->key())
        ->and($event->actor?->reason)->toBe('check')->and($event->state)->toBe($decision->state)
        ->and(EventWorld::plain($event->toArray()))->toBeTrue()->and($event->toArray()['effect'])->toBe($decision->effect->value)
        ->and(W::version())->toBe($version);
});

it('builds and dispatches nothing when the panel does not trace', function (): void {
    $panel = tracedPanel(false);
    CrmWorld::decide($panel, 1, ClientPermission::View, 3);
    CrmWorld::decide($panel, 1, ClientPermission::View, 1);

    expect(EventWorld::events())->toBe([]);
});

it('publishes a decision for explain and for every decision of a batch', function (): void {
    $panel = tracedPanel(true);
    $authorizer = app(Authorizer::class);
    $request = static fn (int $user): AccessRequest => AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of('crm', ClientPermission::View->value))
        ->inTenant(CrmWorld::scope()->tenant);
    $authorizer->explain($panel, $request(3));
    $set = $authorizer->decideMany([$request(1), $request(3)]);

    expect(EventWorld::types())->toBe(['access.decided', 'access.decided', 'access.decided'])
        ->and(array_map(fn (AccessDecided $event): string => $event->subject->key(), EventWorld::events()))->toBe(['crm.user:3', 'crm.user:1', 'crm.user:3'])
        ->and(EventWorld::events()[1]->effect)->toBe($set->get(0)->effect);
});

it('reports a failing listener and keeps the decision', function (): void {
    $panel = tracedPanel(true);
    Event::listen(AccessDecided::class, static fn (): never => throw new RuntimeException('tracing listener failed'));

    $decision = CrmWorld::decide($panel, 1, ClientPermission::View, 3);

    expect($decision->effect)->toBeInstanceOf(Effect::class);
});

it('publishes a decision made inside a transaction of the package only after its commit', function (bool $commit): void {
    $panel = tracedPanel(true);
    $inside = null;

    try {
        CrmWorld::storage()->mutate('crm', function () use ($panel, $commit, &$inside): void {
            CrmWorld::decide($panel, 1, ClientPermission::View, 3);
            $inside = EventWorld::events();

            if (! $commit) {
                throw new RuntimeException('abort');
            }
        });
    } catch (RuntimeException) {
    }

    expect($inside)->toBe([])->and(EventWorld::types())->toBe($commit ? ['access.decided'] : [])->and(EventWorld::levels())->toBe($commit ? [0] : []);
})->with(['commit' => [true], 'rollback' => [false]]);

it('opens no storage read for a policy-only panel that traces, and never raises the version', function (): void {
    $panel = tracedPanel(true, [new ArraySource('code')]);
    $client = Client::query()->findOrFail(1);
    $version = W::version();
    $statements = [];
    $this->connection->listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, 'azg_')) {
            $statements[] = $query->sql;
        }
    });
    $decision = CrmWorld::decide($panel, $client, ClientPermission::View, 3);

    expect($statements)->toBe([])->and(W::version())->toBe($version)->and(EventWorld::types())->toBe(['access.decided'])
        ->and(EventWorld::events()[0]->state)->toBe($decision->state);
});
