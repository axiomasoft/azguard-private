<?php

declare(strict_types=1);

namespace AzGuard\Context\Internal;

use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Model;

/**
 * The assignment scope context a model is inside one panel. One reading of the panel definition for the subject
 * wrapper and the facade, so none of them keeps a second rule.
 */
final class ContextIdentity
{
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
