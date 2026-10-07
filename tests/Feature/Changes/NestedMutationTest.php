<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    if (CrmWorld::storage()->connection()->getDriverName() !== 'sqlite') {
        W::clean();
    }
    CrmWorld::seed();
});
afterEach(function (): void {
    if (CrmWorld::storage()->connection()->getDriverName() !== 'sqlite') {
        W::clean();
    }
    CrmWorld::resetRuntime();
    EventWorld::reset();
    Carbon::setTestNow();
});

it('rolls back a dynamic permission deletion when a pipe creates an exact grant after the cascade was planned', function (ChangeType $type, bool $after, string $exception): void {
    $armed = false;
    $panel = null;
    $panel = W::panel([static function (Change $change, Closure $next) use (&$armed, &$panel, $type, $after): ChangeResult {
        if (! $armed || $change->type !== $type) {
            return $next($change);
        }
        $armed = false;
        $result = $after ? $next($change) : null;
        W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(), origin: 'nested');

        return $result ?? $next($change);
    }], static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]), [CrmWorld::database()->dynamicPermissions()]);
    W::createAction($panel, 'campaigns.view');
    W::createAction($panel, 'campaigns.view', tenant: 2);
    W::pipeline()->grant($panel, W::tenant(), W::user(), W::permission('campaigns.view'), W::project());
    $before = [W::actions(), W::rows('permission'), W::version(), EventWorld::auditRows()];
    EventWorld::listen();
    $armed = true;

    expect(fn () => W::deleteAction($panel, 'campaigns.view'))->toThrow($exception)
        ->and([W::actions(), W::rows('permission'), W::version(), EventWorld::auditRows()])->toBe($before)
        ->and(EventWorld::events())->toBe([]);
})->with([
    'before revocation' => [ChangeType::RevokePermission, false, StaleSelectionException::class],
    'after revocation' => [ChangeType::RevokePermission, true, StaleSelectionException::class],
    'before action delete' => [ChangeType::DeletePermission, false, StaleSelectionException::class],
    'after action delete' => [ChangeType::DeletePermission, true, UnknownPermissionException::class],
]);

it('revalidates the dynamic catalog after a nested delete in a later grant of a compound operation', function (): void {
    $armed = false;
    $panel = null;
    $panel = W::panel([static function (Change $change, Closure $next) use (&$armed, &$panel): ChangeResult {
        if ($armed && $change->type === ChangeType::GrantPermission && $change->permission?->local() === 'campaigns.edit') {
            $armed = false;
            W::deleteAction($panel, 'campaigns.edit');
        }

        return $next($change);
    }], static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]), [CrmWorld::database()->dynamicPermissions()]);
    W::createAction($panel, 'campaigns.view');
    W::createAction($panel, 'campaigns.edit');
    $before = [W::actions(), W::rows('permission'), W::version(), EventWorld::auditRows()];
    EventWorld::listen();
    $armed = true;

    expect(fn () => User::query()->findOrFail(1)->guard('crm')->inTenant(W::tenant())->grantPermission(['campaigns.view', 'campaigns.edit']))
        ->toThrow(UnknownPermissionException::class)
        ->and([W::actions(), W::rows('permission'), W::version(), EventWorld::auditRows()])->toBe($before)
        ->and(EventWorld::events())->toBe([]);
});

it('accepts a dynamic permission created by a later pipe after an earlier sibling read the catalog', function (): void {
    $armed = false;
    $panel = null;
    $panel = W::dynamicPanel([static function (Change $change, Closure $next) use (&$armed, &$panel): ChangeResult {
        if ($armed && $change->type === ChangeType::GrantPermission && $change->permission?->local() === 'campaigns.edit') {
            $armed = false;
            W::createAction($panel, 'campaigns.edit');
        }

        return $next($change);
    }]);
    W::createAction($panel, 'campaigns.view');
    $version = W::version();
    $armed = true;
    $result = User::query()->findOrFail(1)->guard('crm')->inTenant(W::tenant())->grantPermission(['campaigns.view', 'campaigns.edit']);

    expect(W::keys('permission'))->toBe([
        'crm.organization:1|campaigns.view|1|global|manual',
        'crm.organization:1|campaigns.edit|1|global|manual',
    ])->and($result->state->version)->toBe($version + 1)
        ->and(W::version())->toBe($version + 1);
});

it('returns the committed root revision when a no-op pipe performs an effective nested change', function (bool $after): void {
    $armed = false;
    $panel = null;
    $panel = W::panel([static function (Change $change, Closure $next) use (&$armed, &$panel, $after): ChangeResult {
        if (! $armed || $change->role?->key() !== 'support') {
            return $next($change);
        }
        $armed = false;
        $result = $after ? $next($change) : null;
        W::grant($panel, 'auditor', 2, null);

        return $result ?? $next($change);
    }]);
    W::grant($panel, 'support', 1, null);
    $version = W::version();
    EventWorld::listen();
    $armed = true;
    $result = W::grant($panel, 'support', 1, null);

    expect($result->status)->toBe(ChangeStatus::Unchanged)
        ->and($result->committed)->toBeTrue()
        ->and($result->state->version)->toBe($version + 1)
        ->and(W::version())->toBe($version + 1)
        ->and(EventWorld::types())->toBe(['role.granted'])
        ->and(EventWorld::events()[0]->state->version)->toBe($result->state->version);
})->with(['before terminal' => [false], 'after terminal' => [true]]);

it('discards the touch and delivery of an effective grandchild when its parent mutation fails and is caught', function (): void {
    $armed = false;
    $panel = null;
    $panel = W::panel([static function (Change $change, Closure $next) use (&$armed, &$panel): ChangeResult {
        if ($armed && $change->role?->key() === 'support' && $change->subject?->id() === '1') {
            $armed = false;

            try {
                W::grant($panel, 'auditor', 2, null);
            } catch (ChangeCancelledException) {
            }
        } elseif ($change->role?->key() === 'auditor') {
            $next($change);
            W::grant($panel, 'support', 3, null);
            $change->cancel('discard the whole child');
        }

        return $next($change);
    }]);
    W::grant($panel, 'support', 1, null);
    $before = [W::keys(), W::version()];
    EventWorld::listen();
    $armed = true;
    $result = W::grant($panel, 'support', 1, null);

    expect($result->status)->toBe(ChangeStatus::Unchanged)
        ->and($result->state->version)->toBe($before[1])
        ->and([W::keys(), W::version()])->toBe($before)
        ->and(EventWorld::events())->toBe([]);
});
