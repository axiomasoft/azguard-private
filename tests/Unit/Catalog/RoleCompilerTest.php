<?php

declare(strict_types=1);

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Permissions\AttributedClientPolicy;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Plugins\ProbePlugin;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\BadKeyRole;
use AzGuard\Tests\Fixtures\Roles\ExSellerRole;
use AzGuard\Tests\Fixtures\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Roles\OtherManagerRole;
use AzGuard\Tests\Fixtures\Roles\SelfFormerRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

/**
 * The admin panel with client permissions and the given roles, from a source of the provider and optional extra
 * sources.
 *
 * @param  list<mixed>  $roles
 * @param  list<mixed>  $extra
 */
function rolesOfAdmin(array $roles, array $extra = []): PanelCatalog
{
    return PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
            new StaticSource('crm', [...ClientPermission::definitions(), StaticSource::grants('reports.view')], $roles, [
                PolicyBinding::for(ClientPermission::ViewOwnProfile, AttributedClientPolicy::class),
            ]),
            ...$extra,
        ]),
    ])[2]->catalog('admin');
}

/**
 * A role declared inline by the test.
 *
 * @param  list<mixed>  $permissions
 * @param  list<mixed>  $scopes
 */
function inlineRole(string $key, array $permissions = [], array $scopes = [], bool $scopeRequired = false, array $formerKeys = []): BaseRole
{
    return new class($key, $permissions, $scopes, $scopeRequired, $formerKeys) extends BaseRole
    {
        /**
         * @param  list<mixed>  $permissions
         * @param  list<mixed>  $scopes
         * @param  list<mixed>  $formerKeys
         */
        public function __construct(
            private readonly string $roleKey,
            private readonly array $rolePermissions,
            private readonly array $roleScopes,
            private readonly bool $required,
            private readonly array $former,
        ) {}

        public function key(): string
        {
            return $this->roleKey;
        }

        public function formerKeys(): array
        {
            return $this->former;
        }

        public function permissions(): array
        {
            return $this->rolePermissions;
        }

        public function scopes(): array
        {
            return $this->roleScopes;
        }

        public function scopeRequired(): bool
        {
            return $this->required;
        }
    };
}

it('compiles roles into scalar records of keys, permissions and scopes', function (): void {
    $roles = rolesOfAdmin([new SellerRole, new AnalystRole, new SellerRole, inlineRole('support', [ClientPermission::View, 'reports.view', 'clients.**'])])->roles();

    expect(array_keys($roles))->toBe(['seller', 'analyst', 'support'])
        ->and($roles['seller'])->toBe([
            'class' => SellerRole::class,
            'key' => 'seller',
            'former_keys' => ['shop-seller', 'vendor'],
            'permissions' => ['clients.view', 'clients.update'],
            'scopes' => [['type' => 'crm.project', 'class' => ProjectScope::class]],
            'scope_required' => true,
            'super_admin' => false,
            'grantable' => true,
            'source' => 'crm',
            'origin' => 'provider',
        ])
        ->and($roles['analyst']['scopes'])->toBe([['type' => 'crm.project', 'class' => ProjectScope::class]])
        ->and($roles['analyst']['permissions'])->toBe(['reports.*'])
        ->and($roles['support']['permissions'])->toBe(['clients.view', 'reports.view', 'clients.**']);
});

it('checks keys of roles that override key() and formerKeys()', function (BaseRole $role, string $message): void {
    expect(fn () => rolesOfAdmin([$role]))->toThrow(InvalidRoleKeyException::class, $message);
})->with([
    'key() with a malformed key' => [new BadKeyRole, '"Bad Key"'],
    'formerKeys() with the current key' => [new SelfFormerRole, 'lists its current key "auditor"'],
    'formerKeys() with a malformed key' => [inlineRole('auditor', formerKeys: ['Old Key']), '"Old Key"'],
    'formerKeys() with a non-string' => [inlineRole('auditor', formerKeys: [7]), 'lists int among former keys'],
]);

it('rejects two roles that claim one key or former key', function (array $roles, string $message): void {
    expect(fn () => rolesOfAdmin($roles))->toThrow(DuplicateRoleException::class, $message);
})->with([
    'two classes with the key manager' => [[new ManagerRole, new OtherManagerRole], 'Role key "manager" of panel "admin" belongs to '.ManagerRole::class.' from source "crm" and to '.OtherManagerRole::class],
    'a former key equal to the key of another role' => [[new SellerRole, new ExSellerRole], 'Role key "seller"'],
    'a key equal to a former key of another role' => [[new ExSellerRole, new SellerRole], 'Role key "seller"'],
    'two roles with one former key' => [[new SellerRole, inlineRole('trader', formerKeys: ['vendor'])], 'Role key "vendor"'],
]);

