<?php

declare(strict_types=1);

use AzGuard\Attributes\SkipGuardCheck;
use AzGuard\AzGuardManager;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\CheckAccess;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('guard:doctor reports invalid model config without fatal', function (): void {
    config(['az-guard.models.role' => stdClass::class]);

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('az-guard.models.role')
        ->assertFailed();
});

it('guard:doctor json reports invalid model config', function (): void {
    config(['az-guard.models.role' => stdClass::class]);

    $this->artisan(command: 'guard:doctor --json')
        ->assertFailed();
});

it('guard:doctor reports an invalid scope model without crashing', function (): void {
    config(['az-guard.models.scope' => stdClass::class]);

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('az-guard.models.scope')
        ->assertFailed();
});

it('guard:doctor json reports an invalid scope model without crashing', function (): void {
    config(['az-guard.models.scope' => stdClass::class]);

    $this->artisan(command: 'guard:doctor --json')
        ->expectsOutputToContain('az-guard.models.scope')
        ->assertFailed();
});

it('guard:doctor reports effective model table and connection in text and JSON', function (): void {
    $textCode = Artisan::call('guard:doctor', ['--panel' => 'test']);
    $textOutput = Artisan::output();

    expect($textCode)->toBe(0)
        ->and($textOutput)->toContain('models.role', 'roles', 'testbench');

    $code = Artisan::call('guard:doctor', ['--panel' => 'test', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($payload)->toHaveKeys(['errors', 'warnings', 'abilities', 'models'])
        ->and($payload['models'])->toHaveCount(4)
        ->and($payload['models'][0])->toBe([
            'key' => 'models.role',
            'class' => Role::class,
            'table' => 'roles',
            'connection' => 'testbench',
        ]);
});

it('guard:doctor проходит для тестовой панели', function (): void {
    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->assertSuccessful();
});

it('guard:doctor не падает на роли с enum-пермишенами (канонная форма)', function (): void {
    // Регресс: checkRoles кастовал enum-кейс в строку/использовал как ключ массива
    // → фатал, хотя permissions() как list<UnitEnum> — документированная preferred-форма.
    $rolesDir = __DIR__.'/../Stubs/Posts/Roles';
    $rolePath = $rolesDir.'/EnumDoctorRole.php';

    File::ensureDirectoryExists(path: $rolesDir);
    File::put(path: $rolePath, contents: <<<'PHP'
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Posts\Roles;

use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Stubs\Permissions\TestPermission;

final class EnumDoctorRole extends BaseRole
{
    public function permissions(): array
    {
        return [TestPermission::PostView];
    }
}
PHP);

    try {
        $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
            ->assertSuccessful();
    } finally {
        File::delete(paths: $rolePath);
        File::deleteDirectory(directory: $rolesDir);
    }
});

it('guard:doctor warns (but does not fail) on a stale scope_class (C-03)', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    DB::table('model_has_scopes')->insert([
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => $project->getMorphClass(),
        'scope_entity_id' => $project->getKey(),
        'scope_class' => 'AzGuard\\Tests\\Stubs\\Roles\\ThisClassWasDeleted',
        'panel_id' => null,
    ]);

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('stale scope_class')
        ->assertSuccessful();
});

it('guard:doctor подсказывает headless-quick-start при 0 панелей (A-06)', function (): void {
    app()->instance(AzGuardManagerInterface::class, new AzGuardManager);
    AzGuard::clearResolvedInstance(AzGuardManagerInterface::class);

    $this->artisan(command: 'guard:doctor')
        ->expectsOutputToContain('No panels registered — see docs/introduction/headless-quick-start.md')
        ->assertSuccessful();
});

it('guard:doctor не подсказывает headless-quick-start, когда панель зарегистрирована', function (): void {
    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->doesntExpectOutputToContain('No panels registered')
        ->assertSuccessful();
});

it('guard:doctor находит дубликат ability', function (): void {
    $duplicatePath = __DIR__.'/../Stubs/Posts/Policies/DuplicatePostPolicy.php';

    File::put(path: $duplicatePath, contents: <<<'PHP'
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Posts\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use AzGuard\Tests\Stubs\User;

final class DuplicatePostPolicy
{
    #[GateAbility(permission: PostPermission::View)]
    public function canViewAgain(User $user): bool
    {
        return true;
    }
}
PHP);

    try {
        $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
            ->assertFailed();
    } finally {
        if (File::exists(path: $duplicatePath)) {
            File::delete(paths: $duplicatePath);
        }
    }
});

it('guard:doctor warns on missing CheckPermission in legacy mode', function (): void {
    Route::middleware(CheckAccess::class)
        ->get('/azguard-doctor-missing', DoctorMissingAttrController::class);

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('azguard.check without #[CheckPermission] or #[SkipGuardCheck]')
        ->assertSuccessful();
});

it('guard:doctor errors on missing CheckPermission when require_permission_attributes is on', function (): void {
    config(['az-guard.require_permission_attributes' => true]);

    Route::middleware(CheckAccess::class)
        ->get('/azguard-doctor-missing-strict', DoctorMissingAttrController::class);

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('azguard.check without #[CheckPermission] or #[SkipGuardCheck]')
        ->assertFailed();
});

it('guard:doctor json reports the same missing-attribute verdict', function (): void {
    Route::middleware(CheckAccess::class)
        ->get('/azguard-doctor-missing-json', DoctorMissingAttrController::class);

    $this->artisan(command: 'guard:doctor --json')
        ->expectsOutputToContain('azguard.check without #[CheckPermission] or #[SkipGuardCheck]')
        ->assertSuccessful();
});

it('guard:doctor skips SkipGuardCheck and closures', function (): void {
    Route::middleware(CheckAccess::class)
        ->get('/azguard-doctor-skip', [DoctorSkipAttrController::class, 'show']);
    Route::middleware(CheckAccess::class)
        ->get('/azguard-doctor-closure', fn (): string => 'ok');

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->doesntExpectOutputToContain('DoctorSkipAttrController')
        ->doesntExpectOutputToContain('azguard-doctor-closure')
        ->assertSuccessful();
});

it('guard:doctor errors on a missing role class_name', function (): void {
    createRoleWithClass(['name' => 'ghost', 'level' => 1], 'AzGuard\\Nope\\MissingRole');

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('invalid class_name')
        ->assertFailed();
});

it('guard:doctor json identifies an invalid role class_name', function (): void {
    createRoleWithClass(['name' => 'ghost', 'level' => 1], 'AzGuard\\Nope\\MissingRole');

    $this->artisan(command: 'guard:doctor --json')
        ->expectsOutputToContain('invalid class_name')
        ->assertFailed();
});

it('guard:doctor rejects a non-null empty role class_name', function (): void {
    $role = Role::query()->create(['name' => 'empty-code-role', 'level' => 1]);
    $role->class_name = '';
    $role->save();

    $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
        ->expectsOutputToContain('invalid class_name []')
        ->assertFailed();
});

it('guard:doctor reports generated policy model and actor errors', function (): void {
    $path = __DIR__.'/../Stubs/Posts/Policies/GeneratedBrokenPolicy.php';

    File::put(path: $path, contents: <<<'PHP'
<?php

declare(strict_types=1);

// azguard:generated-policy
namespace AzGuard\Tests\Stubs\Posts\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;

#[GuardPolicy(model: \AzGuard\Tests\Stubs\MissingDocument::class)]
final class GeneratedBrokenPolicy
{
    #[GateAbility(permission: PostPermission::View)]
    public function canView(\AzGuard\Tests\Stubs\MissingActor $user): bool
    {
        return true;
    }
}
PHP);

    try {
        $this->artisan(command: 'guard:doctor', parameters: ['--panel' => 'test'])
            ->expectsOutputToContain('invalid Eloquent model')
            ->expectsOutputToContain('invalid Authenticatable actor')
            ->assertFailed();
    } finally {
        File::delete(paths: $path);
    }
});

it('guard:doctor json reports generated enum missing from provider', function (): void {
    $directory = __DIR__.'/../Stubs/Posts/Billing/Permissions';
    $path = $directory.'/BillingPermission.php';
    File::ensureDirectoryExists(path: $directory);

    File::put(path: $path, contents: <<<'PHP'
<?php

declare(strict_types=1);

// azguard:generated-permission
namespace AzGuard\Tests\Stubs\Posts\Billing\Permissions;

enum BillingPermission: string
{
    case View = 'billing.view';
}
PHP);

    try {
        $this->artisan(command: 'guard:doctor --json')
            ->expectsOutputToContain('missing from provider permissionEnums')
            ->assertFailed();
    } finally {
        File::deleteDirectory(directory: dirname($directory));
    }
});

final class DoctorMissingAttrController
{
    public function __invoke(): string
    {
        return 'ok';
    }
}

final class DoctorSkipAttrController
{
    #[SkipGuardCheck]
    public function show(): string
    {
        return 'ok';
    }
}
