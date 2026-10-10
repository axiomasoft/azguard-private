<?php

declare(strict_types=1);

namespace AzGuard\Sources\Relation;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\IdentityCodec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/** Normalized related-root configuration; never resolves a subject's loaded relations. */
final readonly class RelationBinding
{
    /** @param class-string<Model> $model */
    private function __construct(
        public QueryableAssignmentScopeDefinition $definition,
        public string $model,
        public string $via,
        public string $id,
        public ?string $pivotAccessor,
    ) {}

    public static function make(AssignmentScopeDefinition $definition, string $via, bool $pivotRole): self
    {
        $model = $definition->model();

        if (! $definition instanceof QueryableAssignmentScopeDefinition || $model === null) {
            throw new DefinitionException('RelationSource needs a QueryableAssignmentScopeDefinition with a non-null Eloquent model; use a queryable descriptor for '.$definition::class.'.');
        }

        if (! is_subclass_of($model, Model::class) || ! (new ReflectionClass($model))->isInstantiable()) {
            throw new DefinitionException('RelationSource model '.$model.' must be a concrete Eloquent model.');
        }

        $id = self::identity($definition->type());

        try {
            if ($definition->query()->getModel()::class !== $model) {
                throw new DefinitionException('RelationSource descriptor '.$definition::class.' must query its declared model '.$model.'.');
            }

            if (! method_exists($model, $via)) {
                throw new DefinitionException('RelationSource via "'.$via.'" must name an Eloquent relation on the related root '.$model.'.');
            }

            $method = new ReflectionMethod($model, $via);

            if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 0) {
                throw new DefinitionException('RelationSource '.$model.'::'.$via.'() must be a public instance relation without required arguments.');
            }

            $relation = (new $model)->{$via}();
        } catch (DefinitionException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DefinitionException('RelationSource cannot build '.$model.'::'.$via.'(); declare a valid related-root relation.', previous: $error);
        }

        // MorphToMany extends BelongsToMany. MorphOne/MorphMany require their own explicit contract.
        if ((! $relation instanceof BelongsToMany && ! $relation instanceof BelongsTo && ! $relation instanceof HasMany && ! $relation instanceof HasOne)
            || $relation instanceof MorphTo) {
            throw new DefinitionException('RelationSource '.$model.'::'.$via.'() must return BelongsToMany, MorphToMany, HasMany, BelongsTo or HasOne; got '.get_debug_type($relation).'.');
        }

        if ($pivotRole && ! $relation instanceof BelongsToMany) {
            throw new DefinitionException('RelationSource role "pivot.role" requires BelongsToMany or MorphToMany on '.$model.'::'.$via.'(); use a static role or a related-model closure.');
        }

        return new self($definition, $model, $via, $id, $relation instanceof BelongsToMany ? $relation->getPivotAccessor() : null);
    }

    public static function identity(string $type): string
    {
        $id = 'relation:'.$type;

        try {
            IdentityCodec::assertTypeAlias($type);
            IdentityCodec::assertSourceLabel($id);
        } catch (InvalidIdentityException $error) {
            throw new DefinitionException('RelationSource type "'.$type.'" produces an invalid source id "'.$id.'"; use a valid assignment-scope alias of at most 119 bytes so relation:<type> fits the 128-byte source label.', previous: $error);
        }

        return $id;
    }

    /** @return Relation<Model, Model, mixed> */
    public function relation(): Relation
    {
        $model = new $this->model;
        $relation = $model->{$this->via}();

        if (! $relation instanceof Relation) {
            throw new DefinitionException('RelationSource related-root relation no longer returns an Eloquent relation.');
        }

        return $relation;
    }
}
