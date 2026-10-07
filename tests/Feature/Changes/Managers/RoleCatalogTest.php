<?php

declare(strict_types=1);

use AzGuard\Catalog\CodeRoleCatalog;
use AzGuard\Changes\Change;
use AzGuard\Changes\GrantDetails;
use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Schema\RoleSchema;
use AzGuard\Schema\SchemaBuilder;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('V71 lists the code roles of the panel read-only, the same values the schema shows', function (): void {
    $catalog = M::managers($this->panel)->roles();
    $keys = array_map(fn (RoleSchema $role): string => $role->key->key(), $catalog->all());

    expect($catalog)->toBeInstanceOf(RoleCatalog::class)
        ->and($keys)->toBe(['analyst', 'auditor', 'caller', 'root', 'seller', 'support', 'tenant-admin'])
        ->and($catalog->all())->toEqual(app(SchemaBuilder::class)->for($this->panel, W::tenant())->roles())
        ->and(array_unique(array_map(fn (RoleSchema $role): bool => $role->editable, $catalog->all())))->toBe([false])
        ->and(M::managers($this->panel, 2)->roles()->all())->toEqual($catalog->all());
});

it('V71 finds a role by key, panel:key or registered class, never by a former key or another panel', function (): void {
    $catalog = M::managers($this->panel)->roles();

    expect($catalog->find('auditor')?->class)->toBe(AuditorRole::class)
        ->and($catalog->find('crm:auditor')?->key->key())->toBe('auditor')
        ->and($catalog->find(AuditorRole::class)?->key->key())->toBe('auditor')
        ->and($catalog->find('\\'.SellerRole::class)?->key->key())->toBe('seller')
        ->and($catalog->find('inspector'))->toBeNull()
        ->and($catalog->find('crm:inspector'))->toBeNull()
        ->and($catalog->find('backoffice:auditor'))->toBeNull()
        ->and($catalog->find(stdClass::class))->toBeNull()
        ->and($catalog->find('ghost'))->toBeNull();
});

it('V71 R25 has no way to create, change or delete a role definition', function (): void {
    $methods = fn (string $class): array => array_values(array_diff(array_map(fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC)), ['__construct']));

    expect($methods(RoleCatalog::class))->toBe(['all', 'find'])
        ->and($methods(CodeRoleCatalog::class))->toBe(['all', 'find'])
        ->and((new ReflectionClass(CodeRoleCatalog::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(RoleSchema::class))->isReadOnly())->toBeTrue()
        ->and(fn () => (function (RoleSchema $role): void {
            $role->editable = true;
        })(M::managers($this->panel)->roles()->all()[0]))->toThrow(Error::class);
});

it('V71 binds the grant fingerprint to the code build and to the stored row', function (): void {
    $id = W::grant($this->panel, 'auditor', 2, null)->record?->id ?? '';
    $first = M::managers($this->panel)->grants()->find($id)?->fingerprint;

    // Another build of the same panel: a pipe was added, so a form read before the deployment is stale.
    $rebuilt = W::panel([static fn (Change $change, Closure $next) => $next($change)]);
    $grants = M::managers($rebuilt)->grants();
    $second = $grants->find($id)?->fingerprint;

    expect($first)->toBeString()->and($second)->toBeString()->not->toBe($first)
        ->and(fn () => $grants->update($id, new GrantDetails(null, ['region' => 'R1']), $first))->toThrow(StaleSelectionException::class);

    Carbon::setTestNow('2026-10-06T12:00:05Z');
    $grants->update($id, new GrantDetails(null, ['eligible' => true]), $second);
    $third = $grants->find($id)?->fingerprint;

    expect($third)->not->toBe($second)
        ->and(fn () => $grants->update($id, new GrantDetails(null, []), $second))->toThrow(StaleSelectionException::class);
});
