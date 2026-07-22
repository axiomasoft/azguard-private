<?php

declare(strict_types=1);

namespace AzGuard\Registry\Builders;

use AzGuard\Attributes\GateAbility;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Guard\PolicyDiscovery;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Registry\Definitions\EnumPermissionDefinition;
use Illuminate\Support\Facades\Log;
use Override;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds permission catalog entries from policy methods annotated with #[GateAbility].
 *
 * When $policyClasses are provided explicitly (via PanelProvider::registerCatalogBuilders()),
 * those classes are used directly. Otherwise falls back to filesystem discovery.
 */
final readonly class PolicyAbilityCatalogBuilder implements PermissionCatalogBuilder
{
    private AzGuardManagerInterface $manager;

    /**
     * @param  string|null  $panelId  When set, this builder only handles this panel.
     * @param  list<class-string>  $policyClasses  Explicit policy class list (optional).
     * @param  AzGuardManagerInterface|null  $manager  Injected manager (parity with GrantSource DI).
     *                                                 Falls back to the container when this builder
     *                                                 is constructed directly via `new` (PanelProvider).
     */
    public function __construct(
        private ?string $panelId = null,
        private array $policyClasses = [],
        ?AzGuardManagerInterface $manager = null,
    ) {
        $this->manager = $manager ?? app(AzGuardManagerInterface::class);
    }

    #[Override]
    public function build(string $panelId): array
    {
        $panel = $this->manager->panel($panelId);

        if ($panel === null) {
            return [];
        }

        $classes = match (true) {
            $this->policyClasses !== [] => $this->policyClasses,
            $this->panelId !== null => [],
            default => $this->discoverPolicyClasses($panel->getBasePath(), $panel->getNamespace()),
        };

        $definitions = [];

        foreach ($classes as $policyClass) {
            if (! class_exists($policyClass)) {
                Log::warning("AzGuard: policy class [{$policyClass}] does not exist, skipping catalog entry.", [
                    'panel' => $panelId,
                ]);

                continue;
            }

            $reflection = new ReflectionClass($policyClass);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(GateAbility::class) as $attribute) {
                    /** @var GateAbility $gateAbility */
                    $gateAbility = $attribute->newInstance();
                    $permission = $gateAbility->permission;

                    $resolvedKey = $panel->resolvePermission($permission);

                    $definitions[] = EnumPermissionDefinition::fromCase(
                        case: $permission,
                        panelId: $panelId,
                        resolvedKey: $resolvedKey,
                    );
                }
            }
        }

        return $definitions;
    }

    #[Override]
    public function supports(string $panelId): bool
    {
        if ($this->panelId !== null) {
            return $this->panelId === $panelId;
        }

        return $this->manager->panel($panelId) !== null;
    }

    /**
     * @return list<class-string>
     */
    private function discoverPolicyClasses(string $basePath, string $baseNamespace): array
    {
        if ($basePath === '' || $baseNamespace === '') {
            return [];
        }

        return (new PolicyDiscovery)->discoverPolicyClasses(
            basePath: $basePath,
            baseNamespace: $baseNamespace,
        );
    }
}
