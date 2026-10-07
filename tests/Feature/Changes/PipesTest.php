<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\RoleNotGrantableException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\ChangingPlugin;
use AzGuard\Tests\Fixtures\Changes\RecordingPipe;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Models\User;

beforeEach(function (): void {
    CrmWorld::seed();
    RecordingPipe::$log = [];
});
afterEach(fn () => CrmWorld::resetRuntime());

it('runs pipes in the order provider, plugins, configure and resolves class pipes through the container', function (): void {
    CrmWorld::$sources = [CrmWorld::database()];
    CrmWorld::$configure = static fn (PanelBuilder $panel) => $panel->roles([RootRole::class, AuditorRole::class, SupportRole::class])
        ->changing([static function (Change $change, Closure $next): ChangeResult {
            RecordingPipe::$log[] = 'provider:'.$change->type->value;

            return $next($change);
        }, RecordingPipe::class])->plugins([new ChangingPlugin]);
    $registry = new PanelRegistry(app());
    $registry->register(CrmGuardPanelProvider::class);
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->changing([new RecordingPipe('configure')]));
    $registry->freeze();
    app()->instance(PanelRegistry::class, $registry);
    $panel = $registry->get('crm');

    W::grant($panel, 'analyst', 2, 1);

    expect(RecordingPipe::$log)->toBe(['provider:grant_role', 'class:grant_role', 'plugin:grant_role', 'configure:grant_role'])
        ->and(count($panel->changing()))->toBe(4);
});

it('rejects a changing item that is not a pipe when the panel compiles', function (mixed $pipe): void {
    expect(fn () => W::panel([$pipe]))->toThrow(DefinitionException::class);
})->with([
    'unknown class' => ['App\Pipes\Missing'],
    'class without handle' => [User::class],
    'object without handle' => [new stdClass],
]);

it('cancels the whole operation from a pipe with the reason', function (): void {
    $panel = W::panel([static fn (Change $change, Closure $next) => $change->cancel('Укажите причину выдачи')]);
    $version = W::version();

    try {
        W::grant($panel, 'analyst', 2, 1);
        $this->fail('Expected a cancellation.');
    } catch (ChangeCancelledException $error) {
        expect($error->reason())->toBe('Укажите причину выдачи')->and($error->code())->toBe('change_cancelled');
    }

    expect(W::version())->toBe($version)->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});

it('stores expiry and fields set by pipes after validating them again', function (): void {
    $panel = W::panel([
        static fn (Change $c, Closure $next) => $next($c->type === ChangeType::GrantRole && $c->until === null
            ? $c->withUntil(new DateTimeImmutable('2027-01-04T12:00:00Z')) : $c),
        static fn (Change $c, Closure $next) => $next($c->withFields([...$c->fields, 'region' => 'R1'])),
    ]);
    $record = W::grant($panel, 'analyst', 2, 1)->record;

    expect($record?->until?->format(DATE_ATOM))->toBe('2027-01-04T12:00:00+00:00')
        ->and($record?->fields)->toBe(['eligible' => null, 'region' => 'R1']);
});

it('rejects invalid expiry or fields that a pipe proposes', function (Closure $pipe): void {
    $panel = W::panel([$pipe]);

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(InvalidChangeFieldsException::class)
        ->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
})->with([
    'past expiry' => [static fn (Change $c, Closure $next) => $next($c->withUntil(new DateTimeImmutable('2020-01-01T00:00:00Z')))],
    'unknown field' => [static fn (Change $c, Closure $next) => $next($c->withFields(['department' => 1]))],
]);

