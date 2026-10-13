<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity\Panels\NoScope;

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Crm\CrmPanelProvider;

/**
 * Panel `crm` of the populated 0.7.0 dataset rebuilt without a scope declaration: the provider of the dataset, then
 * an explicit `AssignmentScopeMode::None` declaration (see `NoScopeWorld`). Its own folder, so discovery adds nothing.
 */
final class NoScopeCrmPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'crm';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return (new CrmPanelProvider($this->app))->panel($panel)->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, []));
    }
}
