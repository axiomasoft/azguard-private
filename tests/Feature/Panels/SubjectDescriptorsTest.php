<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\SubjectDescriptor;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Database\Eloquent\Relations\Relation;

uses(BootsWithCatalogCache::class);

beforeEach(function (): void {
    FixturePanel::reset();
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-subjects-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'build-subjects';
});

afterEach(function (): void {
    Relation::morphMap([], false);
    @unlink(self::$catalogCachePath);
    @rmdir(dirname(self::$catalogCachePath));
});

function describedSubjects(PanelBuilder $panel): PanelBuilder
{
    return $panel->for(User::class, guard: 'web')->for([Vendor::class, Seller::class], directory: 'App\Directories\Partners')
        ->for(User::class, directory: 'App\Directories\Staff')->permissions([StaticSource::names('orders', 'orders.view')]);
}

it('keeps one immutable descriptor per subject model with its guard and directory', function (): void {
    Relation::morphMap(['fixture.user' => User::class], false);
    $panel = PanelWorld::compile([AdminPanel::class => describedSubjects(...)])[2]->get('admin');

    expect($panel->subjects())->toEqual([
        new SubjectDescriptor(User::class, 'web', 'App\Directories\Staff'),
        new SubjectDescriptor(Vendor::class, null, 'App\Directories\Partners'),
        new SubjectDescriptor(Seller::class, null, 'App\Directories\Partners'),
    ])
        ->and($panel->subjectModels())->toBe([User::class, Vendor::class, Seller::class])
        ->and($panel->guards())->toBe(['web'])
        ->and($panel->subject(new Vendor)?->directory)->toBe('App\Directories\Partners')
        ->and($panel->subject(SubjectRef::of('fixture.user', 1))?->guard)->toBe('web')
        ->and($panel->subject(Seller::class)?->model)->toBe(Seller::class)
        ->and($panel->subject(stdClass::class))->toBeNull()
        ->and($panel->accepts(new User))->toBeTrue()
        ->and((new ReflectionClass(SubjectDescriptor::class))->isReadOnly())->toBeTrue();
});

it('rejects two different guards or directories for one subject model', function (Closure $describe): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => $describe])[2]->get('admin'))->toThrow(DefinitionException::class);
})->with([
    'guards' => [fn (PanelBuilder $panel) => $panel->for(User::class, guard: 'web')->for(User::class, guard: 'api')],
    'directories' => [fn (PanelBuilder $panel) => $panel->for(User::class, directory: 'A\Directory')->for(User::class, directory: 'B\Directory')],
]);

it('keeps descriptors in a build that boots from the catalog cache', function (): void {
    AdminPanel::describe(describedSubjects(...));
    $this->bootCatalogPanels([AdminPanel::class]);
    $live = app(PanelRegistry::class)->get('admin')->subjects();
    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    AdminPanel::describe(describedSubjects(...));
    $this->bootCatalogPanels([AdminPanel::class]);

    expect(StaticSource::$reads)->toBe([])
        ->and(app(PanelRegistry::class)->get('admin')->subjects())->toEqual($live)
        ->and($live[0]->directory)->toBe('App\Directories\Staff');
});
