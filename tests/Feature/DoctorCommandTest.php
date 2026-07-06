<?php

declare(strict_types=1);

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
