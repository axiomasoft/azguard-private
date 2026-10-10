<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Panels\Panel;
use Fiber;
use WeakMap;

/**
 * Request/job-local scope state, isolated by panel.
 *
 * @api
 */
final class CurrentContext
{
    /** @var array<string, AccessScope> */
    private array $scopes = [];

    /** @var WeakMap<object, array<string, AccessScope>> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap;
    }

    public function get(Panel $panel): ?AccessScope
    {
        $fiber = Fiber::getCurrent();

        return ($fiber === null ? $this->scopes : ($this->fibers[$fiber] ?? []))[$panel->id()] ?? null;
    }

    public function set(Panel $panel, ?AccessScope $scope): void
    {
        $fiber = Fiber::getCurrent();
        $scopes = $fiber === null ? $this->scopes : ($this->fibers[$fiber] ?? []);

        if ($scope === null) {
            unset($scopes[$panel->id()]);
        } else {
            $scopes[$panel->id()] = $scope;
        }

        if ($fiber === null) {
            $this->scopes = $scopes;
        } elseif ($scopes === []) {
            unset($this->fibers[$fiber]);
        } else {
            $this->fibers[$fiber] = $scopes;
        }
    }
}
