<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Contracts\PermissionCatalog;

// F40: the catalog resolves panelIds lazily, so a panel registered after boot is
// visible via panels() without rebuilding/replacing the catalog singleton.
it('sees a panel registered after boot via lazy panelIds', function () {
    $catalog = app(PermissionCatalog::class);

    expect($catalog->panels())->not->toContain('late');

    AzGuard::registerPanel(Panel::make()->id('late'));

    expect($catalog->panels())->toContain('late');
});
