<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
    DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists('users');
    Relation::morphMap([], false);
});

it('never queries permissions with dynamic opt-in disabled or during static build', function (): void {
    $queries = [];
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$queries): void {
        if (str_contains($event->sql, 'azg_')) {
            $queries[] = $event->sql;
        }
    });
    $plain = DatabaseSource::make();
    [$panel] = DatabaseWorld::compile($plain);
    expect(DatabaseWorld::items($plain->permissions($panel)))->toBe([]);
    $dynamic = DatabaseSource::make()->dynamicPermissions();
    [,, $registry] = DatabaseWorld::compile($dynamic);
    expect($queries)->toBe([])->and($registry->catalog('admin')->isDynamic())->toBeTrue();
    expect(fn () => $dynamic->permissions($registry->get('admin')))->toThrow(DefinitionException::class, 'explicit tenant');
});

it('reads exact panel tenant definitions while sharing the static role catalog across A B and global', function (): void {
    $a = TenantRef::of('organization', '1');
    $b = TenantRef::of('organization', '2');
    DatabaseWorld::define(tenant: $a, label: 'A');
    DatabaseWorld::define('reports.other', $b, 'B');
    DatabaseWorld::define('reports.global');
    DatabaseWorld::define('reports.foreign', $a, panel: 'cabinet');
    DatabaseWorld::insert('role', [DatabaseWorld::row(scope: AccessScope::in($a)), DatabaseWorld::row(scope: AccessScope::in($b), overrides: ['role' => 'unknown'])]);
    $source = DatabaseSource::make()->dynamicPermissions();
    [$panel, $frameA, $registry] = DatabaseWorld::compile($source, AccessScope::in($a));
    $static = $registry->catalog('admin');
    $overlayA = $static->withDynamic($source->permissions($panel, $a));
    $overlayB = $static->withDynamic($source->permissions($panel, $b));
    $replaced = $overlayA->withDynamic($source->permissions($panel, $b));
    expect(array_keys($overlayA->all()))->toContain('reports.export')->not->toContain('reports.other', 'reports.global', 'reports.foreign')
        ->and($overlayA->get('reports.export')->label)->toBe('A')
        ->and($overlayA->get('reports.export')->authority)->toBe(PermissionAuthority::Grants)
        ->and($overlayB->has('reports.other'))->toBeTrue()->and($overlayB->has('reports.export'))->toBeFalse()
        ->and($overlayA->roles())->toBe($static->roles())->and($overlayB->roles())->toBe($static->roles())
        ->and($replaced->all())->toEqual($overlayB->all())->and($replaced->nameWithFirstSegment('reports'))->toBeNull()
        ->and($static->has('reports.export'))->toBeFalse()->and($overlayA->snapshot())->toBe($static->snapshot());
    $grantsA = $source->readContributions(SubjectRef::of('user', 1), [$frameA->scope()], $frameA);
    [,$frameB] = DatabaseWorld::compile($source, AccessScope::in($b));
    $grantsB = $source->readContributions(SubjectRef::of('user', 1), [$frameB->scope()], $frameB);
    expect($grantsA['roles'][0]->role->key())->toBe('editor')->and($grantsB['roles'][0]->role->key())->toBe('unknown');
});

it('rejects static shadows and prefix conflicts in the tenant overlay', function (): void {
    DatabaseWorld::define('documents.view');
    $source = DatabaseSource::make()->dynamicPermissions();
    [$panel,, $registry] = DatabaseWorld::compile($source);
    expect(fn () => $registry->catalog('admin')->withDynamic($source->permissions($panel, TenantRef::global())))
        ->toThrow(DuplicatePermissionException::class);
    [,, $prefixed] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('admin')]);
    expect(fn () => $prefixed->catalog('admin')->withDynamic([new PermissionDefinition('admin.export', PermissionAuthority::Grants)]))
        ->toThrow(PrefixConflictException::class);
});

it('allows only known dynamic actions and requires a grant rather than treating the definition as authority', function (): void {
    DatabaseWorld::seedSubject();
    DatabaseWorld::define();
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'reports.*'])]);
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    expect(app(Authorizer::class)->decide($panel, $request)->allowed())->toBeTrue();
    DatabaseWorld::define('unassigned.action');
    $unassigned = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'unassigned.action'));
    expect(app(Authorizer::class)->decide($panel, $unassigned)->reason)->toBe(DecisionReason::NotGranted);
    $unknown = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.unknown'));
    expect(fn () => app(Authorizer::class)->decide($panel, $unknown))->toThrow(UnknownPermissionException::class);
});

it('rejects an unknown explicit tenant before dynamic storage lookup until the boundary implementation', function (): void {
    DatabaseWorld::seedSubject();
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
    $reads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$reads): void {
        if (str_contains($event->sql, 'azg_')) {
            $reads++;
        }
    });
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'))->inTenant(TenantRef::of('organization', 1));
    expect(app(Authorizer::class)->decide($panel, $request)->reason)->toBe(DecisionReason::TenantMismatch)->and($reads)->toBe(0);
});
