<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\GrantDetails;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\AssignmentScopeRequiredException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Exceptions\RoleNotGrantableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\OrganizationMembership;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\Project;

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

/** Asserts the refusal and that nothing was written: rows of both kinds and the panel version are unchanged. */
function refusedWithoutWrites(Closure $change, string $exception): Throwable
{
    $before = [W::rows('role'), W::rows('permission'), W::version()];

    try {
        $change();
    } catch (Throwable $error) {
        expect($error)->toBeInstanceOf($exception)
            ->and([W::rows('role'), W::rows('permission'), W::version()])->toBe($before);

        return $error;
    }

    throw new RuntimeException('Expected '.$exception.'.');
}

it('F1 rejects a pipe that substitutes the identity or passes something else', function (Closure $substitute): void {
    $panel = W::panel([static fn (Change $change, Closure $next) => $next($substitute($change))]);
    $error = refusedWithoutWrites(fn () => W::grant($panel, 'analyst', 2, 1), InvalidConfigurationException::class);

    expect($error->code())->toBe('invalid_configuration.changing');
})->with([
    'subject' => [fn (Change $c) => Change::grant('crm', $c->scope, SubjectRef::of('crm.user', 3), $c->role ?? W::role('analyst'), $c->origin, $c->actor)],
    'scope' => [fn (Change $c) => Change::grant('crm', AccessScope::in($c->scope->tenant, AssignmentScopeRef::of('crm.project', 2)), $c->subject ?? W::user(), W::role('analyst'), $c->origin, $c->actor)],
    'origin' => [fn (Change $c) => Change::grant('crm', $c->scope, $c->subject ?? W::user(), W::role('analyst'), 'import', $c->actor)],
    'same identity, foreign frame' => [fn (Change $c) => Change::grant('crm', $c->scope, $c->subject ?? W::user(), W::role('analyst'), $c->origin, $c->actor)],
    'not a change' => [fn (Change $c) => ['role' => 'analyst']],
]);

it('F10 rejects an origin outside the source label grammar', function (): void {
    refusedWithoutWrites(fn () => W::grant(W::panel(), 'analyst', 2, 1, origin: 'Bad Origin'), InvalidIdentityException::class);
});

it('F2 rejects a subject the panel does not accept or that does not exist', function (SubjectRef $subject): void {
    $panel = W::panel();

    refusedWithoutWrites(fn () => W::pipeline()->grant($panel, W::tenant(), $subject, W::role('analyst'), W::project(1)), SubjectNotAcceptedException::class);
})->with([
    'foreign type' => [SubjectRef::of('crm.organization', 1)],
    'missing user' => [SubjectRef::of('crm.user', 99)],
]);

it('F3 rejects unknown roles, former keys and roles that are not granted through storage', function (string $role, string $exception, string $message): void {
    $error = refusedWithoutWrites(fn () => W::grant(W::panel(), $role, 2, null), $exception);

    expect($error->getMessage())->toContain($message);
})->with([
    'typo' => ['edtor', UnknownRoleException::class, 'has no role "edtor"'],
    'former key' => ['inspector', UnknownRoleException::class, 'renamed to "auditor"'],
    'not grantable' => ['root', RoleNotGrantableException::class, 'not granted through storage'],
]);

it('F4 rejects unknown, policy-only and uncovered permissions', function (string $permission, string $exception): void {
    $panel = W::panel();

    refusedWithoutWrites(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission($permission), W::project()), $exception);
})->with([
    'typo' => ['clients.veiw', UnknownPermissionException::class],
    'policy only R63' => ['clients.view_own_profile', PermissionNotGrantableException::class],
    'pattern covering nothing' => ['orders.*', UnknownPermissionException::class],
]);