it('gives a pipe a fresh checked context for every derived change', function (): void {
    $seen = [];
    $panel = W::panel([static function (Change $c, Closure $next) use (&$seen): ChangeResult {
        $first = $c->context();
        $later = $c->withUntil(new DateTimeImmutable('2026-11-01T00:00:00.400Z'));
        $seen = [$first, $c->context(), $later->context()];

        return $next($later);
    }]);
    W::grant($panel, 'seller', 1, 1, actor: ActorRef::of('crm.user', 3));
    [$first, $same, $derived] = $seen;

    expect($same)->toBe($first)
        ->and($derived)->not->toBe($first)
        ->and($first->proposed)->toBe(['until' => null, 'fields' => []])
        ->and($derived->proposed['until']?->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 00:00:00.000000')
        ->and($first->user?->getKey())->toBe(1)
        ->and($first->role)->toBeInstanceOf(SellerRole::class)
        ->and($first->actor)->toEqual(ActorRef::of('crm.user', 3))
        ->and($first->phase)->toBe(AssignmentScopePhase::Assignment)
        ->and($first->state->version)->toBe(W::version() - 1);
});

it('lets revocations pass pipes without a proposal and refuses withUntil on them', function (): void {
    $panel = W::panel([static function (Change $c, Closure $next): ChangeResult {
        if ($c->type->isRevocation()) {
            expect($c->context()->proposed)->toBe([])->and($c->context()->phase)->toBe(AssignmentScopePhase::Revocation);
            $c->withUntil(null);
        }

        return $next($c);
    }]);

    expect(fn () => W::revoke($panel, 'seller', 1, 1))->toThrow(InvalidConfigurationException::class, 'withUntil() applies to a grant or an update')
        ->and(W::keys())->toContain('crm.organization:1|seller|1|crm.project:1|manual');
});

it('rolls back when a pipe breaks the exactly-once continuation or forges the result', function (Closure $pipe): void {
    $panel = W::panel([$pipe, static fn (Change $c, Closure $next) => $next($c)]);
    $version = W::version();

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(InvalidConfigurationException::class)
        ->and(W::version())->toBe($version)
        ->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
})->with([
    'skip next' => [static fn (Change $c, Closure $next): ChangeResult => ChangeResult::combine([], $c->context()->state, true, 'forged')],
    'double next' => [static function (Change $c, Closure $next): ChangeResult {
        $next($c);

        return $next($c);
    }],
    'forged result' => [static function (Change $c, Closure $next): ChangeResult {
        $result = $next($c);

        return ChangeResult::written(null, [], $result->state, $result->correlationId);
    }],
    'swallowed failure' => [static function (Change $c, Closure $next): ChangeResult {
        $result = $next($c);

        try {
            $c->cancel('late');
        } catch (ChangeCancelledException) {
            return ChangeResult::combine([$result], $result->state, true, $result->correlationId);
        }
    }],
    'returns nothing' => [static function (Change $c, Closure $next): ?ChangeResult {
        $next($c);

        return null;
    }],
]);

it('rolls back the first effective write when a later pipe reaches the writer again', function (): void {
    $calls = 0;
    $panel = W::panel([static function (Change $c, Closure $next) use (&$calls): ChangeResult {
        $calls++;
        $next($c);

        return $next($c->withFields(['region' => 'R2']));
    }]);

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(InvalidConfigurationException::class, '$next twice')
        ->and($calls)->toBe(1)
        ->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});

it('rolls back every sibling of a composite operation when one change fails', function (): void {
    $panel = W::panel([static fn (Change $c, Closure $next) => $c->role?->key() === 'root' ? $next($c) : $next($c)]);
    W::grant($panel, 'auditor', 2, null);
    $before = [W::rows(), W::version()];

    expect(fn () => W::pipeline()->sync($panel, W::tenant(), W::user(2), 'role', [W::role('support'), W::role('root')], W::project()))
        ->toThrow(RoleNotGrantableException::class)
        ->and([W::rows(), W::version()])->toBe($before);
});

it('passes the unchanged writer result through observing pipes', function (): void {
    $observed = [];
    $panel = W::panel([static function (Change $c, Closure $next) use (&$observed): ChangeResult {
        $result = $next($c);
        $observed[] = $result;

        return $result;
    }]);
    $result = W::grant($panel, 'seller', 1, 1);

    expect($result->status)->toBe(ChangeStatus::Unchanged)
        ->and($observed[0]->records)->toBe($result->records)
        ->and(SellerProjects::$observed)->not->toBe([]);
});
