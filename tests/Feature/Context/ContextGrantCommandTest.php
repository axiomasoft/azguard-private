<?php

declare(strict_types=1);

use AzGuard\Context\Events\ContextGrantGiven;
use AzGuard\Context\Events\ContextGrantRevoked;
use AzGuard\Context\Models\ContextRole;
use AzGuard\PermissionKey;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Event;

/**
 * F14 — write-API: guard:context:grant / guard:context:revoke place and remove
 * context-scoped grants from the CLI, delegating to ContextGrantBuilder.
 */
describe('guard:context:grant command', function (): void {

    it('writes a context grant row from the CLI', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:context:grant', [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ])
            ->expectsOutputToContain('Context grant issued.')
            ->assertExitCode(0);

        expect(ContextRole::query()->where([
            'model_type' => User::class,
            'model_id' => $user->getAuthIdentifier(),
            'context_type' => 'workspace',
            'context_id' => 7,
            'panel_id' => 'test',
            'permission_key' => 'test.post.export',
        ])->exists())->toBeTrue();
    });

    it('is idempotent — a repeated grant does not duplicate the row', function (): void {
        $user = User::factory()->create();

        $args = [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ];

        $this->artisan('guard:context:grant', $args)->assertExitCode(0);
        $this->artisan('guard:context:grant', $args)->assertExitCode(0);

        expect(ContextRole::query()
            ->where('model_id', $user->getAuthIdentifier())
            ->where('permission_key', 'test.post.export')
            ->count())->toBe(1);
    });

    it('fires ContextGrantGiven', function (): void {
        Event::fake([ContextGrantGiven::class]);
        $user = User::factory()->create();

        $this->artisan('guard:context:grant', [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ])->assertExitCode(0);

        Event::assertDispatched(ContextGrantGiven::class);
    });

    it('fails for an unknown user', function (): void {
        $this->artisan('guard:context:grant', [
            'user-id' => 99999,
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });
});

describe('guard:context:revoke command', function (): void {

    it('removes a specific context grant from the CLI', function (): void {
        $user = User::factory()->create();

        ContextRole::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getAuthIdentifier(),
            'context_type' => 'workspace',
            'context_id' => 7,
            'panel_id' => 'test',
            'permission_key' => 'test.post.export',
        ]);

        $this->artisan('guard:context:revoke', [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ])
            ->expectsOutputToContain('revoked')
            ->assertExitCode(0);

        expect(ContextRole::query()
            ->where('model_id', $user->getAuthIdentifier())
            ->where('permission_key', 'test.post.export')
            ->exists())->toBeFalse();
    });

    it('warns when the grant does not exist', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:context:revoke', [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ])
            ->expectsOutputToContain('not found or already revoked')
            ->assertExitCode(0);
    });

    it('revokes every grant for the context with --all --force', function (): void {
        $user = User::factory()->create();

        foreach (['test.post.export', 'test.post.view'] as $key) {
            ContextRole::query()->create([
                'model_type' => User::class,
                'model_id' => $user->getAuthIdentifier(),
                'context_type' => 'workspace',
                'context_id' => 7,
                'panel_id' => 'test',
                'permission_key' => $key,
            ]);
        }

        $this->artisan('guard:context:revoke', [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'ignored',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
            '--all' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 2 context grant(s).')
            ->assertExitCode(0);

        expect(ContextRole::query()
            ->where('model_id', $user->getAuthIdentifier())
            ->count())->toBe(0);
    });

    it('fires ContextGrantRevoked with the wildcard key on --all', function (): void {
        $user = User::factory()->create();
        ContextRole::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getAuthIdentifier(),
            'context_type' => 'workspace',
            'context_id' => 7,
            'panel_id' => 'test',
            'permission_key' => 'test.post.export',
        ]);

        Event::fake([ContextGrantRevoked::class]);

        $this->artisan('guard:context:revoke', [
            'user-id' => $user->getAuthIdentifier(),
            'permission' => 'ignored',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
            '--all' => true,
            '--force' => true,
        ])->assertExitCode(0);

        Event::assertDispatched(
            ContextGrantRevoked::class,
            fn (ContextGrantRevoked $e): bool => $e->permissionKey === PermissionKey::WILDCARD,
        );
    });

    it('fails for an unknown user', function (): void {
        $this->artisan('guard:context:revoke', [
            'user-id' => 99999,
            'permission' => 'test.post.export',
            'panel' => 'test',
            'context-type' => 'workspace',
            'context-id' => 7,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });
});
