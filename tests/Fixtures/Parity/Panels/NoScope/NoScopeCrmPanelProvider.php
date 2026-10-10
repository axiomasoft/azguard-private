<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity\Panels\NoScope;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Panels\Crm\CrmPanelProvider;

/**
 * Panel `crm` of the populated 0.7.0 dataset rebuilt without a scope declaration: the provider of the dataset, then
 * `AssignmentScopePolicy::none()` (see `NoScopeWorld`). Its own folder, so discovery adds nothing.
 */
final class NoScopeCrmPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'crm';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return (new CrmPanelProvider($this->app))->panel($panel)->scopes(AssignmentScopePolicy::none());
    }
}
