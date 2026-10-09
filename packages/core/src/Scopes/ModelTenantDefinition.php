<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * A tenant model identified by its registered morph alias.
 *
 * @api
 */
final readonly class ModelTenantDefinition
{
    private string $alias;

    /** @param class-string<Model> $model */
    public function __construct(private string $model, ?string $type = null)
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new DefinitionException('Tenant definition requires an Eloquent model class.');
        }

        $this->alias = $type ?? (new $model)->getMorphClass();

        try {
            IdentityCodec::assertTypeAlias($this->alias);
        } catch (Throwable $error) {
            throw new DefinitionException('Tenant model '.$model.' needs a registered morph alias or an explicit stable type.', previous: $error);
        }
    }

    /** @param class-string<Model> $model */
    public static function make(string $model, ?string $type = null): self
    {
        return new self(model: $model, type: $type);
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

    public function resolve(TenantRef $ref): ?Model
    {
        if ($ref->isGlobal() || $ref->type() !== $this->type()) {
            return null;
        }

        return ModelKey::find($this->model::query(), $ref->id());
    }
}
