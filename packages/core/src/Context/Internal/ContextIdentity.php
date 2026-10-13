<?php

declare(strict_types=1);

namespace AzGuard\Context\Internal;

use AzGuard\Context\AssignmentScopeDescription;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\DescribesAssignmentScope;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Support\ModelKey;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * The assignment scope context a model is inside one panel, and the evaluator the panel declares. One reading of the
 * panel declaration for the subject wrapper, the facade, the stages and the compilers, so none of them keeps a second
 * rule.
 */
final class ContextIdentity
{
    /**
     * The facts core reads about a definition: its own description, or the defaults for one that gives none (no
     * components, label or directory, a tenant owner, a custom `resolve()`).
     */
    public static function describe(AssignmentScopeDefinition $definition): AssignmentScopeDescription
    {
        return $definition instanceof DescribesAssignmentScope ? $definition->description() : new AssignmentScopeDescription;
    }

    /**
     * The evaluator the scope declaration of the panel names, resolved from the container for one operation; null
     * when the panel declares none (eligibility is then structural only).
     *
     * @throws DefinitionException when the class does not resolve to an evaluator
     */
    public static function evaluator(Panel $panel, Container $container): ?AssignmentScopeEvaluator
    {
        $class = $panel->scopeDeclaration()?->evaluator;

        if ($class === null) {
            return null;
        }
        $evaluator = $container->make($class);

        if (! $evaluator instanceof AssignmentScopeEvaluator) {
            throw new DefinitionException('Assignment scope evaluator '.$class.' of panel '.$panel->id().' did not resolve to '.AssignmentScopeEvaluator::class.'.');
        }

        return $evaluator;
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

        return $types === [] ? null : AssignmentScopeRef::of($types[0], ModelKey::of($model));
    }
}
