<?php

declare(strict_types=1);

namespace AzGuard\Extensions;

use AzGuard\AzGuard;
use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Diagnostics\AzGuardHealthCheck;
use AzGuard\Diagnostics\Doctor;
use AzGuard\Errors\AzGuardProblemDefinitions;
use AzGuard\Panels\AzGuardPanelProvider;
use AzGuard\Schema\AzGuardSchemaSets;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Packman core extension plugin declaring AzGuard's configuration, schema, capabilities, slots, panels, and diagnostics.
 */
#[Api(since: '1.0')]
final class AzGuardCorePlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithPanels, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.azguard',
            version: '2.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['sqlite', 'pgsql', 'mysql'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'azguard';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/azguard.php';
    }

    /** @return class-string<AzGuardOptions> */
    public function optionsClass(): string
    {
        return AzGuardOptions::class;
    }

    public function tablePrefix(): string
    {
        return 'azg_';
    }

    /** @return list<string> */
    public function tableNouns(): array
    {
        return [
            'permissions',
            'role_grants',
            'permission_grants',
            'panel_state',
            'subject_revisions',
            'storage_state',
        ];
    }

    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    public function schemaSetProviders(): array
    {
        return [AzGuardSchemaSets::class];
    }

    /** @return array<class-string, string> */
    public function definedCapabilities(): array
    {
        return [
            AzGuard::class => Cardinality::RequiredSingle->value,
            PanelRegistry::class => Cardinality::RequiredSingle->value,
            Doctor::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    public function providedCapabilities(): array
    {
        return [
            AzGuard::class => AzGuard::class,
            Doctor::class => Doctor::class,
        ];
    }

    /** @return array<class-string, array{kind: string, cardinality: string}> */
    public function definedSlots(): array
    {
        return [
            BeforeHook::class => [
                'kind' => SlotKind::Guard->value,
                'cardinality' => Cardinality::Many->value,
            ],
            AfterHook::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            Restriction::class => [
                'kind' => SlotKind::Restriction->value,
                'cardinality' => Cardinality::Many->value,
            ],
            GrantCondition::class => [
                'kind' => SlotKind::Guard->value,
                'cardinality' => Cardinality::Many->value,
            ],
            ChangePipe::class => [
                'kind' => SlotKind::OperationMiddleware->value,
                'cardinality' => Cardinality::Many->value,
            ],
            ChangeParticipant::class => [
                'kind' => SlotKind::Contributor->value,
                'cardinality' => Cardinality::Many->value,
            ],
        ];
    }

    /** @return list<class-string> */
    public function panelProviders(): array
    {
        return [AzGuardPanelProvider::class];
    }

    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [AzGuardHealthCheck::class];
    }

    /** @return list<class-string> */
    public function problemProviders(): array
    {
        return [AzGuardProblemDefinitions::class];
    }
}
