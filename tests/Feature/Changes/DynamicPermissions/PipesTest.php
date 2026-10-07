<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Carbon;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

/** @return array{ArrayObject<int, Change>, Panel} */
function dynamicObserved(): array
{
    $seen = new ArrayObject;
    $panel = W::dynamicPanel([static function (Change $change, Closure $next) use ($seen) {
        $seen[] = $change;

        return $next($change);
    }]);

    return [$seen, $panel];
}

it('hands the pipes a typed change of the tenant with name and details, without subject, role, grant or expiry', function (): void {
    [$seen, $panel] = dynamicObserved();
    W::createAction($panel, 'campaigns.view', label: 'Просмотр', group: 'Кампании', description: 'Описание', actor: ActorRef::of('crm.user', 3, 'onboarding'));
    /** @var Change $change */
    $change = $seen[0];

    expect($change->type)->toBe(ChangeType::CreatePermission)
        ->and($change->isAction())->toBeTrue()
        ->and($change->isRole())->toBeFalse()
        ->and($change->panel)->toBe('crm')
        ->and($change->scope->tenant->key())->toBe('crm.organization:1')
        ->and($change->scope->context->isGlobal())->toBeTrue()
        ->and([$change->subject, $change->role, $change->permission, $change->grantId, $change->expectedFingerprint, $change->until])->each->toBeNull()
        ->and($change->origin)->toBe(Change::ACTION_ORIGIN)
        ->and($change->name)->toBe('campaigns.view')
        ->and([$change->details?->label, $change->details?->group, $change->details?->description, $change->fields])
        ->toBe(['Просмотр', 'Кампании', 'Описание', []])
        ->and($change->actor)->toEqual(ActorRef::of('crm.user', 3, 'onboarding'));
});

it('derives a checked context for create, update and delete: no subject, user or role, and the right phase', function (): void {
    $contexts = [];
    $panel = W::dynamicPanel([static function (Change $change, Closure $next) use (&$contexts) {
        $contexts[$change->type->value] = $change->context();

        return $next($change);
    }]);
    W::createAction($panel, 'campaigns.view');
    W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails('Метка'));
    W::deleteAction($panel, 'campaigns.view');

    expect(array_keys($contexts))->toBe(['create_permission', 'update_permission', 'delete_permission']);

    foreach ($contexts as $context) {
        expect([$context->subject, $context->user, $context->role])->each->toBeNull()
            ->and($context->scope->tenant->key())->toBe('crm.organization:1')
            ->and($context->panel->id())->toBe('crm');
    }
    expect($contexts['create_permission']->phase)->toBe(AssignmentScopePhase::Assignment)
        ->and($contexts['create_permission']->proposed)->toBe(['fields' => []])
        ->and($contexts['update_permission']->phase)->toBe(AssignmentScopePhase::Assignment)
        ->and($contexts['update_permission']->proposed)->toBe(['fields' => []])
        ->and($contexts['delete_permission']->phase)->toBe(AssignmentScopePhase::Revocation)
        ->and($contexts['delete_permission']->proposed)->toBe([])
        ->and($contexts['create_permission']->operation)->toBe(ChangeType::CreatePermission);
});

it('carries label, group and description through the pipes to the stored row, and keeps them with withFields([])', function (): void {
    $panel = W::dynamicPanel([static fn (Change $change, Closure $next) => $next($change->withFields([]))]);
    W::createAction($panel, 'campaigns.view', label: 'Просмотр', group: 'Кампании', description: 'Описание');
    $row = W::actions()[0];

    expect([$row['label'], $row['group'], $row['description']])->toBe(['Просмотр', 'Кампании', 'Описание']);
});

it('F1 rejects a pipe that changes the name, the tenant, the details or the type of a dynamic permission', function (Closure $substitute): void {
    $panel = W::dynamicPanel([static fn (Change $change, Closure $next) => $next($substitute($change))]);
    $before = [W::actions(), W::version()];

    try {
        W::createAction($panel, 'campaigns.view', label: 'Просмотр');

        throw new RuntimeException('Expected a refusal.');
    } catch (InvalidConfigurationException $error) {
        expect($error->code())->toBe('invalid_configuration.changing')
            ->and([W::actions(), W::version()])->toBe($before);
    }
})->with([
    'name' => [fn (Change $c) => Change::createPermission('crm', $c->scope->tenant, 'campaigns.edit', $c->details ?? new PermissionDetails, $c->actor)],
    'tenant' => [fn (Change $c) => Change::createPermission('crm', TenantRef::of('crm.organization', 2), $c->name ?? 'campaigns.view', $c->details ?? new PermissionDetails, $c->actor)],
    'label' => [fn (Change $c) => Change::createPermission('crm', $c->scope->tenant, $c->name ?? 'campaigns.view', new PermissionDetails('Другое'), $c->actor)],
    'type' => [fn (Change $c) => Change::updatePermission('crm', $c->scope->tenant, $c->name ?? 'campaigns.view', $c->details ?? new PermissionDetails, $c->actor)],
    'foreign frame, same identity' => [fn (Change $c) => Change::createPermission('crm', $c->scope->tenant, $c->name ?? 'campaigns.view', $c->details ?? new PermissionDetails, $c->actor)],
]);

it('refuses withUntil() on a change of a dynamic permission and withFields() on its delete', function (): void {
    $until = new DateTimeImmutable('2027-01-01T00:00:00Z');
    $create = W::dynamicPanel([static fn (Change $change, Closure $next) => $next($change->withUntil($until))]);

    expect(fn () => W::createAction($create, 'campaigns.view'))->toThrow(InvalidConfigurationException::class, 'withUntil');

    W::createAction(W::dynamicPanel(), 'campaigns.view');
    $delete = W::dynamicPanel([static fn (Change $change, Closure $next) => $next($change->withFields([]))]);

    expect(fn () => W::deleteAction($delete, 'campaigns.view'))->toThrow(InvalidConfigurationException::class, 'withFields')
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view']);
});

it('validates the shape of the change values', function (): void {
    $tenant = TenantRef::of('crm.organization', 1);

    expect(Change::createPermission('crm', $tenant, 'campaigns.view', new PermissionDetails('x'), null)->name)->toBe('campaigns.view')
        ->and(fn () => Change::createPermission('crm', $tenant, 'campaigns', new PermissionDetails, null))->toThrow(InvalidIdentityException::class)
        ->and(fn () => Change::deletePermission('crm', $tenant, 'campaigns.*', null))->toThrow(InvalidIdentityException::class)
        ->and(fn () => new PermissionDetails(fields: [0 => 'x']))->toThrow(InvalidChangeFieldsException::class)
        ->and(Change::deletePermission('crm', $tenant, 'campaigns.view', null)->details)->toBeNull();
});

it('keeps the Grants authority for every dynamic permission: no input names a mode', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view');
    CrmWorld::storage()->mutate('crm', function () use ($panel): void {
        $definition = $panel->writer()->lockedReads($panel)->catalog(W::tenant())->get('campaigns.view');

        expect($definition->authority)->toBe(PermissionAuthority::Grants);
    });
    $reflection = new ReflectionClass(PermissionDetails::class);

    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), $reflection->getProperties()))
        ->toBe(['label', 'group', 'description', 'fields']);
});
