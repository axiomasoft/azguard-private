<?php

declare(strict_types=1);

use AzGuard\Panels\Panel;
use AzGuard\Permissions\InteractsWithPanel;

enum CoverageBackedPanelPermission: string
{
    use InteractsWithPanel;

    case View = 'docs.view';
}

final class CoverageNonEnumPanelPermission
{
    use InteractsWithPanel;
}

it('scopes a backed enum through the panel', function (): void {
    $panel = Panel::make()->id('app')->scopedByPanelId(true);

    expect(CoverageBackedPanelPermission::View->resolve($panel))->toBe('app.docs.view');
});

it('returns an empty string when the host is not a backed enum', function (): void {
    $panel = Panel::make()->id('app')->scopedByPanelId(true);

    expect((new CoverageNonEnumPanelPermission)->resolve($panel))->toBe('');
});