it('names the plugin of a role source in a role collision', function (): void {
    $plugin = ProbePlugin::make('acme/blog', register: static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
        new StaticSource('blog', roles: [new OtherManagerRole]),
    ]));

    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->permissions([new StaticSource('crm', [StaticSource::grants('clients.view')], [new ManagerRole])])
            ->plugins([$plugin]),
    ]))->toThrow(DuplicateRoleException::class, 'from source "blog" (plugin:acme/blog)');
});

it('resolves role permissions against the catalog of the panel', function (BaseRole $role, string $exception, string $message): void {
    expect(fn () => rolesOfAdmin([$role]))->toThrow($exception, $message);
})->with([
    'an unbound enum case' => [inlineRole('clerk', [OrderPermission::View]), UnknownPermissionException::class, OrderPermission::class.'::View'],
    'an unknown exact name' => [inlineRole('clerk', ['orders.view']), UnknownPermissionException::class, 'lists "orders.view", which is not a permission of panel "admin"'],
    'a malformed name' => [inlineRole('clerk', ['view']), InvalidPermissionKeyException::class, '"view"'],
    'a malformed pattern' => [inlineRole('clerk', ['clients.*.view']), InvalidPermissionKeyException::class, 'wildcard only as the last segment'],
    'a policy-only case' => [inlineRole('clerk', [ClientPermission::ViewOwnProfile]), DefinitionException::class, 'never assigned'],
    'a policy-only name' => [inlineRole('clerk', ['clients.view_own_profile']), DefinitionException::class, 'decides by its policy alone'],
    'another item type' => [inlineRole('clerk', [42]), DefinitionException::class, 'lists int in permissions()'],
]);

it('takes an unknown exact name when the panel has a dynamic permission source', function (): void {
    $roles = rolesOfAdmin([inlineRole('clerk', ['exports.run'])], [new StaticSource('db', dynamic: true)])->roles();

    expect($roles['clerk']['permissions'])->toBe(['exports.run']);
});

it('checks assignment scopes of roles', function (array $roles, string $message): void {
    expect(fn () => rolesOfAdmin($roles))->toThrow(DefinitionException::class, $message);
})->with([
    'scope required without scopes' => [[inlineRole('clerk', scopeRequired: true)], 'requires a scope on every assignment but lists no scopes'],
    'not a scope' => [[inlineRole('clerk', scopes: [SellerRole::class])], 'lists '.SellerRole::class.' in scopes()'],
    'two classes with one type' => [[
        new SellerRole,
        inlineRole('clerk', scopes: [new class implements AssignmentScopeDefinition
        {
            public function type(): string
            {
                return 'crm.project';
            }

            public function model(): ?string
            {
                return null;
            }

            public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
            {
                return null;
            }
        }]),
    ], 'two assignment scopes with the type "crm.project"'],
]);

it('refuses something a source returns that is not a role', function (): void {
    expect(fn () => rolesOfAdmin([SellerRole::class]))->toThrow(InvalidSourceContributionException::class, 'from roles()');
});

it('rejects the same role class from independent origins with all contributor ids', function (bool $provider): void {
    $one = ProbePlugin::make('one/access', register: static fn (PanelBuilder $p): PanelBuilder => $p->permissions([
        new StaticSource('one-access', roles: [new ManagerRole]),
    ]));
    $two = ProbePlugin::make('two/access', register: static fn (PanelBuilder $p): PanelBuilder => $p->permissions([
        new StaticSource('two-access', roles: [new ManagerRole]),
    ]));

    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $p): PanelBuilder => $p
            ->permissions([new StaticSource('app', [StaticSource::grants('clients.view')], $provider ? [new ManagerRole] : [])])
            ->plugins($provider ? [$two] : [$one, $two]),
    ]))->toThrow(DuplicateRoleException::class,
        ($provider ? 'source "app"' : 'source "one-access" (plugin:one/access)')
        .' and to '.ManagerRole::class.' from source "two-access" (plugin:two/access)');
})->with(['provider/plugin' => [true], 'two plugins' => [false]]);

it('keeps a repeated role class within one origin owned by its first source', function (bool $plugin): void {
    $sources = [
        new StaticSource('first', roles: [new ManagerRole, new ManagerRole]),
        new StaticSource('second', roles: [new ManagerRole]),
    ];
    $catalog = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $p): PanelBuilder => $p
            ->permissions([StaticSource::names('app', 'clients.view'), ...($plugin ? [] : $sources)])
            ->plugins($plugin ? [ProbePlugin::make('one/access', register: static fn (PanelBuilder $p): PanelBuilder => $p->permissions($sources))] : []),
    ])[2]->catalog('admin');

    expect(array_keys($catalog->roles()))->toBe(['manager'])
        ->and($catalog->roles()['manager']['source'])->toBe('first')
        ->and($catalog->roles()['manager']['origin'])->toBe($plugin ? 'plugin:one/access' : 'provider');
})->with(['provider' => [false], 'plugin' => [true]]);
