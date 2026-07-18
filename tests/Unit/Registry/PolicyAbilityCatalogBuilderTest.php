<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Builders\PolicyAbilityCatalogBuilder;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use AzGuard\Tests\Stubs\Posts\Policies\PostPolicy;
use Illuminate\Support\Facades\Log;

// ─── Helpers ──────────────────────────────────────────────────────────────

/**
 * Build a manager stub that returns the given panel for its id.
 */
function fakeCatalogManager(?Panel $panel): AzGuardManagerInterface
{
    $mock = Mockery::mock(AzGuardManagerInterface::class);
    $mock->shouldReceive('panel')->andReturn($panel);

    return $mock;
}

function testPanel(): Panel
{
    return Panel::make()->id('test')->scopedByPanelId(true);
}

// ─── Tests ────────────────────────────────────────────────────────────────

describe('PolicyAbilityCatalogBuilder', function () {

    it('does not crash boot on a non-existent / stale policy class', function () {
        $builder = new PolicyAbilityCatalogBuilder(
            panelId: 'test',
            policyClasses: ['AzGuard\Tests\Stubs\Posts\Policies\StaleRenamedPolicy'],
            manager: fakeCatalogManager(testPanel()),
        );

        // Must return normally (empty list), not throw a ReflectionException.
        $definitions = $builder->build('test');

        expect($definitions)->toBe([]);
    });

    it('surfaces the missing policy class to diagnostics via a log warning', function () {
        Log::spy();

        $builder = new PolicyAbilityCatalogBuilder(
            panelId: 'test',
            policyClasses: ['AzGuard\Tests\Stubs\Posts\Policies\StaleRenamedPolicy'],
            manager: fakeCatalogManager(testPanel()),
        );

        $builder->build('test');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'StaleRenamedPolicy')
                    && ($context['panel'] ?? null) === 'test';
            });
    });

    it('still produces catalog entries for valid policy classes', function () {
        $builder = new PolicyAbilityCatalogBuilder(
            panelId: 'test',
            policyClasses: [PostPolicy::class],
            manager: fakeCatalogManager(testPanel()),
        );

        $definitions = $builder->build('test');

        expect($definitions)->toHaveCount(1);

        $expectedKey = testPanel()->resolvePermission(PostPermission::View);

        expect($definitions[0]->key())->toBe($expectedKey);
    });

    it('skips only the stale class and keeps valid entries when both are listed', function () {
        Log::spy();

        $builder = new PolicyAbilityCatalogBuilder(
            panelId: 'test',
            policyClasses: [
                'AzGuard\Tests\Stubs\Posts\Policies\StaleRenamedPolicy',
                PostPolicy::class,
            ],
            manager: fakeCatalogManager(testPanel()),
        );

        $definitions = $builder->build('test');

        // Stale skipped, valid one survives.
        expect($definitions)->toHaveCount(1)
            ->and($definitions[0]->key())->toBe(testPanel()->resolvePermission(PostPermission::View));

        Log::shouldHaveReceived('warning')->once();
    });

    it('uses the injected manager — no static facade call inside build()', function () {
        // The injected manager is the only source of the panel; a mock proves
        // build() reads it (DI parity with GrantSource) rather than a static
        // AzGuard::panel() call. If build() ignored the injected manager and hit
        // the container/facade instead, panel() would resolve differently.
        $mock = Mockery::mock(AzGuardManagerInterface::class);
        $mock->shouldReceive('panel')->once()->with('test')->andReturn(testPanel());

        $builder = new PolicyAbilityCatalogBuilder(
            panelId: 'test',
            policyClasses: [PostPolicy::class],
            manager: $mock,
        );

        $builder->build('test');

        // Mockery expectation (->once()->with('test')) verifies the injected
        // manager was the one consulted.
        expect(true)->toBeTrue();
    });

    it('returns empty list when the panel is not registered', function () {
        $builder = new PolicyAbilityCatalogBuilder(
            panelId: 'test',
            policyClasses: [PostPolicy::class],
            manager: fakeCatalogManager(null),
        );

        expect($builder->build('test'))->toBe([]);
    });
});
