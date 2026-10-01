<?php

declare(strict_types=1);

namespace AzGuard\Support;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Exceptions\InvalidRoleClassException;
use AzGuard\Exceptions\InvalidRoleIdentityException;
use AzGuard\Models\Role;
use AzGuard\Panels\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Preflight classification for guard:sync-roles. Dry-run and write share this plan.
 *
 * @internal
 */
final class RoleSyncPlanner
{
    /**
     * @return array{
     *     decisions: list<array{panel: string, name: string, previous: ?string, level: int, class: string, status: string}>,
     *     errors: list<string>
     * }
     */
    public function plan(AzGuardManagerInterface $manager, ?string $panelFilter): array
    {
        $errors = [];
        $definitions = $this->collectDefinitions($manager, $panelFilter, $errors);

        /** @var class-string<Role> $roleModel */
        $roleModel = Config::roleModel();
        $existing = $roleModel::query()->get();

        $byClass = $this->indexByClass($existing);
        $byName = $this->indexByName($existing);

        $decisions = [];

        foreach ($definitions as $definition) {
            $className = $definition['class'];
            $canonical = $definition['name'];
            $level = $definition['level'];
            $panelId = $definition['panel'];
            $rows = $byClass[$className] ?? [];

            if (count($rows) > 1) {
                $ids = implode(', ', array_map(static fn (Model $row): string => (string) $row->getKey(), $rows));
                $errors[] = "Duplicate class_name [{$className}] on role rows [{$ids}]. Resolve manually before sync.";
                $decisions[] = $this->decision($panelId, $canonical, null, $level, $className, 'collision');

                continue;
            }

            if ($rows === []) {
                $holder = $byName[$canonical] ?? null;

                if ($holder !== null) {
                    $holderClass = $holder->class_name ?? 'null';
                    $errors[] = "Canonical name [{$canonical}] is held by row id={$holder->getKey()} class_name={$holderClass}. Will not adopt a DB-only or foreign row.";
                    $decisions[] = $this->decision($panelId, $canonical, $holder->name, $level, $className, 'collision');

                    continue;
                }

                $decisions[] = $this->decision($panelId, $canonical, null, $level, $className, 'created');

                continue;
            }

            $row = $rows[0];
            $previous = (string) $row->name;

            if ($previous === $canonical && (int) $row->level === $level) {
                $decisions[] = $this->decision($panelId, $canonical, $previous, $level, $className, 'unchanged');

                continue;
            }

            if ($previous !== $canonical) {
                $holder = $byName[$canonical] ?? null;

                if ($holder !== null && (int) $holder->getKey() !== (int) $row->getKey()) {
                    $errors[] = "Cannot rename [{$previous}] → [{$canonical}] for {$className}: name is held by row id={$holder->getKey()}.";
                    $decisions[] = $this->decision($panelId, $canonical, $previous, $level, $className, 'collision');

                    continue;
                }
            }

            $decisions[] = $this->decision(
                $panelId,
                $canonical,
                $previous,
                $level,
                $className,
                $previous === $canonical ? 'updated' : 'renamed',
            );
        }

        return ['decisions' => $decisions, 'errors' => $errors];
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{panel: string, class: string, name: string, level: int}>
     */
    private function collectDefinitions(AzGuardManagerInterface $manager, ?string $panelFilter, array &$errors): array
    {
        $definitions = [];
        $seen = [];

        foreach ($manager->getPanels() as $panelId => $panel) {
            if (is_string($panelFilter) && $panelFilter !== '' && $panelFilter !== $panelId) {
                continue;
            }

            foreach ($this->classNamesFor($panel, (string) $panelId, $errors) as $className) {
                if (RoleIdentity::isBuiltInSuperAdmin($className)) {
                    if (isset($seen[$className])) {
                        continue;
                    }
                } elseif (isset($seen[$className])) {
                    $errors[] = "Duplicate code-role definition [{$className}] on panels [{$seen[$className]}] and [{$panelId}].";

                    continue;
                }

                try {
                    $logic = RoleIdentity::logicOrFail($className);
                } catch (InvalidRoleClassException $exception) {
                    $errors[] = $exception->getMessage();

                    continue;
                }

                try {
                    $name = RoleIdentity::persistedName((string) $panelId, $logic->getName(), $className);
                } catch (InvalidRoleIdentityException $exception) {
                    $errors[] = $exception->getMessage();

                    continue;
                }

                $seen[$className] = (string) $panelId;
                $definitions[] = [
                    'panel' => RoleIdentity::isBuiltInSuperAdmin($className) ? '*' : (string) $panelId,
                    'class' => $className,
                    'name' => $name,
                    'level' => $logic->getLevel(),
                ];
            }
        }

        return $definitions;
    }

    /**
     * @param  list<string>  $errors
     * @return list<class-string>
     */
    private function classNamesFor(Panel $panel, string $panelId, array &$errors): array
    {
        $explicit = $panel->getRoleClasses();

        if ($explicit !== []) {
            return $explicit;
        }

        $basePath = $panel->getBasePath();
        $namespace = $panel->getNamespace();

        if ($basePath === '' || $namespace === '') {
            return [];
        }

        $rolesPath = rtrim($basePath, '/').'/Roles';

        if (! is_dir($rolesPath)) {
            return [];
        }

        $classNames = [];

        foreach (glob($rolesPath.'/*Role.php') ?: [] as $file) {
            $className = $namespace.'\\Roles\\'.basename($file, '.php');

            if (! class_exists($className)) {
                $errors[] = "Role class [{$className}] discovered for panel [{$panelId}] does not exist.";

                continue;
            }

            if (! is_subclass_of($className, RoleInterface::class)) {
                $errors[] = InvalidRoleClassException::notARole($className)->getMessage();

                continue;
            }

            $classNames[] = $className;
        }

        return $classNames;
    }

    /**
     * @param  Collection<int, Role>  $existing
     * @return array<string, list<Role>>
     */
    private function indexByClass(Collection $existing): array
    {
        $byClass = [];

        foreach ($existing as $row) {
            if (! is_string($row->class_name)) {
                continue;
            }

            if ($row->class_name === '') {
                continue;
            }
            $byClass[$row->class_name][] = $row;
        }

        return $byClass;
    }

    /**
     * @param  Collection<int, Role>  $existing
     * @return array<string, Role>
     */
    private function indexByName(Collection $existing): array
    {
        $byName = [];

        foreach ($existing as $row) {
            $byName[(string) $row->name] = $row;
        }

        return $byName;
    }

    /**
     * @return array{panel: string, name: string, previous: ?string, level: int, class: string, status: string}
     */
    private function decision(
        string $panelId,
        string $name,
        ?string $previous,
        int $level,
        string $className,
        string $status,
    ): array {
        return [
            'panel' => $panelId,
            'name' => $name,
            'previous' => $previous,
            'level' => $level,
            'class' => $className,
            'status' => $status,
        ];
    }
}
