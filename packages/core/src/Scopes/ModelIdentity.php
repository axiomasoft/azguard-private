<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal What a model is inside one panel: its tenant or its assignment scope context. One reading of the panel
 * definition for the subject wrapper, the panel access and the facade, so none of them keeps a second rule.
 */
final class ModelIdentity
{
    /** @throws TenantMismatchException when the model is not the tenant model of the panel */
    public static function tenant(Panel $panel, Model $tenant): TenantRef
    {
        $definition = $panel->tenants()->definition();
        $class = $definition?->model();

        if ($definition === null || $class === null || ! $tenant instanceof $class) {
            throw new TenantMismatchException($tenant::class.' is not the tenant model of panel '.$panel->id().'.');
        }

        return TenantRef::of($definition->type(), self::key($tenant));
    }

    /**
     * The context a model is when its class is the model of an assignment scope type of the panel.
     *
     * @throws AssignmentScopeNotAcceptedException when the model fits several types
     */
    public static function context(Panel $panel, Model $model): ?AssignmentScopeRef
    {
        $types = [];
        foreach ($panel->scopeDefinitions() as $definition) {
            $class = $definition->model();

            if ($class !== null && $model instanceof $class) {
                $types[] = $definition->type();
            }
        }

        if (count($types) > 1) {
            throw new AssignmentScopeNotAcceptedException($model::class.' is the model of several assignment scope types of panel '
                .$panel->id().': pass an AssignmentScopeRef.');
        }

        return $types === [] ? null : AssignmentScopeRef::of($types[0], self::key($model));
    }

    /** @throws InvalidIdentityException when the model has no key yet */
    public static function key(Model $model): int|string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidIdentityException($model::class.' has no key: save the model first.');
        }

        return $key;
    }
}
