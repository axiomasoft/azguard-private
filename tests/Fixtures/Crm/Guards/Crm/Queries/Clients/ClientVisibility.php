<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Queries\Clients;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class ClientVisibility
{
    public static function panel(?Closure $configure = null, ?array $sources = null): Panel
    {
        // Exact mode uses the model's explicit mapping; scalar parity checks both routes.
        return CrmWorld::compile($configure, $sources, exact: true);
    }

    /** @return Builder<Client> */
    public static function query(Panel $panel, ClientPermission $action = ClientPermission::View, ?int $user = 1, int $tenant = 1, ?Builder $host = null): Builder
    {
        return app(Authorizer::class)->visibleTo($panel, $host ?? Client::query(),
            $user === null ? null : SubjectRef::of('crm.user', $user), $action, CrmWorld::scope($tenant));
    }
}
