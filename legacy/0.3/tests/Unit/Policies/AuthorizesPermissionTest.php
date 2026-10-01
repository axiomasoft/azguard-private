<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Policies\AuthorizesPermission;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class CoveragePermissionPolicy
{
    use AuthorizesPermission;

    public function view(Authenticatable $user): bool
    {
        return $this->allows(TestPermission::PostView, $user);
    }

    protected function panelId(): string
    {
        return 'test';
    }
}

final class CoverageMissingPanelPolicy
{
    use AuthorizesPermission;

    public function view(Authenticatable $user): bool
    {
        return $this->allows(TestPermission::PostView, $user);
    }

    protected function panelId(): string
    {
        return 'missing-panel';
    }
}

it('allows a user who has the resolved permission on the policy panel', function (): void {
    $user = User::factory()->create();
    AzGuard::forUser($user)->on('test')->grant(TestPermission::PostView);

    expect((new CoveragePermissionPolicy)->view($user))->toBeTrue();
});

it('denies a user without hasPermission', function (): void {
    $user = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    expect((new CoveragePermissionPolicy)->view($user))->toBeFalse();
});

it('throws when the policy panel is not registered', function (): void {
    $user = User::factory()->create();

    expect(fn () => (new CoverageMissingPanelPolicy)->view($user))
        ->toThrow(RuntimeException::class, 'AzGuard panel [missing-panel] is not registered.');
});
