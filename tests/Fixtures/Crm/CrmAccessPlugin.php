<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Builder;

final class CrmAccessPlugin implements Plugin
{
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
        // The whole definition of the alias: contributions of one alias are identical or conflict, so the stand
        // attaches no ScopesPlugin of its own (CrmWorld::$scopes = false).
        $panel->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ProjectScope::make()->filter(new ActiveProjects)->filter(
            static function (Builder $query) use ($city): void {
                $query->where('city_id', $city);
            },
        )], evaluator: ScopeEvaluator::class));
    }
}
