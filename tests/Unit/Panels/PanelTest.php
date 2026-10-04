<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelFingerprint;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Tests\Fixtures\Authorization\ContinueHook;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use AzGuard\Tests\Fixtures\Panels\FixedTenantResolver;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\Manager;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Plugins\AuditFreeze;
use AzGuard\Tests\Fixtures\Roles\SellerRole;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @param  Closure(PanelBuilder, PanelRecipe): mixed  $describe
 * @return array{0: Panel, 1: PanelRecipe}
 */
function compilePanel(Closure $describe, string $id = 'admin'): array
{
    $recipe = new PanelRecipe($id);
    $describe(new PanelBuilder($recipe), $recipe);
    $recipe->seal();

    return [(new PanelCompiler)->compile($recipe), $recipe];
}

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('is a final readonly value with read-only accessors', function (): void {
    $panel = new ReflectionClass(Panel::class);

    expect($panel->isFinal())->toBeTrue()
        ->and($panel->isReadOnly())->toBeTrue();

    foreach (['id', 'label', 'isDefault', 'prefix', 'subjectModels', 'accepts'] as $method) {
        expect($panel->hasMethod($method))->toBeTrue($method);
    }
});

it('compiles a panel with defaults when the provider sets nothing', function (): void {
    [$panel] = compilePanel(static fn (): null => null, 'cabinet');

    expect($panel->id())->toBe('cabinet')
        ->and($panel->label())->toBe('cabinet')
        ->and($panel->isDefault())->toBeFalse()
        ->and($panel->prefix())->toBe('cabinet')
        ->and($panel->subjectModels())->toBe([]);
});

it('compiles what the provider describes', function (): void {
    [$panel] = compilePanel(static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->id('admin')->label('Back office')->default()->for([User::class, Seller::class])->for(User::class));

    expect($panel->label())->toBe('Back office')
        ->and($panel->isDefault())->toBeTrue()
        ->and($panel->subjectModels())->toBe([User::class, Seller::class]);
});

it('resolves the prefix from the resource prefix setting', function (string|bool $setting, ?string $expected): void {
    [$panel] = compilePanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix($setting));

    expect($panel->prefix())->toBe($expected);
})->with([
    'on: the panel id' => [true, 'admin'],
    'custom segment' => ['backoffice', 'backoffice'],
    'off' => [false, null],
]);

it('lets the provider win over configure for all panels and the last call win inside one layer', function (): void {
    [$panel] = compilePanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::configure(), fn () => $panel->label('configure')->resourcePrefix('shared')->default());
        $panel->label('first')->label('second');
    });

    expect($panel->label())->toBe('second')
        ->and($panel->prefix())->toBe('shared')
        ->and($panel->isDefault())->toBeTrue();
});

it('accepts instances of its subject models and of their subclasses', function (): void {
    [$panel] = compilePanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class));

    expect($panel->accepts(new User))->toBeTrue()
        ->and($panel->accepts(new Manager))->toBeTrue()
        ->and($panel->accepts(new Seller))->toBeFalse();
});

it('accepts a subject reference by the morph class of a subject model', function (): void {
    Relation::morphMap(['user' => User::class, 'seller' => Seller::class]);
    [$panel] = compilePanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class));

    expect($panel->accepts(SubjectRef::of('user', 7)))->toBeTrue()
        ->and($panel->accepts(SubjectRef::of('seller', 7)))->toBeFalse()
        ->and($panel->accepts(SubjectRef::of('project', 7)))->toBeFalse();
});

it('gives the same fingerprint to the same description', function (): void {
    $describe = static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, new ArraySource('ldap-live'), 'database'])
        ->roles([SellerRole::class])
        ->before(static fn (): null => null)
        ->tenantResolvers([new FixedTenantResolver]);

    expect(PanelFingerprint::of(...compilePanel($describe)))->toBe(PanelFingerprint::of(...compilePanel($describe)))
        ->toMatch('/\A[0-9a-f]{64}\z/');
});

it('changes the fingerprint when the described panel changes', function (Closure $change): void {
    $base = static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, InvoicePermission::class, 'ldap', 'database'])
        ->roles([SellerRole::class])
        ->restrictions([RecordingRestriction::class, AuditFreeze::class]);

    expect(PanelFingerprint::of(...compilePanel($change)))->not->toBe(PanelFingerprint::of(...compilePanel($base)));
})->with([
    'prefix' => [fn (PanelBuilder $panel) => $panel->resourcePrefix('backoffice')->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, InvoicePermission::class, 'ldap', 'database'])->roles([SellerRole::class])
        ->restrictions([RecordingRestriction::class, AuditFreeze::class])],
    'subject model' => [fn (PanelBuilder $panel) => $panel->for(Seller::class, guard: 'web')
        ->permissions([OrderPermission::class, InvoicePermission::class, 'ldap', 'database'])->roles([SellerRole::class])
        ->restrictions([RecordingRestriction::class, AuditFreeze::class])],
    'enum order' => [fn (PanelBuilder $panel) => $panel->for(User::class, guard: 'web')
        ->permissions([InvoicePermission::class, OrderPermission::class, 'ldap', 'database'])->roles([SellerRole::class])
        ->restrictions([RecordingRestriction::class, AuditFreeze::class])],
    'source order' => [fn (PanelBuilder $panel) => $panel->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, InvoicePermission::class, 'database', 'ldap'])->roles([SellerRole::class])
        ->restrictions([RecordingRestriction::class, AuditFreeze::class])],
    'role' => [fn (PanelBuilder $panel) => $panel->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, InvoicePermission::class, 'ldap', 'database'])
        ->restrictions([RecordingRestriction::class, AuditFreeze::class])],
    'hook order' => [fn (PanelBuilder $panel) => $panel->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, InvoicePermission::class, 'ldap', 'database'])->roles([SellerRole::class])
        ->restrictions([AuditFreeze::class, RecordingRestriction::class])],
]);

it('keeps closures and objects out of the fingerprint metadata', function (): void {
    $source = new ArraySource('ldap-live');
    $metadata = PanelFingerprint::metadata(...compilePanel(static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->for(User::class, guard: 'web')
        ->permissions([OrderPermission::class, $source, 'database'])
        ->before([static fn (): null => null, ContinueHook::class])
        ->tenantResolvers([new FixedTenantResolver])
        ->label('Ignored by the fingerprint')));

    expect($metadata)->toMatchArray([
        'id' => 'admin',
        'prefix' => 'admin',
        'default' => false,
        'subjects' => [['model' => User::class, 'guard' => 'web', 'directory' => null]],
        'enums' => [OrderPermission::class],
        'roles' => [],
        'sources' => [['class' => ArraySource::class, 'id' => 'ldap-live'], ['name' => 'database']],
        'resource_scopes' => [],
    ])->and($metadata['hooks'])->toMatchArray([
        'before' => ['closure', ContinueHook::class],
        'restrictions' => [],
        'tenant_resolvers' => [FixedTenantResolver::class],
    ])->and(json_encode($metadata, JSON_THROW_ON_ERROR))->not->toContain('Ignored by the fingerprint');
});
