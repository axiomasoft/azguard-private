<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use InvalidArgumentException;

/**
 * A plugin whose dependencies are named typed parameters of its own factory.
 */
final class CrmAccessPlugin extends BasePlugin
{
    /**
     * @param  class-string<ResourceScopeResolver>  $clientScope
     */
    private function __construct(
        private readonly CrmModels $models,
        private readonly string $clientScope,
    ) {}

    /**
     * @param  class-string<ResourceScopeResolver>  $clientScope
     */
    public static function make(CrmModels $models, string $clientScope): self
    {
        if (! is_a($clientScope, ResourceScopeResolver::class, true)) {
            throw new InvalidArgumentException('clientScope SPI');
        }

        return new self($models, $clientScope);
    }

    public function id(): string
    {
        return 'acme/crm-access';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->for(model: $this->models->subject, guard: 'web')
            ->resourceScopes([$this->models->client => $this->clientScope]);
    }
}
