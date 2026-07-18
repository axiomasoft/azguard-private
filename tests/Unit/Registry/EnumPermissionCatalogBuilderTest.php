<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Registry\Builders\EnumPermissionCatalogBuilder;
use AzGuard\Support\Panel;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use Illuminate\Support\Facades\Log;

// ─── Helpers ──────────────────────────────────────────────────────────────

/**
 * Build a manager stub that returns the given panel for its id.
 */
function fakeEnumCatalogManager(?Panel $panel): AzGuardManagerInterface
{
    $mock = Mockery::mock(AzGuardManagerInterface::class);
    $mock->shouldReceive('panel')->andReturn($panel);

    return $mock;
}

function enumTestPanel(): Panel
{
    return Panel::make()->id('test')->scopedByPanelId(true);
}

// ─── Tests ────────────────────────────────────────────────────────────────

describe('EnumPermissionCatalogBuilder', function () {

    it('surfaces the missing enum class to diagnostics via a log warning', function () {
        Log::spy();

        $builder = new EnumPermissionCatalogBuilder(
            panelId: 'test',
            enumClasses: ['AzGuard\Tests\Stubs\Posts\Permissions\StaleRemovedPermission'],
            manager: fakeEnumCatalogManager(enumTestPanel()),
        );

        $builder->build('test');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'StaleRemovedPermission')
                    && ($context['panel'] ?? null) === 'test';
            });
    });

    it('skips only the stale class and keeps valid entries when both are listed', function () {
        Log::spy();

        $builder = new EnumPermissionCatalogBuilder(
            panelId: 'test',
            enumClasses: [
                'AzGuard\Tests\Stubs\Posts\Permissions\StaleRemovedPermission',
                PostPermission::class,
            ],
            manager: fakeEnumCatalogManager(enumTestPanel()),
        );

        $definitions = $builder->build('test');

        expect($definitions)->toHaveCount(1)
            ->and($definitions[0]->key())->toBe(enumTestPanel()->resolvePermission(PostPermission::View));

        Log::shouldHaveReceived('warning')->once();
    });
});
