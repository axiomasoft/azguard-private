<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Packman\Attributes\Api;
use Packman\Filament\Concerns\Authorization\AuthorizesRelationManager as PackmanAuthorizesRelationManager;
use ReflectionProperty;

/**
 * Decides a relation manager by the permissions of the resource of its records: the related resource, or the resource
 * class named by `protected static ?string $azguardResource` on the relation manager. That resource uses
 * `AuthorizesResource`; the table of the relation manager keeps the records of it that the user may view. Without such
 * a resource a Filament panel that enforces refuses the relation manager.
 *
 * @phpstan-require-extends RelationManager
 */
#[Api(since: '1.0')]
trait AuthorizesRelationManager
{
    use PackmanAuthorizesRelationManager;

    /** @return class-string<FilamentResource>|null */
    public static function getRelatedResource(): ?string
    {
        $resource = parent::getRelatedResource();

        // Expose the existing AzGuard declaration to Packman's shared surface hooks and query filter.
        if ($resource === null && property_exists(static::class, 'azguardResource')) {
            $resource = new ReflectionProperty(static::class, 'azguardResource')->getValue();
        }

        return is_string($resource) && is_subclass_of($resource, FilamentResource::class) ? $resource : null;
    }
}
