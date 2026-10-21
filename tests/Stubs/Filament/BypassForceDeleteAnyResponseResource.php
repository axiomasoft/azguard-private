<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use Illuminate\Auth\Access\Response;

final class BypassForceDeleteAnyResponseResource extends CatalogProjectResource
{
    public static function getForceDeleteAnyAuthorizationResponse(): Response
    {
        return Response::allow();
    }
}
