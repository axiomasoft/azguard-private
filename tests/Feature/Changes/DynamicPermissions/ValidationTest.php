<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Carbon;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

/** Asserts the refusal and that nothing was written: permissions, grants and the panel version are unchanged. */
function dynamicRefused(Closure $change, string $exception): Throwable
{
    $before = [W::actions(), W::rows('role'), W::rows('permission'), W::version()];

    try {
        $change();
    } catch (Throwable $error) {
        expect($error)->toBeInstanceOf($exception)
            ->and([W::actions(), W::rows('role'), W::rows('permission'), W::version()])->toBe($before);

        return $error;
    }

    throw new RuntimeException('Expected '.$exception.'.');
}

it('refuses create, update and delete on a panel without dynamicPermissions()', function (Closure $change): void {
    $panel = W::panel();

    expect(dynamicRefused(fn () => $change($panel), PanelNotWritableException::class)->code())->toBe('panel_not_writable');
})->with([
    'create' => [fn ($panel) => W::createAction($panel, 'campaigns.view')],
    'update' => [fn ($panel) => W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails('x'))],
    'delete' => [fn ($panel) => W::deleteAction($panel, 'campaigns.view')],
]);

it('refuses the name of a static permission with DuplicatePermissionException', function (): void {
    $panel = W::dynamicPanel();

    expect(dynamicRefused(fn () => W::createAction($panel, 'clients.view'), DuplicatePermissionException::class)->code())->toBe('duplicate_permission');
});

it('refuses a name under the panel prefix with PrefixConflictException', function (): void {
    $panel = W::dynamicPanel();

    expect(dynamicRefused(fn () => W::createAction($panel, 'crm.campaigns'), PrefixConflictException::class)->code())->toBe('prefix_conflict');
});

it('refuses a repeated create of the same name in the same tenant', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view', label: 'Первое');

    dynamicRefused(fn () => W::createAction($panel, 'campaigns.view', label: 'Второе'), DuplicatePermissionException::class);

    expect(W::actions()[0]['label'])->toBe('Первое');
});

it('refuses update and delete of a missing, static or foreign-tenant name with UnknownPermissionException', function (string $name, int $tenant): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view', tenant: 1);

    expect(dynamicRefused(fn () => W::pipeline()->updatePermission($panel, W::tenant($tenant), $name, new PermissionDetails('x')),
        UnknownPermissionException::class)->code())->toBe('unknown_permission');
    dynamicRefused(fn () => W::deleteAction($panel, $name, $tenant), UnknownPermissionException::class);
})->with([
    'missing name' => ['campaigns.edit', 1],
    'static name' => ['clients.view', 1],
    'name of another tenant' => ['campaigns.view', 2],
]);

it('refuses a name that breaks the permission grammar before anything is written', function (string $name): void {
    $panel = W::dynamicPanel();

    dynamicRefused(fn () => W::createAction($panel, $name), InvalidIdentityException::class);
})->with(['one segment' => ['campaigns'], 'wildcard' => ['campaigns.*'], 'empty' => [''], 'uppercase and space' => ['Campaigns View']]);

it('refuses any field because a dynamic permission declares no field schema', function (): void {
    $panel = W::dynamicPanel();
    $error = dynamicRefused(fn () => W::createAction($panel, 'campaigns.view', fields: ['region' => 'R1']), InvalidChangeFieldsException::class);

    expect($error->errors())->toBe(['region' => ['A dynamic permission declares no fields.']]);

    W::createAction($panel, 'campaigns.view');

    dynamicRefused(fn () => W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails(fields: ['eligible' => true])),
        InvalidChangeFieldsException::class);
});

it('refuses fields a changing pipe adds with withFields()', function (): void {
    $panel = W::dynamicPanel([static fn (Change $change, Closure $next) => $next($change->withFields(['region' => 'R1']))]);

    dynamicRefused(fn () => W::createAction($panel, 'campaigns.view'), InvalidChangeFieldsException::class);
});

it('refuses a label or group longer than its column', function (string $field): void {
    $panel = W::dynamicPanel();
    $error = dynamicRefused(fn () => W::createAction($panel, 'campaigns.view', ...[$field => str_repeat('я', 192)]), InvalidChangeFieldsException::class);

    expect(array_keys($error->errors()))->toBe([$field]);
    expect(W::createAction($panel, 'campaigns.view', ...[$field => str_repeat('я', 191)])->applied())->toBeTrue();
})->with(['label', 'group']);

it('keeps a dynamic permission in a tenant: not global and of the panel tenant type', function (): void {
    $panel = W::dynamicPanel();

    expect(dynamicRefused(fn () => W::pipeline()->createPermission($panel, TenantRef::global(), 'campaigns.view', new PermissionDetails),
        TenantRequiredException::class)->code())->toBe('tenant_required');
    dynamicRefused(fn () => W::pipeline()->createPermission($panel, TenantRef::of('crm.project', 1), 'campaigns.view', new PermissionDetails),
        TenantMismatchException::class);
});

it('lets a roles-only writer with dynamicPermissions() manage actions but not grant them directly', function (): void {
    $panel = W::dynamicPanel(rolesOnly: true);
    $created = W::createAction($panel, 'campaigns.view', label: 'Просмотр');
    $updated = W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails('Чтение'));

    expect($created->applied())->toBeTrue()->and($updated->applied())->toBeTrue();

    dynamicRefused(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2)), PanelNotWritableException::class);

    expect(W::deleteAction($panel, 'campaigns.view')->applied())->toBeTrue()->and(W::actions())->toBe([]);
});

it('refuses a grant of the name before it is created and after it is deleted, and accepts it in between', function (): void {
    $panel = W::dynamicPanel();
    $grant = fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));

    dynamicRefused($grant, UnknownPermissionException::class);
    W::createAction($panel, 'campaigns.view');

    expect($grant()->applied())->toBeTrue()->and(W::keys('permission'))->toBe(['crm.organization:1|campaigns.view|2|crm.project:2|manual']);

    W::deleteAction($panel, 'campaigns.view');

    expect(W::keys('permission'))->toBe([]);
    dynamicRefused($grant, UnknownPermissionException::class);
});

it('does not revoke grants when the opt-in was removed before the delete', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view');
    W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));
    $plain = W::panel();

    dynamicRefused(fn () => W::deleteAction($plain, 'campaigns.view'), PanelNotWritableException::class);

    expect(W::keys('permission'))->toBe(['crm.organization:1|campaigns.view|2|crm.project:2|manual'])
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view']);
});
