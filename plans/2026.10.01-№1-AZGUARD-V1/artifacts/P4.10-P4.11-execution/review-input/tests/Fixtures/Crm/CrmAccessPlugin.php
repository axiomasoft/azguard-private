<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Builder;

final class CrmAccessPlugin extends BasePlugin
{
    public static array $boots = [];

    private function __construct(private readonly int $city) {}

    public static function make(int $city): self
    {
        return new self($city);
    }

    public function id(): string
    {
        return 'acme/crm-city';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $city = $this->city;
        $panel->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
            static function (Builder $query) use ($city): void {
                $query->where('city_id', $city);
            },
        )));
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        self::$boots[] = $panel->id();
    }
}
