<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use Illuminate\Auth\Access\Response;

final class BypassDeleteAnyResponseResource extends CatalogProjectResource
{
    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::allow();
    }
}
