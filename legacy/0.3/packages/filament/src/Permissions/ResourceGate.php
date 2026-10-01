<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

/**
 * Zero-boilerplate Filament resource authorization.
 *
 * Registered as a Gate::before() hook. When Filament authorizes a resource
 * action (viewAny, view, create, update, delete, …) against a discovered
 * model, this maps it to the `{panel}.{resource}.{ability}` permission and
 * answers from the user's AzGuard permissions — so resources need no
 * authorization code of their own. Returns null (defers) for anything it does
 * not manage.
 */
final class ResourceGate
{
    /** @var array<string, array<class-string, string>> */
    private array $resourcesByPanel = [];

    public function __construct(
        private readonly string $panelId,
        private readonly PermissionSchema $schema,
        private readonly PermissionDiscovery $discovery,
    ) {}

    /**
     * @param  array<int, mixed>  $arguments  Gate arguments — $arguments[0] is the model.
     */
    public function check(object $user, string $ability, array $arguments): ?bool
    {
        return $this->checkForPanel($user, $ability, $arguments, $this->panelId, $this->schema);
    }

    /** @param array<int, mixed> $arguments */
    public function checkForPanel(
        object $user,
        string $ability,
        array $arguments,
        string $panelId,
        PermissionSchema $schema,
    ): ?bool {
        $slug = FilamentActions::MAP[$ability] ?? null;

        if ($slug === null) {
            return null;
        }

        $model = $arguments[0] ?? null;

        $modelClass = match (true) {
            is_object($model) => $model::class,
            is_string($model) => $model,
            default => null,
        };

        $resource = $modelClass === null ? null : ($this->map($panelId)[$modelClass] ?? null);

        if ($resource === null || ! method_exists($user, 'hasPermission')) {
            return null;
        }

        // Union-only (§6): never return false from a Gate::before hook — that
        // would short-circuit the ENTIRE gate and deny even abilities a later
        // policy/before callback would otherwise grant. Absence of a grant here
        // defers (null), it does not assert a denial.
        return $user->hasPermission(
            $schema->key($panelId, $resource, $slug),
            $panelId,
        ) ? true : null;
    }

    /**
     * @return array<class-string, string>
     */
    private function map(string $panelId): array
    {
        if (isset($this->resourcesByPanel[$panelId])) {
            return $this->resourcesByPanel[$panelId];
        }

        $map = [];

        foreach ($this->discovery->subjects($panelId) as $subject) {
            if ($subject->model !== null) {
                $map[$subject->model] = $subject->name;
            }
        }

        return $this->resourcesByPanel[$panelId] = $map;
    }
}
