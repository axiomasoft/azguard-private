<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

/**
 * Builds the source of a Laravel policy for one model-backed
 * {@see PermissionSubject}. Each Filament policy method checks the matching
 * AzGuard permission, so Filament's native authorization enforces the schema
 * (the "policy" source) — and `Gate::authorize()` works outside Filament too.
 */
final readonly class PolicyGenerator
{
    /** Policy methods that receive the record instance (vs. collection-level). */
    private const array RECORD_LEVEL = ['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'];

    public function className(PermissionSubject $subject): string
    {
        $base = $subject->model === null ? $subject->name : class_basename($subject->model);

        return $base.'Policy';
    }

    /**
     * @param  class-string  $userModel
     */
    public function source(
        PermissionSubject $subject,
        string $panelId,
        PermissionSchema $schema,
        string $namespace,
        string $userModel,
    ): string {
        $model = $subject->model ?? $userModel;
        $modelShort = class_basename($model);
        $userShort = class_basename($userModel);
        $class = $this->className($subject);

        $methods = [];

        foreach (FilamentActions::methodsFor($subject->abilities) as $method => $slug) {
            $key = $schema->key($panelId, $subject->name, $slug);

            $signature = in_array($method, self::RECORD_LEVEL, true)
                ? "{$userShort} \$user, {$modelShort} \$record"
                : "{$userShort} \$user";

            $methods[] = <<<METHOD
                    public function {$method}({$signature}): bool
                    {
                        return (bool) \$user->hasPermission('{$key}', '{$panelId}');
                    }
                METHOD;
        }

        $body = implode("\n\n", $methods);

        $imports = array_unique(array_filter([
            $userModel,
            $subject->model,
        ]));
        sort($imports);
        $useLines = implode("\n", array_map(static fn (string $fqcn): string => "use {$fqcn};", $imports));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            {$useLines}

            class {$class}
            {
            {$body}
            }

            PHP;
    }
}
