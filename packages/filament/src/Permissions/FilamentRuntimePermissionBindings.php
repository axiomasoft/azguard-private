<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use AzGuard\Registry\Support\RuntimePermissionBindings;
use Override;

/**
 * Diagnostic evidence from live Filament registration and its enforcement hooks.
 *
 * @internal
 */
final readonly class FilamentRuntimePermissionBindings implements RuntimePermissionBindings
{
    public function __construct(
        private string $panelId,
        private PermissionSchema $schema,
        private PermissionDiscovery $discovery,
        private FilamentDiscovery $liveDiscovery,
    ) {}

    #[Override]
    public function keys(string $panelId): array
    {
        if ($panelId !== $this->panelId
            || ! config('az-guard-filament.enforce', true)
            || config('az-guard-filament.source', 'database') === 'policy') {
            return [];
        }

        // Match ResourceGate's model map, including last-subject-wins behavior
        // when an application supplies its own PermissionDiscovery.
        $resources = [];

        foreach ($this->discovery->subjects($panelId) as $subject) {
            if ($subject->model !== null) {
                $resources[$subject->model] = $subject->name;
            }
        }

        $keys = [];

        foreach ($this->liveDiscovery->authorizationSubjects($panelId) as $subject) {
            if ($subject->model !== null && ($resources[$subject->model] ?? null) !== $subject->name) {
                continue;
            }

            array_push($keys, ...$this->schema->keys($panelId, $subject));
        }

        return array_values(array_unique($keys));
    }
}
