<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\TenantRef;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Eloquent shorthand. A tenant panel must supply an explicit authoritative owner callback.
 *
 * @api
 */
final class ModelAssignmentScopeDefinition extends BaseAssignmentScope
{
    private readonly string $alias;

    /** @param class-string<Model> $model
     * @param  Closure(Model): TenantRef|null  $owner
     */
    public function __construct(private readonly string $model, private readonly ?Closure $owner = null, ?string $type = null)
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new DefinitionException('Assignment scope definition requires an Eloquent model class.');
        }
        $this->alias = $type ?? (new $model)->getMorphClass();

        try {
            IdentityCodec::assertTypeAlias($this->alias);
        } catch (Throwable $error) {
            throw new DefinitionException('Assignment scope model '.$model.' needs a registered morph alias or an explicit stable type.', previous: $error);
        }
    }

    /** @param class-string<Model> $model
     * @param  Closure(Model): TenantRef|null  $owner
     */
    public static function make(string $model, ?Closure $owner = null, ?string $type = null): self
    {
        return new self(model: $model, owner: $owner, type: $type);
    }

    public function type(): string
    {
        return $this->alias;
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return $this->model;
    }

    public function query(): Builder
    {
        return $this->model::query();
    }

    public function hasOwner(): bool
    {
        return $this->owner !== null;
    }

    public function tenantOf(Model $record): TenantRef
    {
        if (! $record instanceof $this->model) {
            throw new DefinitionException('Scope owner callback received a model of another definition.');
        }

        return $this->owner === null ? TenantRef::global() : ($this->owner)($record);
    }
}
