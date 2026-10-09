<?php

declare(strict_types=1);

use AzGuard\Diagnostics\Doctor;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Attributes\ForFilament;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Diagnostics\FilamentDefinitionsCheck;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;

/*
 * V75: `azguard:filament:generate` writes the permission enums of the resources, pages and widgets of a Filament panel
 * with the generators of the core: the keys are the ones FilamentKeys gives, the authority is only `--authority`, a
 * policy never opens what it does not decide, and a second run does not overwrite. The doctor finds a definition whose
 * class is gone and a class without definition.
 * Snapshots of the generated files: Fixtures/Filament/Snapshots/generate (AZGUARD_UPDATE_SNAPSHOTS=1 writes them again).
 */

/** The application of the test with a throwaway base path, a composer.json that names its namespace and the `Admin` panel directory. */
function generatedApp(): GeneratedApp
{
    $app = GeneratedApp::in(app());
    file_put_contents($app->path('composer.json'), '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    mkdir($app->path('app/Guards/Admin'), 0o755, true);

    return $app;
}

/** @return list<string> the generated files, without the composer.json of the application */
function generatedPaths(GeneratedApp $app): array
{
    return array_values(array_filter($app->files(), static fn (string $path): bool => $path !== 'composer.json'));
}

/** @return array<string, string> the generated files by path, with the namespace of the test replaced */
function generatedFiles(GeneratedApp $app): array
{
    $files = [];

    foreach (generatedPaths($app) as $path) {
        $files[$path] = str_replace($app->namespace, 'App', $app->read($path));
    }

    return $files;
}

/** @return list<class-string<UnitEnum>> */
function generatedEnums(GeneratedApp $app): array
{
    $enums = [];

    foreach (generatedPaths($app) as $path) {
        if (str_contains($path, '/Permissions/')) {
            /** @var class-string<UnitEnum> $class */
            $class = $app->namespace.'\\'.str_replace('/', '\\', substr($path, strlen('app/'), -strlen('.php')));
            $enums[] = $class;
        }
    }

    return $enums;
}

/** @return list<string> the keys of all cases of the generated enums */
function generatedKeys(GeneratedApp $app): array
{
    $keys = [];

    foreach (generatedEnums($app) as $enum) {
        foreach ((new ReflectionEnum($enum))->getCases() as $case) {
            $keys[] = (string) $case->getBackingValue();
        }
    }
    sort($keys);

    return $keys;
}

/** @return list<string> the keys that the Filament panel `admin` checks */
function checkedKeys(): array
{
    $keys = array_map(static fn ($key): string => $key->local, AzGuardPlugin::get('admin')->keys(Filament::getPanel('admin'))->all());
    sort($keys);

    return $keys;
}

/** @param array<string, mixed> $options */
function runGenerate(array $options = []): int
{
    return Artisan::call('azguard:filament:generate', ['--filament-panel' => 'admin', ...$options]);
}

/** @return list<DoctorFinding> */
function doctorDefinitionFindings(): array
{
    $doctor = app(Doctor::class);

    return array_values(array_filter($doctor->run($doctor->context(['admin'])), static fn (DoctorFinding $finding): bool => str_starts_with($finding->key, 'filament.')));
}

beforeEach(function (): void {
    GateWorld::prepare();
    FilamentFixture::$guardPermissions = [];
    FilamentFixture::$guardPolicies = [];
    FilamentFixture::$memberPermissions = [];
    $this->bootFilament();
    $this->generated = generatedApp();
});
afterEach(fn () => $this->generated->release());

it('V75 writes an enum for each resource, one for the pages and one for the widgets, as the snapshot shows', function (): void {
    expect(runGenerate())->toBe(0);
    $files = generatedFiles($this->generated);
    $directory = __DIR__.'/../../../Fixtures/Filament/Snapshots/generate';

    if (getenv('AZGUARD_UPDATE_SNAPSHOTS') === '1') {
        foreach ($files as $path => $contents) {
            @mkdir(dirname($directory.'/'.$path), 0o755, true);
            file_put_contents($directory.'/'.$path.'.snap', $contents);
        }
    }
    $expected = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $expected[substr($file->getPathname(), strlen($directory) + 1, -strlen('.snap'))] = (string) file_get_contents($file->getPathname());
    }
    ksort($expected);
    ksort($files);

    expect($files)->toBe($expected);
});

it('V75 gives the enums the keys that the Filament panel checks, and the catalog of the guard panel compiles them', function (): void {
    expect(runGenerate(['--only' => ['resources', 'widgets']]))->toBe(0);
    $keys = generatedKeys($this->generated);

    expect($keys)->toBe(array_values(array_filter(checkedKeys(), static fn (string $key): bool => ! str_starts_with($key, 'pages.'))));

    FilamentFixture::$guardPermissions = generatedEnums($this->generated);
    $this->bootFilament();
    $catalog = AzGuard::panel('admin')->inTenant(TenantRef::global())->catalog();

    foreach ($keys as $key) {
        expect($catalog->has($key))->toBeTrue($key)
            ->and($catalog->get($key)->authority)->toBe(PermissionAuthority::Grants);
    }
});

it('V75 marks each enum with the class it defines and keeps the authority at grants by default', function (): void {
    runGenerate();
    $orders = $this->generated->read('app/Guards/Admin/Permissions/Sales/Orders/OrderPermission.php');
    $pages = $this->generated->read('app/Guards/Admin/Permissions/Pages/PagePermission.php');

    expect($orders)->toContain('#[ForFilament(OrderResource::class)]', 'use '.OrderResource::class.';', 'use '.ForFilament::class.';', '#[RequiresGrant]', "case ViewAny = 'orders.view_any';", 'model: Order::class')
        ->and($orders)->not->toContain('PolicyOnly')
        ->and($pages)->toContain('#[ForFilament(ProbePage::class)]', "case Probe = 'pages.probe';", "case Reports = 'pages.reports';")
        ->and(array_filter(generatedPaths($this->generated), static fn (string $path): bool => str_contains($path, '/Policies/')))->toBe([]);
});

