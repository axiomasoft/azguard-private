<?php

declare(strict_types=1);

namespace AzGuard\Guard;

use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\RoleOnly;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\PermissionKey;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Support\Panel;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use UnitEnum;

/**
 * Diagnostic tool that inspects all registered panels and reports:
 * - duplicate Gate abilities
 * - enum permission cases with no matching #[GateAbility] policy method
 * - #[GateAbility] attributes pointing to enums outside the panel Permissions/ dir
 * - roles referencing unknown or unprefixed permissions
 * - policy classes with no #[GateAbility] methods (orphans)
 */
class AzGuardDiagnostics
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Run all checks across all (or a single) registered panel(s).
     *
     * @return array{errors: list<string>, warnings: list<string>, abilities: list<array{panel: string, ability: string, handler: string}>}
     */
    public function diagnose(?string $panelFilter = null): array
    {
        $this->errors = [];
        $this->warnings = [];
        $abilityRows = [];

        foreach (AzGuard::getPanels() as $panelId => $panel) {
            if ($panelFilter !== null && $panelFilter !== $panelId) {
                continue;
            }

            $basePath = $panel->getBasePath();
            $baseNamespace = $panel->getNamespace();

            if ($basePath === '' || $baseNamespace === '') {
                $this->warnings[] = "Panel [{$panelId}]: basePath or namespace is not configured on the provider.";

                continue;
            }

            $discovery = new PolicyDiscovery;
            $policyClasses = $discovery->discoverPolicyClasses(
                basePath: $basePath,
                baseNamespace: $baseNamespace,
            );

            $registeredAbilities = $this->collectRegisteredAbilities(
                policyClasses: $policyClasses,
                panel: $panel,
            );

            foreach ($registeredAbilities as $ability => $handler) {
                $abilityRows[] = [
                    'panel' => $panelId,
                    'ability' => $ability,
                    'handler' => $handler,
                ];
            }

            // Discover permission enums once per panel — shared by three checks below.
            $enumClasses = $this->discoverPermissionEnums(basePath: $basePath, baseNamespace: $baseNamespace);

            // Enum↔policy pairing только для панелей на policy-модели. Панель без
            // policy-классов enforce-ит иначе (Gate/ResourceGate, напр. Filament),
            // и требовать #[GateAbility]-метод на каждый enum-кейс там некорректно.
            if ($policyClasses !== []) {
                $this->checkEnumsAgainstPolicies(
                    enumClasses: $enumClasses,
                    panel: $panel,
                    registeredAbilities: $registeredAbilities,
                );
            }
            $this->checkGateAbilityEnumReferences(
                policyClasses: $policyClasses,
                enumClasses: $enumClasses,
            );
            $this->checkRoles(
                basePath: $basePath,
                baseNamespace: $baseNamespace,
                panel: $panel,
                knownAbilities: array_merge(
                    array_keys($registeredAbilities),
                    $this->collectRoleOnlyAbilities(
                        enumClasses: $enumClasses,
                        panel: $panel,
                    ),
                    // Полный каталог пермишенов панели — источник истины по известным
                    // ability. Включает ключи, объявленные НЕ через policy/#[RoleOnly]:
                    // Filament-дискавери ресурсов, обычные enum-кейсы и т.п. Без этого
                    // роли Filament-панели ложно репортились как «unknown permission».
                    $this->collectCatalogAbilities(panelId: $panelId),
                ),
            );
            $this->checkOrphanPolicies(policyClasses: $policyClasses, panelId: $panelId);
        }

        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'abilities' => $abilityRows,
        ];
    }

    /**
     * @param  list<class-string>  $policyClasses
     * @return array<string, string>
     */
    private function collectRegisteredAbilities(array $policyClasses, Panel $panel): array
    {
        $abilities = [];

        foreach ($policyClasses as $policyClass) {
            $reflection = new ReflectionClass($policyClass);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(GateAbility::class) as $attribute) {
                    /** @var GateAbility $gateAbility */
                    $gateAbility = $attribute->newInstance();
                    $ability = $panel->resolvePermission(permission: $gateAbility->permission);
                    $handler = "{$policyClass}::{$method->getName()}";

                    if (isset($abilities[$ability])) {
                        $this->errors[] = "Panel [{$panel->getId()}]: duplicate ability [{$ability}] — {$abilities[$ability]} and {$handler}.";
                    }

                    $abilities[$ability] = $handler;
                }
            }
        }

        return $abilities;
    }

    /**
     * @param  list<class-string>  $enumClasses  Pre-computed by diagnose().
     * @param  array<string, string>  $registeredAbilities
     */
    private function checkEnumsAgainstPolicies(
        array $enumClasses,
        Panel $panel,
        array $registeredAbilities,
    ): void {
        foreach ($enumClasses as $enumClass) {
            foreach ((new ReflectionEnum($enumClass))->getCases() as $case) {
                if ($case->getAttributes(RoleOnly::class) !== []) {
                    continue;
                }

                /** @var UnitEnum $enumCase */
                $enumCase = $case->getValue();
                $resolved = $panel->resolvePermission(permission: $enumCase);

                if (! isset($registeredAbilities[$resolved])) {
                    $this->errors[] = "Enum {$enumClass}::{$case->getName()} → [{$resolved}] has no policy method annotated with #[GateAbility].";
                }
            }
        }
    }

    /**
     * @param  list<class-string>  $policyClasses
     * @param  list<class-string>  $enumClasses  Pre-computed by diagnose().
     */
    private function checkGateAbilityEnumReferences(
        array $policyClasses,
        array $enumClasses,
    ): void {
        // Build a fast lookup set from the pre-computed enum list.
        $enumIndex = array_fill_keys($enumClasses, true);

        foreach ($policyClasses as $policyClass) {
            $reflection = new ReflectionClass($policyClass);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(GateAbility::class) as $attribute) {
                    /** @var GateAbility $gateAbility */
                    $gateAbility = $attribute->newInstance();
                    $permission = $gateAbility->permission;

                    if (! $permission instanceof UnitEnum) {
                        continue;
                    }

                    $enumClass = $permission::class;

                    if (! isset($enumIndex[$enumClass])) {
                        $this->errors[] = "{$policyClass}::{$method->getName()}: permission enum {$enumClass} was not found in the panel's Permissions/ directory.";
                    }
                }
            }
        }
    }

    /** @param list<string> $knownAbilities */
    private function checkRoles(
        string $basePath,
        string $baseNamespace,
        Panel $panel,
        array $knownAbilities,
    ): void {
        $rolesPath = $basePath.'/Roles';

        if (! is_dir($rolesPath)) {
            return;
        }

        // Use a fast lookup set for known abilities.
        $abilitiesIndex = array_fill_keys($knownAbilities, true);

        foreach (File::files($rolesPath) as $file) {
            if (! str_ends_with(haystack: $file->getFilename(), needle: 'Role.php')) {
                continue;
            }

            $class = $baseNamespace.'\\Roles\\'.str_replace('.php', '', $file->getFilename());

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, RoleInterface::class)) {
                continue;
            }

            /** @var RoleInterface $role */
            $role = app()->make($class);

            foreach ($role->permissions() as $permission) {
                if ($permission === PermissionKey::WILDCARD) {
                    continue;
                }

                // Enum-кейсы — канонная (refactor-safe) форма объявления пермишенов
                // роли. Их нельзя кастовать в строку/использовать как ключ массива;
                // скоупим через панель, как это делает ClassRoleGrantSource. Enum
                // чужой панели (не в permissionEnums) — не ability этой панели, скип.
                if ($permission instanceof UnitEnum) {
                    if (! in_array($permission::class, $panel->getPermissionEnums(), strict: true)) {
                        continue;
                    }

                    $key = $panel->resolvePermission($permission);

                    if (! isset($abilitiesIndex[$key])) {
                        $this->errors[] = "Role {$class}: references unknown permission [{$key}].";
                    }

                    continue;
                }

                if (! str_starts_with(haystack: $permission, needle: $panel->getId().PermissionKey::SEPARATOR)) {
                    $this->warnings[] = "Role {$class}: permission [{$permission}] is missing the panel prefix [{$panel->getId()}.].";

                    continue;
                }

                if (! isset($abilitiesIndex[$permission])) {
                    $this->errors[] = "Role {$class}: references unknown permission [{$permission}].";
                }
            }
        }
    }

    /** @param list<class-string> $policyClasses */
    private function checkOrphanPolicies(array $policyClasses, string $panelId): void
    {
        foreach ($policyClasses as $policyClass) {
            $reflection = new ReflectionClass($policyClass);
            $hasAbility = false;

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(GateAbility::class) !== []) {
                    $hasAbility = true;

                    break;
                }
            }

            if (! $hasAbility) {
                $this->warnings[] = "Panel [{$panelId}]: policy {$policyClass} has no public methods annotated with #[GateAbility].";
            }
        }
    }

    /**
     * @param  list<class-string>  $enumClasses  Pre-computed by diagnose().
     * @return list<string>
     */
    private function collectRoleOnlyAbilities(array $enumClasses, Panel $panel): array
    {
        $abilities = [];

        foreach ($enumClasses as $enumClass) {
            foreach ((new ReflectionEnum($enumClass))->getCases() as $case) {
                if ($case->getAttributes(RoleOnly::class) === []) {
                    continue;
                }

                /** @var UnitEnum $enumCase */
                $enumCase = $case->getValue();
                $abilities[] = $panel->resolvePermission(permission: $enumCase);
            }
        }

        return $abilities;
    }

    /**
     * Ключи всего каталога пермишенов панели (авторитетный набор известных
     * ability): enum-каталог, Filament-дискавери ресурсов, policy-ability и т.п.
     *
     * @return list<string>
     */
    private function collectCatalogAbilities(string $panelId): array
    {
        return array_map(
            static fn (PermissionDefinition $definition): string => $definition->key(),
            app(PermissionCatalog::class)->all($panelId),
        );
    }

    /** @return list<class-string> */
    private function discoverPermissionEnums(string $basePath, string $baseNamespace): array
    {
        if (! is_dir($basePath)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles(directory: $basePath) as $file) {
            if (! str_ends_with(haystack: $file->getFilename(), needle: 'Permission.php')) {
                continue;
            }

            $relativePath = $file->getRelativePathname();
            $class = $baseNamespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (class_exists($class) && (new ReflectionClass($class))->isEnum()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
