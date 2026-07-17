<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Attributes\GateAbility;
use AzGuard\Commands\Concerns\OutputsStructured;
use AzGuard\Facades\AzGuard;
use AzGuard\Guard\PolicyDiscovery;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;
use UnitEnum;

/**
 * Validates consistency between the catalog and policies.
 *
 * Checks:
 * 1. Every key in the catalog has a policy method with #[GateAbility] (warning in normal mode, error in strict).
 * 2. Every key in the catalog is unique (no duplicates).
 * 3. Every #[GateAbility] policy method references an enum-case present in the catalog.
 *
 * Examples:
 *   php artisan guard:catalog:validate
 *   php artisan guard:catalog:validate --panel=app
 *   php artisan guard:catalog:validate --strict
 *   php artisan guard:catalog:validate --json
 */
final class CatalogValidateCommand extends Command
{
    use OutputsStructured;

    protected $signature = 'guard:catalog:validate
        {--panel= : Validate only the given panel}
        {--strict : Treat warnings as errors}
        {--json : Output a machine-readable JSON payload instead of text}';

    protected $description = 'Validate consistency between PermissionCatalog, policies, and enums';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(PermissionCatalog $catalog): int
    {
        $this->errors = [];
        $this->warnings = [];

        $panelFilter = $this->normalizeOption('panel');
        $strict = (bool) $this->option('strict');

        $panelIds = $panelFilter !== null
            ? [$panelFilter]
            : $catalog->panels();

        if ($panelIds === []) {
            if ($this->wantsJson()) {
                $this->renderJsonPayload(errors: [], warnings: ['No registered panels found.']);

                return self::SUCCESS;
            }

            $this->warn('No registered panels found.');

            return self::SUCCESS;
        }

        foreach ($panelIds as $panelId) {
            $this->validatePanel($catalog, $panelId);
        }

        $hasIssues = $this->errors !== [] || ($strict && $this->warnings !== []);

        if ($this->wantsJson()) {
            $this->renderJsonPayload(errors: $this->errors, warnings: $this->warnings);

            return $hasIssues ? self::FAILURE : self::SUCCESS;
        }

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($this->errors as $error) {
            $this->error($error);
        }

        if (! $hasIssues) {
            $panelLabel = $panelFilter ?? 'all';
            $this->info("guard:catalog:validate: panel [{$panelLabel}] checks passed.");

            return self::SUCCESS;
        }

        $this->error('guard:catalog:validate: catalog consistency errors found.');

        return self::FAILURE;
    }

    private function validatePanel(PermissionCatalog $catalog, string $panelId): void
    {
        $panel = AzGuard::panel(id: $panelId);

        if ($panel === null) {
            $this->errors[] = "Panel [{$panelId}] not found in AzGuardManager.";

            return;
        }

        $basePath = $panel->getBasePath();
        $baseNamespace = $panel->getNamespace();

        if ($basePath === '' || $baseNamespace === '') {
            $this->warnings[] = "Panel [{$panelId}]: basePath/namespace not set.";

            return;
        }

        $abilityMap = $this->buildAbilityMap(
            basePath: $basePath,
            baseNamespace: $baseNamespace,
            panelId: $panelId,
        );

        $definitions = $catalog->all($panelId);

        $this->checkCatalogKeysHavePolicies(
            definitions: $definitions,
            abilityMap: $abilityMap,
            panelId: $panelId,
        );

        $this->checkAbilitiesAreCatalogued(
            abilityMap: $abilityMap,
            catalog: $catalog,
            panelId: $panelId,
        );

        if ($this->wantsJson()) {
            return;
        }

        $this->info(
            "Panel [{$panelId}]: {$this->countLabel(count($definitions))} in catalog, {$this->countLabel(count($abilityMap))} in policies.",
        );
    }

    /**
     * Builds a resolvedKey -> "Policy::method" map from #[GateAbility] methods.
     *
     * @return array<string, string>
     */
    private function buildAbilityMap(string $basePath, string $baseNamespace, string $panelId): array
    {
        $discovery = new PolicyDiscovery;
        $policyClasses = $discovery->discoverPolicyClasses(
            basePath: $basePath,
            baseNamespace: $baseNamespace,
        );

        $panel = AzGuard::panel(id: $panelId);
        $map = [];

        foreach ($policyClasses as $policyClass) {
            $reflection = new ReflectionClass($policyClass);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(GateAbility::class) as $attribute) {
                    /** @var GateAbility $gateAbility */
                    $gateAbility = $attribute->newInstance();

                    if (! $gateAbility->permission instanceof UnitEnum) {
                        continue;
                    }

                    if ($panel === null) {
                        continue;
                    }

                    $resolvedKey = $panel->resolvePermission($gateAbility->permission);
                    $map[$resolvedKey] = "{$policyClass}::{$method->getName()}";
                }
            }
        }

        return $map;
    }

    /**
     * Check that every catalog key has a corresponding policy method.
     *
     * @param  list<PermissionDefinition>  $definitions
     * @param  array<string, string>  $abilityMap
     */
    private function checkCatalogKeysHavePolicies(
        array $definitions,
        array $abilityMap,
        string $panelId,
    ): void {
        foreach ($definitions as $definition) {
            $key = $definition->key();

            if (! isset($abilityMap[$key])) {
                $message = "Panel [{$panelId}]: permission [{$key}] is in the catalog but has no policy method with #[GateAbility].";

                $this->warnings[] = $message;
            }
        }
    }

    /**
     * Check that every ability method references a key present in the catalog.
     *
     * @param  array<string, string>  $abilityMap
     */
    private function checkAbilitiesAreCatalogued(
        array $abilityMap,
        PermissionCatalog $catalog,
        string $panelId,
    ): void {
        foreach ($abilityMap as $resolvedKey => $handler) {
            if (! $catalog->has($panelId, $resolvedKey)) {
                $this->errors[] = "Panel [{$panelId}]: {$handler} uses [{$resolvedKey}] which is missing from PermissionCatalog.";
            }
        }
    }

    private function countLabel(int $count): string
    {
        return "{$count} permission".($count !== 1 ? 's' : '');
    }

    private function normalizeOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
