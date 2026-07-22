<?php

declare(strict_types=1);

namespace AzGuard\Tests\Unit\Auth;

use AzGuard\Auth\DirectGrantPolicy;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use AzGuard\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\MockObject\MockObject;

class DirectGrantPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DirectGrantPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DirectGrantPolicy;
    }

    protected function createUserMock(): MockObject
    {
        return $this->getMockBuilder(UserWithDirectGrants::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasGrant'])
            ->getMock();
    }

    protected function createUserWithoutGrantsTrait(): Authenticatable
    {
        return new class implements Authenticatable
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

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };
    }

    protected function createUserWithDirectGrant(string $permission, string $panelId): UserWithDirectGrants
    {
        $user = UserWithDirectGrants::factory()->create();
        $user->directGrants()->create([
            'panel_id' => $panelId,
            'permission_key' => $permission,
            'expires_at' => null,
        ]);

        return $user;
    }

    public function test_returns_false_if_model_lacks_has_direct_grant(): void
    {
        $user = $this->createUserWithoutGrantsTrait();

        $result = $this->policy->check($user, 'app.x');

        $this->assertFalse($result);
    }

    public function test_delegates_to_has_direct_grant_with_string_arg(): void
    {
        $user = $this->createUserMock();
        $user->expects($this->once())
            ->method('hasGrant')
            ->with('app.x', null)
            ->willReturn(true);

        $result = $this->policy->check($user, 'app.x');

        $this->assertTrue($result);
    }

    public function test_delegates_with_array_arg(): void
    {
        $user = $this->createUserMock();
        $user->expects($this->once())
            ->method('hasGrant')
            ->with('app.x', 'app')
            ->willReturn(false);

        $result = $this->policy->check($user, ['app.x', 'app']);

        $this->assertFalse($result);
    }

    public function test_uses_current_panel_when_panel_not_set(): void
    {
        $panel = Panel::make()->id('app');
        AzGuard::setCurrentPanel($panel);

        $user = $this->createUserMock();
        $user->expects($this->once())
            ->method('hasGrant')
            ->with('app.x', 'app')
            ->willReturn(true);

        $result = $this->policy->check($user, ['app.x']);

        $this->assertTrue($result);
    }

    public function test_gate_integration(): void
    {
        $user = $this->createUserWithDirectGrant('app.documents.export', 'app');
        $this->actingAs($user);

        $this->assertTrue(Gate::allows('direct-grant', 'app.documents.export'));
        $this->assertFalse(Gate::allows('direct-grant', 'app.documents.delete'));
    }
}