it('F4 lets a roles-only writer refuse permission grants', function (): void {
    $panel = CrmWorld::compile(fn (PanelBuilder $p) => $p->roles([RootRole::class]), sources: [CrmWorld::database()->rolesOnly()]);

    refusedWithoutWrites(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.view'), W::project()), PanelNotWritableException::class);
    expect(W::grant($panel, 'analyst', 2, 1)->applied())->toBeTrue();
});

it('F5 keeps the tenant boundary: required tenant, tenant type and membership', function (TenantRef $tenant, int $user, string $exception): void {
    $panel = W::panel();

    refusedWithoutWrites(fn () => W::pipeline()->grant($panel, $tenant, W::user($user), W::role('auditor'), W::project()), $exception);
})->with([
    'global tenant' => [TenantRef::global(), 2, TenantRequiredException::class],
    'foreign tenant type' => [TenantRef::of('crm.city', 1), 2, TenantMismatchException::class],
    'not a member' => [TenantRef::of('crm.organization', 1), 4, TenantMismatchException::class],
    'member of A only' => [TenantRef::of('crm.organization', 2), 2, TenantMismatchException::class],
]);

it('F5 accepts a global tenant only for roles the panel allows globally', function (): void {
    $panel = W::panel(configure: fn (PanelBuilder $p) => $p->tenants(TenantPolicy::required(Organization::class)
        ->requireMembership(new OrganizationMembership)->allowGlobalRoles([SupportRole::class])));

    refusedWithoutWrites(fn () => W::pipeline()->grant($panel, TenantRef::global(), W::user(2), W::role('auditor'), W::project()), TenantRequiredException::class);
    expect(W::pipeline()->grant($panel, TenantRef::global(), W::user(2), W::role('support'), W::project())->applied())->toBeTrue()
        ->and(W::keys())->toContain('global|support|2|global|manual');
});

it('F6 checks context type, role binding, structure, owner tenant and Assignment filters', function (string $role, int $user, ?AssignmentScopeRef $context, string $exception): void {
    $panel = W::panel();

    refusedWithoutWrites(fn () => W::pipeline()->grant($panel, W::tenant(), W::user($user), W::role($role), $context ?? W::project()), $exception);
})->with([
    'scope required' => ['analyst', 2, null, AssignmentScopeRequiredException::class],
    'unknown type' => ['analyst', 2, AssignmentScopeRef::of('crm.unknown', 1), AssignmentScopeNotAcceptedException::class],
    'role without the type' => ['auditor', 2, AssignmentScopeRef::of('crm.project', 1), AssignmentScopeNotAcceptedException::class],
    'missing project' => ['analyst', 2, AssignmentScopeRef::of('crm.project', 99), AssignmentScopeNotAcceptedException::class],
    'project of tenant B' => ['analyst', 1, AssignmentScopeRef::of('crm.project', 4), TenantMismatchException::class],
    'inactive project R09' => ['analyst', 2, AssignmentScopeRef::of('crm.project', 3), AssignmentScopeNotAcceptedException::class],
    'seller filter of the target city' => ['seller', 1, AssignmentScopeRef::of('crm.project', 2), AssignmentScopeNotAcceptedException::class],
]);

it('F7 rejects an expiry that is not in the future before any filter runs', function (string $until): void {
    $error = refusedWithoutWrites(fn () => W::grant(W::panel(), 'seller', 1, 1, until: new DateTimeImmutable($until)), InvalidChangeFieldsException::class);

    expect($error)->toBeInstanceOf(InvalidChangeFieldsException::class)
        ->and(array_keys($error->errors()))->toBe(['until'])
        ->and(ActiveProjects::$observed)->toBe([])->and(SellerProjects::$observed)->toBe([]);
})->with(['past' => ['2026-10-06T11:59:59Z'], 'now' => ['2026-10-06T12:00:00.900Z']]);

it('F8 validates fields against the schema before any filter runs', function (array $fields, string $field): void {
    $error = refusedWithoutWrites(fn () => W::grant(W::panel(), 'seller', 1, 1, fields: $fields), InvalidChangeFieldsException::class);

    expect($error)->toBeInstanceOf(InvalidChangeFieldsException::class)
        ->and(array_keys($error->errors()))->toContain($field)
        ->and(ActiveProjects::$observed)->toBe([])->and(SellerProjects::$observed)->toBe([]);
})->with([
    'unknown field' => [['department' => 7], 'department'],
    'invalid value' => [['eligible' => 'maybe'], 'eligible'],
]);

it('F8 checks the tenant of a model field through the panel resource scopes', function (): void {
    $resolver = new class implements ResourceScopeResolver
    {
        public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
        {
            return AccessScope::in(TenantRef::of('crm.organization', (string) $resource->getAttribute('organization_id')));
        }
    };
    $fields = fn (PanelBuilder $p) => $p->fields(FieldTarget::RoleGrant, [Field::model('home_project', Project::class)->inMeta()]);
    $withoutResolver = W::panel(configure: $fields);

    refusedWithoutWrites(fn () => W::grant($withoutResolver, 'seller', 1, 1, fields: ['home_project' => 1]), InvalidChangeFieldsException::class);

    $panel = W::panel(configure: fn (PanelBuilder $p) => $fields($p)->resourceScopes([Project::class => $resolver]));
    refusedWithoutWrites(fn () => W::grant($panel, 'seller', 1, 1, fields: ['home_project' => 4]), InvalidChangeFieldsException::class);

    expect(W::grant($panel, 'seller', 1, 1, fields: ['home_project' => 5])->record?->fields['home_project'])->toBe(5);
});

it('F9 refuses a stale or foreign selection of an update', function (Closure $update): void {
    $panel = W::panel();
    $record = W::grant($panel, 'analyst', 2, 1)->record;
    W::grant($panel, 'analyst', 2, 1, fields: ['region' => 'R2']);

    refusedWithoutWrites(fn () => $update($panel, $record), StaleSelectionException::class);
})->with([
    'stale fingerprint' => [fn ($panel, $record) => W::pipeline()->update($panel, W::tenant(), 'manual', $record->id, new GrantDetails(null, ['region' => 'R1']), $record->fingerprint)],
    'another tenant' => [fn ($panel, $record) => W::pipeline()->update($panel, W::tenant(2), 'manual', $record->id, new GrantDetails(null, []))],
    'another origin' => [fn ($panel, $record) => W::pipeline()->update($panel, W::tenant(), 'import', $record->id, new GrantDetails(null, []))],
    'malformed id' => [fn ($panel, $record) => W::pipeline()->update($panel, W::tenant(), 'manual', '1', new GrantDetails(null, []))],
]);

it('revalidates an update under the lock like a grant', function (): void {
    $panel = W::panel();
    $record = W::grant($panel, 'seller', 1, 1)->record;
    Project::query()->whereKey(1)->update(['is_active' => false]);

    refusedWithoutWrites(fn () => W::pipeline()->update($panel, W::tenant(), 'manual', $record->id ?? '', new GrantDetails(null, ['region' => 'R1'])),
        AssignmentScopeNotAcceptedException::class);
});
