<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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
