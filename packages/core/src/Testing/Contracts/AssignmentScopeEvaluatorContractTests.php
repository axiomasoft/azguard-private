<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelResolver;
use AzGuard\Support\ModelKey;
use AzGuard\Testing\FakeSource;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * For the authors of assignment scope evaluators: what every evaluator guarantees, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application and implement `azguardEvaluator()`,
 * `azguardScopeDefinition()` and `azguardScopes()`. The suite declares the definition on the panel `contract` (no
 * tenants, so the scopes belong to the global tenant) with the evaluator.
 *
 * - `allowsMany()` gives one answer per check, in the order of the checks, equal to `allows()` for each;
 * - an answer only narrows: a scope the evaluator accepts grants nothing without a grant;
 * - the engine asks the evaluator only about scopes it verified structurally (declared type, resolved scope);
 * - it does not fail on ordinary requests: an exception is a denial with the reason `assignment_scope_filter_error`,
 *   never a pass, and the suite reports it.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
trait AssignmentScopeEvaluatorContractTests
{
    /** A new instance of the evaluator under test. */
    abstract protected function azguardEvaluator(): AssignmentScopeEvaluator;

    /** The scope type the suite declares; `resolve()` finds the scopes of `azguardScopes()` in the global tenant. */
    abstract protected function azguardScopeDefinition(): AssignmentScopeDefinition;

    /**
     * Scopes of that type that exist.
     *
     * @return non-empty-list<AssignmentScopeRef>
     */
    abstract protected function azguardScopes(): array;

    /** The subject the suite asks about. */
    protected function azguardSubject(): Model
    {
        return ContractWorld::subject(1);
    }

    #[Test]
    public function evaluatorAnswersManyChecksLikeOneInTheirOrder(): void
    {
        $evaluator = $this->azguardEvaluator();
        $checks = [];

        foreach ($this->azguardScopes() as $ref) {
            $checks[] = [$this->azguardRuntime($this->azguardScopePanel($evaluator), $ref), $this->azguardResolved($ref)];
        }
        $single = array_map(static fn (array $check): bool => $evaluator->allows($check[0], $check[1]), $checks);
        $many = $evaluator->allowsMany($checks);

        $this->assertSame(array_keys($checks), array_keys($many), 'allowsMany() does not give one answer per check in a list.');
        $this->assertSame($single, $many, 'allowsMany() answers differently than allows() for the same checks.');
        $this->assertSame(array_reverse($single), $evaluator->allowsMany(array_reverse($checks)), 'allowsMany() does not follow the order of the checks.');
        $this->assertSame([], $evaluator->allowsMany([]), 'allowsMany() answers checks nobody asked.');
    }

    #[Test]
    public function evaluatorOnlyNarrows(): void
    {
        $panel = $this->azguardScopePanel($this->azguardEvaluator(), new FakeSource);

        foreach ($this->azguardScopes() as $ref) {
            $this->assertFalse($this->azguardDecide($panel, $ref)->allowed(), 'A scope the evaluator accepts granted '.$ref->key().' without a grant.');
        }
    }

    #[Test]
    public function engineAsksTheEvaluatorOnlyAboutVerifiedScopes(): void
    {
        $evaluator = $this->azguardEvaluator();
        $spy = new class($evaluator) implements AssignmentScopeEvaluator
        {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private readonly AssignmentScopeEvaluator $evaluator) {}

            public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void
            {
                $this->evaluator->validate($definition, $registered);
            }

            public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
            {
                $this->asked[] = $resolved->ref->key();

                return $this->evaluator->allows($runtime, $resolved);
            }

            public function allowsMany(array $checks): array
            {
                foreach ($checks as [, $resolved]) {
                    $this->asked[] = $resolved->ref->key();
                }

                return $this->evaluator->allowsMany($checks);
            }
        };
        $source = new FakeSource;
        $panel = $this->azguardScopePanel($spy, $source);
        $type = $this->azguardScopeDefinition()->type();

        $this->azguardDecide($panel, AssignmentScopeRef::of($type === 'azguard-contract.other' ? 'azguard-contract.undeclared' : 'azguard-contract.other', 1));
        $this->assertSame([], $spy->asked, 'The engine asked the evaluator about a scope type the panel does not declare.');

        foreach ($this->azguardScopes() as $ref) {
            $this->grant($panel, $source, $ref);
            $this->azguardDecide($panel, $ref);
        }

        foreach ($spy->asked as $key) {
            $this->assertContains($key, array_map(static fn (AssignmentScopeRef $ref): string => $ref->key(), $this->azguardScopes()),
                'The engine asked the evaluator about a scope it did not resolve: '.$key.'.');
        }
    }

    #[Test]
    public function evaluatorDoesNotFailOnOrdinaryRequests(): void
    {
        $source = new FakeSource;
        $panel = $this->azguardScopePanel($this->azguardEvaluator(), $source);

        foreach ($this->azguardScopes() as $ref) {
            $this->grant($panel, $source, $ref);
            $decision = $this->azguardDecide($panel, $ref);

            $this->assertNotSame(DecisionReason::AssignmentScopeFilterError, $decision->reason, 'The evaluator threw for '.$ref->key().': the engine denied the request instead of answering it.');
        }
    }

    /** The panel `contract` declaring the scope type with the evaluator, bound in the container under its class. */
    protected function azguardScopePanel(AssignmentScopeEvaluator $evaluator, ?FakeSource $source = null): Panel
    {
        app()->instance($evaluator::class, $evaluator);
        $definition = $this->azguardScopeDefinition();

        return ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions($source === null ? [] : [$source])
            ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [$definition], evaluator: $evaluator::class)));
    }

    protected function azguardRuntime(Panel $panel, AssignmentScopeRef $ref): AssignmentScopeRuntime
    {
        $subject = $this->azguardSubject();
        $key = SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject));

        return new AssignmentScopeRuntime(panel: $panel, scope: AccessScope::in(TenantRef::global(), $ref), subject: $key, user: $subject,
            role: null, grant: null, actor: ActorRef::of($key->type(), $key->id()), actorModel: $subject, now: new DateTimeImmutable('now'),
            phase: AssignmentScopePhase::Access);
    }

    protected function azguardResolved(AssignmentScopeRef $ref): ResolvedAssignmentScope
    {
        return $this->azguardScopeDefinition()->resolve($ref)
            ?? throw new RuntimeException('azguardScopes() lists '.$ref->key().', which the definition does not resolve.');
    }

    private function azguardDecide(Panel $panel, AssignmentScopeRef $ref): Decision
    {
        return AzGuard::panel($panel->id())->for($this->azguardSubject())->decide(ContractPermission::View, on: $ref);
    }

    private function grant(Panel $panel, FakeSource $source, AssignmentScopeRef $ref): void
    {
        $subject = $this->azguardSubject();
        $source->grantPermission(SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject)), app(PanelResolver::class)->pattern($panel, ContractPermission::View), $ref);
    }
}
