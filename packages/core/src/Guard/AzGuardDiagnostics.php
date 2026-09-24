<?php

declare(strict_types=1);

namespace AzGuard\Guard;

use AzGuard\Attributes\CheckPermission as CheckPermissionAttribute;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;
use AzGuard\Attributes\RoleOnly;
use AzGuard\Attributes\SkipGuardCheck;
use AzGuard\Configuration\Config;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Exceptions\InvalidModelConfigException;
use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\CheckAccess;
use AzGuard\Panels\Panel;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
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
     * @return array{errors: list<string>, warnings: list<string>, abilities: list<array{panel: string, ability: string, handler: string}>, models: list<array{key: string, class: string, table: string, connection: string}>}
     */
    public function diagnose(?string $panelFilter = null): array
    {
        $this->errors = [];
        $this->warnings = [];
        $abilityRows = [];

        // Panel-independent (model_has_scopes has no meaningful "per panel"
        // grouping for this check) — run once regardless of $panelFilter.
        $modelErrors = Config::authorizationModelConfigErrors();
        array_push($this->errors, ...$modelErrors);

        if ($modelErrors === []) {
            $this->checkStaleScopeClasses();
            $this->checkRoleClassIdentity();
        }
        $this->checkPermissionAttributes();

        $panels = AzGuard::getPanels();

        // A-06: 0 registered panels is a valid (headless/embedded) setup, not
        // an error — surface it as an onboarding hint rather than staying
        // silent, so a fresh install is distinguishable from a broken one.
        if ($panels === []) {
            $this->warnings[] = 'No panels registered — see docs/introduction/headless-quick-start.md for a minimal setup.';
        }

        foreach ($panels as $panelId => $panel) {
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
            $this->checkGeneratedScaffold(policyClasses: $policyClasses, enumClasses: $enumClasses, panel: $panel);

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
            'models' => $this->authorizationModelDetails(),
        ];
    }

    /**
     * Report each valid configured model even when another binding is invalid.
     *
     * @return list<array{key: string, class: string, table: string, connection: string}>
     */
    private function authorizationModelDetails(): array
    {
        $bindings = [
            'models.role' => Config::roleModel(...),
            'models.scope' => Config::scopeModel(...),
            'models.direct_grant' => Config::directGrantModel(...),
            'models.role_permission' => Config::rolePermissionModel(...),
        ];
        $details = [];

        foreach ($bindings as $key => $resolve) {
            try {
                $class = $resolve();
            } catch (InvalidModelConfigException) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;
            $details[] = [
                'key' => $key,
                'class' => $class,
                'table' => $model->getTable(),
                'connection' => (string) ($model->getConnectionName() ?? config('database.default')),
            ];
        }

        return $details;
    }

    /**
     * Generated files have a narrow marker so custom policy and enum layouts
     * are not judged by scaffold-specific assumptions.
     *
     * @param  list<class-string>  $policyClasses
     * @param  list<class-string>  $enumClasses
     */
    private function checkGeneratedScaffold(array $policyClasses, array $enumClasses, Panel $panel): void
    {
        foreach ($policyClasses as $policyClass) {
            $reflection = new ReflectionClass($policyClass);
            $filename = $reflection->getFileName();

            if (! is_string($filename)) {
                continue;
            }

            if (! str_contains(File::get(path: $filename), 'azguard:generated-policy')) {
                continue;
            }

            $attributes = $reflection->getAttributes(GuardPolicy::class);

            if ($attributes === []) {
                $this->errors[] = "Generated policy {$policyClass}: missing #[GuardPolicy] model.";

                continue;
            }

            /** @var GuardPolicy $policy */
            $policy = $attributes[0]->newInstance();

            if (! class_exists($policy->model) || ! is_subclass_of($policy->model, Model::class)) {
                $this->errors[] = "Generated policy {$policyClass}: invalid Eloquent model [{$policy->model}].";
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(GateAbility::class) === []) {
                    continue;
                }

                $parameters = $method->getParameters();
                $type = isset($parameters[0]) ? $parameters[0]->getType() : null;
                $actor = $type instanceof ReflectionNamedType ? $type->getName() : '';

                if ($actor === '' || ! class_exists($actor) || ! is_subclass_of($actor, Authenticatable::class)) {
                    $this->errors[] = "Generated policy {$policyClass}::{$method->getName()}: invalid Authenticatable actor [{$actor}].";
                }
            }
        }

        foreach ($enumClasses as $enumClass) {
            $reflection = new ReflectionClass($enumClass);
            $filename = $reflection->getFileName();

            if (! is_string($filename)) {
                continue;
            }

            if (! str_contains(File::get(path: $filename), 'azguard:generated-permission')) {
                continue;
            }

            if (! in_array($enumClass, $panel->getPermissionEnums(), true)) {
                $this->errors[] = "Panel [{$panel->getId()}]: generated permission enum {$enumClass} is missing from provider permissionEnums([...]).";
            }
        }
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

    /**
     * Routes that carry CheckAccess must declare #[CheckPermission] or
     * #[SkipGuardCheck]. Closures and unresolvable actions are skipped.
     * Missing metadata is a warning in legacy mode and an error when
     * `require_permission_attributes` is on.
     */
    private function checkPermissionAttributes(): void
    {
        try {
            $routes = Route::getRoutes()->getRoutes();
        } catch (Throwable) {
            return;
        }

        $strict = Config::requirePermissionAttributes();

        foreach ($routes as $route) {
            if (! $this->routeUsesCheckAccess($route)) {
                continue;
            }

            $method = $this->controllerMethodForRoute($route);

            if (! $method instanceof ReflectionMethod) {
                continue;
            }

            if ($method->getAttributes(SkipGuardCheck::class) !== []) {
                continue;
            }

            if ($method->getAttributes(CheckPermissionAttribute::class) !== []) {
                continue;
            }

            $label = $this->routeAttributeLabel($route, $method);
            $message = "{$label} has azguard.check without #[CheckPermission] or #[SkipGuardCheck].";

            if ($strict) {
                $this->errors[] = $message;
            } else {
                $this->warnings[] = $message;
            }
        }
    }

    private function routeUsesCheckAccess(LaravelRoute $route): bool
    {
        $needles = [
            'azguard.check',
            Config::checkAccessAlias(),
            CheckAccess::class,
        ];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $name = explode(':', $middleware, 2)[0];

            if (in_array($name, $needles, true)) {
                return true;
            }
        }

        return false;
    }

    private function controllerMethodForRoute(LaravelRoute $route): ?ReflectionMethod
    {
        $actionName = $route->getActionName();

        if ($actionName === 'Closure' || str_contains($actionName, '{closure}')) {
            return null;
        }

        if (str_contains($actionName, '@')) {
            [$controllerClass, $methodName] = explode('@', $actionName, 2);
        } elseif (class_exists($actionName)) {
            $controllerClass = $actionName;
            $methodName = '__invoke';
        } else {
            return null;
        }

        if (! class_exists($controllerClass) || ! method_exists($controllerClass, $methodName)) {
            return null;
        }

        return new ReflectionMethod($controllerClass, $methodName);
    }

    private function routeAttributeLabel(LaravelRoute $route, ReflectionMethod $method): string
    {
        $verb = $route->methods()[0] ?? 'ANY';
        $uri = '/'.$route->uri();

        return "Route [{$verb} {$uri}] ({$method->class}::{$method->name})";
    }

    /**
     * C-03 — surface stale scope_class values (the class was renamed/removed
     * after being persisted in model_has_scopes) as a loud warning, rather
     * than the silent per-request Log::warning in bootHasScopedRoles() being
     * the only way to ever notice.
     */
    private function checkRoleClassIdentity(): void
    {
        $model = Config::roleModel();
        $rows = $model::query()
            ->whereNotNull('class_name')
            ->get(['id', 'name', 'class_name']);
        $byClass = [];

        foreach ($rows as $row) {
            if (! is_string($row->class_name) || $row->class_name === '') {
                $this->errors[] = "roles: invalid class_name [{$row->class_name}] on role [{$row->name}] — expected a non-empty RoleInterface class.";

                continue;
            }

            if (! class_exists($row->class_name)) {
                $this->errors[] = "roles: invalid class_name [{$row->class_name}] on role [{$row->name}] — class does not exist.";

                continue;
            }

            if (! is_subclass_of($row->class_name, RoleInterface::class)) {
                $this->errors[] = "roles: invalid class_name [{$row->class_name}] on role [{$row->name}] — class does not implement RoleInterface.";

                continue;
            }

            $byClass[$row->class_name][] = $row->id;
        }

        foreach ($byClass as $class => $ids) {
            if (count($ids) > 1) {
                $this->errors[] = 'roles: duplicate class_name ['.$class.'] on rows '.implode(', ', $ids).'.';
            }
        }
    }

    private function checkStaleScopeClasses(): void
    {
        $model = Config::scopeModel();

        $classes = $model::query()
            ->whereNotNull('scope_class')
            ->distinct()
            ->pluck('scope_class');

        foreach ($classes as $class) {
            if (! class_exists($class)) {
                $this->warnings[] = "model_has_scopes: stale scope_class [{$class}] — class does not exist.";
            }
        }
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
