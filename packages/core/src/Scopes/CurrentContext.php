<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Panels\Panel;

/**
 * Request/job-local scope state, isolated by panel.
 *
 * @api
 */
final class CurrentContext
{
    /** @var array<string, AccessScope> */
    private array $scopes = [];

    public function get(Panel $panel): ?AccessScope
    {
        return $this->scopes[$panel->id()] ?? null;
    }

    public function set(Panel $panel, ?AccessScope $scope): void
    {
        if ($scope === null) {
            unset($this->scopes[$panel->id()]);

            return;
        }

        $this->scopes[$panel->id()] = $scope;
    }
}
