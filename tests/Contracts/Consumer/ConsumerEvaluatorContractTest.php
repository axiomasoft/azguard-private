<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Consumer;

use Acme\Consumer\ConsumerEvaluator;
use Acme\Consumer\ConsumerScope;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Testing\Contracts\AssignmentScopeEvaluatorContractTests;
use AzGuard\Tests\TestCase;

require_once dirname(__DIR__, 2).'/Fixtures/Modules/Consumer/autoload.php';

/** I8: the scope evaluator of a package outside `AzGuard\` passes the evaluator suite. */
final class ConsumerEvaluatorContractTest extends TestCase
{
    use AssignmentScopeEvaluatorContractTests;

    protected function azguardEvaluator(): AssignmentScopeEvaluator
    {
        return new ConsumerEvaluator;
    }

    protected function azguardScopeDefinition(): AssignmentScopeDefinition
    {
        return new ConsumerScope;
    }

    protected function azguardScopes(): array
    {
        return [AssignmentScopeRef::of('store', 1), AssignmentScopeRef::of('store', 2)];
    }
}
