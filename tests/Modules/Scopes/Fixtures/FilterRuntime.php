<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\Internal\Query\EligibilityBuilder;
use AzGuard\Support\ModelKey;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use DateTimeImmutable;

/** The runtime of the first record of a definition, for the filter contract suite. */
final class FilterRuntime
{
    public static function of(QueryableAssignmentScopeDefinition $definition): AssignmentScopeRuntime
    {
        $record = EligibilityBuilder::structural($definition)->firstOrFail();
        $subject = ContractWorld::subject(1);
        $key = SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject));

        return new AssignmentScopeRuntime(
            panel: ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder),
            scope: AccessScope::in($definition->tenantOf($record), AssignmentScopeRef::of($definition->type(), ModelKey::of($record))),
            subject: $key, user: $subject, role: null, grant: null, actor: ActorRef::of($key->type(), $key->id()), actorModel: $subject,
            now: new DateTimeImmutable('now'), phase: AssignmentScopePhase::Access,
        );
    }
}
