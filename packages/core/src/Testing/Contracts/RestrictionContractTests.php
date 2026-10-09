<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelResolver;
use AzGuard\Scopes\ModelIdentity;
use AzGuard\Testing\FakeSource;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use UnitEnum;

/**
 * For the authors of restrictions: what every restriction guarantees, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application and implement `azguardRestriction()`. The suite grants
 * the subject every permission it decides, so a denial that comes back is the restriction's.
 *
 * - the key is stable;
 * - it answers the same request the same way;
 * - it writes nothing while it decides;
 * - an exception inside it is a denial with the reason `restriction_error`, never a pass — and the suite finds the
 *   exception, because a restriction that fails on an ordinary request is a defect;
 * - a denial names the restriction.
 *
 * @api
 */
trait RestrictionContractTests
{
    /** A new instance of the restriction under test. */
    abstract protected function azguardRestriction(): Restriction;

    /** The subject the suite asks about. */
    protected function azguardSubject(): Model
    {
        return ContractWorld::subject(1);
    }

    /**
     * The permissions of the panel `contract` the suite decides.
     *
     * @return list<string|UnitEnum>
     */
    protected function azguardPermissions(): array
    {
        return [ContractPermission::View, ContractPermission::Edit];
    }

    #[Test]
    public function restrictionHasAStableKey(): void
    {
        $restriction = $this->azguardRestriction();

        $this->assertNotSame('', $restriction->key(), 'A restriction has a key.');
        $this->assertSame($restriction->key(), $restriction->key(), 'The key changes between calls.');
        $this->assertSame($restriction->key(), $this->azguardRestriction()->key(), 'Two instances of the restriction have different keys.');
    }

    #[Test]
    public function restrictionAnswersTheSameRequestTheSameWay(): void
    {
        $source = new FakeSource;
        $panel = ContractWorld::panel(fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source])->restrictions([$this->azguardRestriction()]));
        $subject = $this->azguardSubject();
        $ref = SubjectRef::of($subject->getMorphClass(), ModelIdentity::key($subject));

        foreach ($this->azguardPermissions() as $permission) {
            $source->grantPermission($ref, app(PanelResolver::class)->pattern($panel, $permission));
            $first = ContractWorld::decide($panel, $subject, $permission);
            $second = ContractWorld::decide($panel, $subject, $permission);

            $this->assertSame($first->effect, $second->effect, 'The restriction decides the same request differently.');
            $this->assertSame($first->reason, $second->reason, 'The restriction gives different reasons for the same request.');
        }
    }

    #[Test]
    public function restrictionDoesNotFailOnOrdinaryRequestsAndNamesItsDenial(): void
    {
        $restriction = $this->azguardRestriction();
        $source = new FakeSource;
        $panel = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source]));
        $subject = $this->azguardSubject();
        $source->grantPermission(SubjectRef::of($subject->getMorphClass(), ModelIdentity::key($subject)), app(PanelResolver::class)->pattern($panel, ContractPermission::View));
        $source->grantPermission(SubjectRef::of($subject->getMorphClass(), ModelIdentity::key($subject)), app(PanelResolver::class)->pattern($panel, ContractPermission::Edit));
        $restricted = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source])->restrictions([$restriction]));

        foreach ($this->azguardPermissions() as $permission) {
            $decision = ContractWorld::decide($restricted, $subject, $permission);

            $this->assertNotSame(DecisionReason::RestrictionError, $decision->reason, 'The restriction threw: the engine denied the request instead of passing it.');

            if ($decision->reason === DecisionReason::Restricted) {
                $this->assertSame($restriction->key(), $decision->component, 'A denial by the restriction does not name its key.');
            }
        }
    }

    #[Test]
    public function restrictionWritesNothingWhileItDecides(): void
    {
        $source = new FakeSource;
        $panel = ContractWorld::panel(fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source])->restrictions([$this->azguardRestriction()]));
        $subject = $this->azguardSubject();

        foreach ($this->azguardPermissions() as $permission) {
            $source->grantPermission(SubjectRef::of($subject->getMorphClass(), ModelIdentity::key($subject)), app(PanelResolver::class)->pattern($panel, $permission));
        }

        $writes = ContractWorld::writesDuring(function () use ($panel, $subject): void {
            foreach ($this->azguardPermissions() as $permission) {
                ContractWorld::decide($panel, $subject, $permission);
            }
        });

        $this->assertSame([], $writes, 'The restriction wrote while it decided: '.implode('; ', $writes));
    }
}