it('V75 declares the permissions policy-only with --authority=policy and writes policies that deny', function (): void {
    expect(runGenerate(['--authority' => 'policy']))->toBe(0);
    $files = generatedFiles($this->generated);
    $policies = array_filter($files, static fn (string $path): bool => str_contains($path, '/Policies/'), ARRAY_FILTER_USE_KEY);
    $enums = array_filter($files, static fn (string $path): bool => str_contains($path, '/Permissions/'), ARRAY_FILTER_USE_KEY);

    expect($policies)->toHaveCount(count($enums))->not->toBe([]);

    foreach ($policies as $contents) {
        expect($contents)->toContain('return false;')->not->toContain('return true;');
    }

    foreach ($enums as $contents) {
        expect($contents)->toContain('#[PolicyOnly]')->not->toContain('RequiresGrant');
    }
});

it('V75 binds a policy that only passes to the enum with --with-policy and keeps the authority at grants', function (): void {
    expect(runGenerate(['--with-policy' => true, '--only' => ['OrderResource']]))->toBe(0);
    $enum = $this->generated->read('app/Guards/Admin/Permissions/Sales/Orders/OrderPermission.php');
    $policy = $this->generated->read('app/Guards/Admin/Policies/Sales/Orders/OrderPolicy.php');

    expect($enum)->toContain('#[RequiresGrant]')->not->toContain('PolicyOnly')
        ->and($policy)->toContain('#[PolicyFor(OrderPermission::class)]', '#[Decides(OrderPermission::ViewAny)]')
        ->and(substr_count($policy, '#[Decides('))->toBe(substr_count($enum, 'case '))
        ->and(substr_count($policy, 'return true;'))->toBe(substr_count($enum, 'case '))
        ->and($policy)->not->toContain('return false;');
});

it('V75 does not overwrite a second run without --force, and does with it', function (): void {
    runGenerate(['--only' => ['OrderResource']]);
    $path = 'app/Guards/Admin/Permissions/Sales/Orders/OrderPermission.php';
    file_put_contents($this->generated->path($path), $this->generated->read($path).'// owner edit'."\n");

    expect(runGenerate(['--only' => ['OrderResource']]))->toBe(1)
        ->and(Artisan::output())->toContain('already exists')
        ->and($this->generated->read($path))->toContain('// owner edit');

    expect(runGenerate(['--only' => ['OrderResource'], '--force' => true]))->toBe(0)
        ->and($this->generated->read($path))->not->toContain('// owner edit');
});

it('prints the plan and writes nothing with --dry-run', function (): void {
    expect(runGenerate(['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Admin/Sales/Orders', 'orders.view_any', 'Admin/Pages', 'pages.probe', 'Admin/Widgets', 'widgets.order-count')
        ->and(generatedPaths($this->generated))->toBe([]);
});

it('generates the permissions of the editors of AzGuard like those of any resource', function (): void {
    $this->generated->release();
    FilamentFixture::$editors = ['roles' => true, 'permissions' => true];
    $this->bootFilament();
    $this->generated = generatedApp();

    expect(runGenerate(['--only' => ['resources']]))->toBe(0);

    expect($this->generated->read('app/Guards/Admin/Permissions/AzGuard/AzguardRoles/AzguardRolePermission.php'))
        ->toContain("case ViewAny = 'azguard-roles.view_any';", '#[ForFilament(RoleResource::class)]');
});

it('refuses an authority that is neither grants nor policy, and a Filament panel that takes its definitions from the resources', function (): void {
    expect(runGenerate(['--authority' => 'both']))->toBe(2);

    $this->generated->release();
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    $this->bootFilament();
    $this->generated = generatedApp();

    expect(runGenerate())->toBe(2)
        ->and(Artisan::output())->toContain('FilamentSource');
});

it('V75 reports a class that has no definition as an error and a definition whose class is gone as a warning', function (): void {
    $this->generated->release();
    FilamentFixture::$doctorChecks = [FilamentDefinitionsCheck::class];
    $this->bootFilament();
    $missing = doctorDefinitionFindings();

    expect(array_unique(array_map(static fn (DoctorFinding $finding): string => $finding->key, $missing)))->toBe(['filament.missing_definition'])
        ->and(array_column(array_map(static fn (DoctorFinding $finding): array => $finding->details, $missing), 'permission'))->toContain('orders.view_any', 'widgets.order-count');

    $this->generated = generatedApp();
    runGenerate(['--only' => ['resources', 'widgets']]);
    FilamentFixture::$guardPermissions = generatedEnums($this->generated);
    FilamentFixture::$resources = [OrderResource::class];
    FilamentFixture::$pages = [ProbePage::class];
    $this->bootFilament();
    $findings = doctorDefinitionFindings();

    expect(array_map(static fn (DoctorFinding $finding): string => $finding->key, $findings))->toBe(['filament.stale_definition'])
        ->and($findings[0]->details['class'])->toBe(ArchivedOrderResource::class)
        ->and($findings[0]->severity->value)->toBe('warning');
});

it('reports nothing when every class has its definition and every definition its class', function (): void {
    runGenerate(['--only' => ['resources', 'widgets']]);
    FilamentFixture::$doctorChecks = [FilamentDefinitionsCheck::class];
    FilamentFixture::$guardPermissions = generatedEnums($this->generated);
    FilamentFixture::$pages = [ProbePage::class];
    $this->bootFilament();

    expect(doctorDefinitionFindings())->toBe([]);
});
