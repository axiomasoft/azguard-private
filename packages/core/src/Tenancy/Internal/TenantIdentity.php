<?php

declare(strict_types=1);

namespace AzGuard\Tenancy\Internal;

use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant a model is inside one panel. One reading of the panel definition for the subject wrapper, the panel
 * access and the facade, so none of them keeps a second rule.
 */
final class TenantIdentity
{
    /** @throws TenantMismatchException when the model is not the tenant model of the panel */
    public static function tenant(Panel $panel, Model $tenant): TenantRef
    {
        $definition = $panel->tenants()->definition();
        $class = $definition?->model();

        if ($definition === null || $class === null || ! $tenant instanceof $class) {
            throw new TenantMismatchException($tenant::class.' is not the tenant model of panel '.$panel->id().'.');
        }

        return TenantRef::of($definition->type(), ModelKey::of($tenant));
    }
}
