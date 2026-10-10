<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;

/**
 * The Blog module's own panel: an ordinary panel provider, registered by the module through the facade.
 */
final class BlogGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'blog';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->resourcePrefix(false)->permissions([BlogPermission::class]);
    }
}
