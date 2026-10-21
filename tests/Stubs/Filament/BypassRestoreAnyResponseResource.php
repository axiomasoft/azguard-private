<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use Illuminate\Auth\Access\Response;

final class BypassRestoreAnyResponseResource extends CatalogProjectResource
{
    public static function getRestoreAnyAuthorizationResponse(): Response
    {
        return Response::allow();
    }
}
