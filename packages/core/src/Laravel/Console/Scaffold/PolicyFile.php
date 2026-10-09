<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal The policy of a permission enum: one method with `#[Decides]` for each case. The method name is free; the
 * attribute is what binds the method to the case.
 */
final class PolicyFile
{
    /**
     * @param  class-string  $enum
     * @param  list<string>  $cases  the names of the cases
     * @param  bool  $explicit  bind the policy to the enum with #[PolicyFor] instead of by its group
     * @param  bool  $allow  whether a method passes (the permission is still granted by a grant) or denies until it is
     *                       written: a policy-only permission has no grant behind it, so it starts closed
     */
    public static function render(StubStore $stubs, Place $place, string $class, string $enum, array $cases, bool $explicit, bool $allow = true): string
    {
        $short = substr($enum, (int) strrpos($enum, '\\') + 1);
        $methods = array_map(static fn (string $case): string => rtrim($stubs->render('policy-method', [
            'enum' => $short,
            'case' => $case,
            'method' => lcfirst($case),
            'comment' => $allow ? 'The permission is granted; return false to veto it for this user or resource.'
                : 'Not decided yet: the permission is denied until this method returns true for who may have it.',
            'result' => $allow ? 'true' : 'false',
        ]), "\n"), $cases);

        return $stubs->render('policy', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports([$enum, Decides::class, Model::class, ...($explicit ? [PolicyFor::class] : [])]),
            'attributes' => $explicit ? '#[PolicyFor('.$short.'::class)]'."\n" : '',
            'class' => $class,
            'methods' => implode("\n\n", $methods),
        ]);
    }
}
