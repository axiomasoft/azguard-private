<?php

declare(strict_types=1);

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Tests\Fixtures\Panels\FixedResourceScopeResolver;
use AzGuard\Tests\Fixtures\Panels\FixedTenantResolver;
use AzGuard\Tests\Fixtures\Panels\Manager;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use AzGuard\Tests\Fixtures\Plugins\Account;
use AzGuard\Tests\Fixtures\Plugins\AuditJournal;
use AzGuard\Tests\Fixtures\Plugins\AuditTrailPlugin;
use AzGuard\Tests\Fixtures\Plugins\CrmAccessPlugin;
use AzGuard\Tests\Fixtures\Plugins\CrmModels;

it('declares the plugin as an id and a declaration-only register(), without a lifecycle or dependencies', function (): void {
    $plugin = new ReflectionClass(Plugin::class);
    $signature = static fn (string $method): array => array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $plugin->getMethod($method)->getParameters(),
    );

    expect(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $plugin->getMethods()))
        ->toBe(['id', 'register'])
        ->and($signature('register'))->toBe([PanelBuilder::class, PluginContext::class])
        ->and((string) $plugin->getMethod('register')->getReturnType())->toBe('void')
        ->and((string) $plugin->getMethod('id')->getReturnType())->toBe('string')
        ->and(interface_exists('AzGuard\\Contracts\\Plugins\\PrefixesKeys'))->toBeFalse();
});

it('tells a plugin its panel, its id and the build only', function (): void {
    $context = new ReflectionClass(PluginContext::class);

    expect(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $context->getMethods()))
        ->toBe(['__construct', 'panelId', 'pluginId', 'buildId'])
        ->and(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $context->getConstructor()?->getParameters() ?? []))
        ->toBe(['panelId', 'pluginId', 'buildId']);
});

it('gives every fixture plugin its own factory with named typed parameters', function (string $plugin, array $parameters): void {
    $make = new ReflectionMethod($plugin, 'make');
    $actual = [];

    foreach ($make->getParameters() as $parameter) {
        $actual[$parameter->getName()] = (string) $parameter->getType();
    }

    expect($make->isStatic())->toBeTrue()
        ->and($make->getDeclaringClass()->getName())->toBe($plugin)
        ->and($actual)->toBe($parameters);
})->with([
    'audit trail' => [AuditTrailPlugin::class, ['retentionDays' => 'int']],
    'crm access' => [CrmAccessPlugin::class, ['models' => CrmModels::class, 'clientScope' => 'string']],
]);

it('creates a plugin through the container, so a binding of its service applies', function (): void {
    $journal = new class extends AuditJournal {};
    app()->instance(AuditJournal::class, $journal);

    $plugin = AuditTrailPlugin::make(retentionDays: 30);

    expect($plugin->journal())->toBe($journal)
        ->and($plugin->retentionDays())->toBe(30)
        ->and(AuditTrailPlugin::make()->retentionDays())->toBe(90);
});

it('changes a plugin setting on a copy', function (): void {
    $plugin = AuditTrailPlugin::make(retentionDays: 90);
    $short = $plugin->retention(30);

    expect($short)->not->toBe($plugin)
        ->and($short->retentionDays())->toBe(30)
        ->and($plugin->retentionDays())->toBe(90)
        ->and(fn () => $plugin->retention(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AuditTrailPlugin::make(retentionDays: 0))->toThrow(InvalidArgumentException::class);
});

it('rejects a model that does not fit before any panel is built', function (array $models, string $message): void {
    $models += ['subject' => Account::class, 'organization' => Vendor::class, 'project' => Manager::class, 'client' => Seller::class];

    expect(fn () => new CrmModels(...$models))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a client that is not a model' => [['client' => stdClass::class], 'Expected Model: stdClass'],
    'a subject that is not a model' => [['subject' => FixedTenantResolver::class], 'Expected Model'],
    'a subject that cannot sign in' => [['subject' => User::class], 'subject must implement Authenticatable'],
]);

it('rejects a factory argument of the wrong contract', function (): void {
    $models = new CrmModels(subject: Account::class, organization: Vendor::class, project: Manager::class, client: Seller::class);

    expect(fn () => CrmAccessPlugin::make(models: $models, clientScope: FixedTenantResolver::class))
        ->toThrow(InvalidArgumentException::class, 'clientScope')
        ->and(CrmAccessPlugin::make(models: $models, clientScope: FixedResourceScopeResolver::class)->id())->toBe('acme/crm-access');
});
