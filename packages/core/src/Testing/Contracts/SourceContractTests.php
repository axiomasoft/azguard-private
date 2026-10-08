<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Changes\Change;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Sources\PanelSources;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use UnitEnum;

/**
 * For the authors of sources: what every source guarantees, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application and implement `azguardSource()`. Each test builds its
 * own panels, so the source is made again for each of them; a source that keeps state between instances is a defect
 * these tests find.
 *
 * - the source has a valid, stable id and a panel accepts it and describes it;
 * - it answers only for its own panel: no decision of a panel comes back as `source_error`;
 * - the same version of the state gives the same answer;
 * - it takes `now` from the context, not from its own clock;
 * - `describe()` lists the capabilities the source implements and what it returns has the type of its capability;
 * - a writer refuses to store anything outside the transaction the change pipeline opens.
 *
 * @api
 */
trait SourceContractTests
{
    /** A new instance of the source under test, ready to be attached to a panel. */
    abstract protected function azguardSource(): Source;

    /**
     * Attaches the source to a panel of the suite. Override to add what the source needs of the panel: roles, scopes,
     * subject models.
     */
    protected function azguardAttach(PanelBuilder $panel, Source $source): void
    {
        $panel->permissions([$source]);
    }

    /** The subject the suite asks about. */
    protected function azguardSubject(): Model
    {
        return ContractWorld::subject(1);
    }

    /**
     * The permissions of the panel `contract` the suite decides; add the ones the source gives.
     *
     * @return list<string|UnitEnum>
     */
    protected function azguardPermissions(): array
    {
        return [ContractPermission::View, ContractPermission::Edit];
    }

    #[Test]
    public function sourceHasAValidStableId(): void
    {
        $source = $this->azguardSource();
        $id = $source->id();

        $this->assertNotSame('', $id, 'A source has an id.');
        $this->assertTrue($this->azguardSucceeds(static fn () => IdentityCodec::assertSourceLabel($id)), 'The id "'.$id.'" is not a valid source label.');
        $this->assertSame($id, $source->id(), 'The id changes between calls.');
        $this->assertSame($id, $this->azguardSource()->id(), 'Two instances of the source have different ids.');
    }

    #[Test]
    public function panelAcceptsAndDescribesTheSource(): void
    {
        $source = $this->azguardSource();
        $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $this->azguardAttach($builder, $source));
        $ids = array_map(static fn ($description): string => $description->id, $panel->sources());

        $this->assertContains($source->id(), $ids, 'The panel does not list the source among its sources.');
    }

    #[Test]
    public function sourceAnswersOnlyForItsOwnPanel(): void
    {
        $registry = ContractWorld::build(
            fn (PanelBuilder $builder) => $this->azguardAttach($builder, $this->azguardSource()),
            fn (PanelBuilder $builder) => $this->azguardAttach($builder, $this->azguardSource()),
        );
        $subject = $this->azguardSubject();

        foreach ($this->azguardPermissions() as $permission) {
            $reason = ContractWorld::decide($registry->get(ContractWorld::PANEL), $subject, $permission)->reason;
            $this->assertNotSame(DecisionReason::SourceError, $reason, 'The source broke the decision of panel contract.');
        }
        $reason = ContractWorld::decide($registry->get(ContractWorld::OTHER), $subject, OtherPermission::Read)->reason;
        $this->assertNotSame(DecisionReason::SourceError, $reason, 'The source broke the decision of panel other: it answers for a panel it was not attached to.');
    }

    #[Test]
    public function sameVersionGivesTheSameAnswer(): void
    {
        $source = $this->azguardSource();
        $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $this->azguardAttach($builder, $source));
        $subject = $this->azguardSubject();

        foreach ($this->azguardPermissions() as $permission) {
            $first = ContractWorld::decide($panel, $subject, $permission);
            $second = ContractWorld::decide($panel, $subject, $permission);

            $this->assertSame($first->effect, $second->effect, 'The effect changed on the same version of the state.');
            $this->assertSame($first->reason, $second->reason, 'The reason changed on the same version of the state.');
            $this->assertEquals($first->state, $second->state, 'The state token changed without a change.');
        }
    }

    #[Test]
    public function sourceTakesNowFromTheContext(): void
    {
        $source = $this->azguardSource();

        if (! $source instanceof ProvidesGrants && ! $source instanceof ProvidesRoleGrants) {
            $this->assertNotInstanceOf(ProvidesGrants::class, $source, 'A source of grants reads the time from the context.');

            return;
        }
        $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $this->azguardAttach($builder, $source));
        $source = $this->azguardAttached($panel, $source);
        $ref = SubjectRef::of($this->azguardSubject()->getMorphClass(), $this->azguardSubject()->getKey());
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $contexts = [AccessScope::in(TenantRef::global()), AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('store', '1'))];

        try {
            Carbon::setTestNow('2026-01-01T00:00:00Z');
            $early = $this->azguardRead($source, $ref, $panel, $contexts, $now);
            Carbon::setTestNow('2031-06-01T00:00:00Z');
            $late = $this->azguardRead($source, $ref, $panel, $contexts, $now);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($early, $late, 'The source answers differently when only the clock moves: it reads its own time instead of $context->now().');
    }

    #[Test]
    public function describeMatchesWhatTheSourceGives(): void
    {
        $source = $this->azguardSource();
        $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $this->azguardAttach($builder, $source));
        $source = $this->azguardAttached($panel, $source);
        $capabilities = PanelSources::capabilities($source);

        foreach ($panel->sources() as $description) {
            if ($description->id === $source->id()) {
                $this->assertEqualsCanonicalizing($capabilities, $description->capabilities, 'The panel lists capabilities the source does not have, or leaves out ones it has.');
            }
        }

        if ($source instanceof DescribesSchema) {
            $description = $source->describe($panel);

            $this->assertSame($source->id(), $description->id, 'describe() names another id than the source.');
            $this->assertSame($source::class, $description->class, 'describe() names another class than the source.');
            $this->assertEqualsCanonicalizing($capabilities, $description->capabilities, 'describe() lists capabilities the source does not have, or leaves out ones it has.');
        }
        $ref = SubjectRef::of($this->azguardSubject()->getMorphClass(), $this->azguardSubject()->getKey());
        $context = new ContractContext($panel, AccessScope::in(TenantRef::global()), new DateTimeImmutable('2026-01-01T00:00:00Z'));

        if ($source instanceof ProvidesGrants) {
            foreach ($source->grants($ref, [$context->scope()], $context) as $grant) {
                $this->assertInstanceOf(Grant::class, $grant, 'grants() returns something that is not a Grant.');
                $this->assertSame($panel->id(), $grant->pattern->panel(), 'grants() gives a permission of another panel.');
                $this->assertNotSame('*', $grant->pattern->local(), 'grants() gives the bare wildcard.');
            }
        }

        if ($source instanceof ProvidesRoleGrants) {
            foreach ($source->roleGrants($ref, [$context->scope()], $context) as $contribution) {
                $this->assertInstanceOf(RoleContribution::class, $contribution, 'roleGrants() returns something that is not a RoleContribution.');
                $this->assertSame($panel->id(), $contribution->role->panel(), 'roleGrants() gives a role of another panel.');
            }
        }

        if ($source instanceof ProvidesPermissions) {
            foreach ($source->permissions($panel) as $definition) {
                $this->assertInstanceOf(PermissionDefinition::class, $definition, 'permissions() returns something that is not a PermissionDefinition.');
            }
        }

        if ($source instanceof ProvidesRoles) {
            foreach ($source->roles($panel) as $role) {
                $this->assertInstanceOf(BaseRole::class, $role, 'roles() returns something that is not a BaseRole.');
            }
        }
    }

    #[Test]
    public function writerStoresOnlyInsideThePipelineTransaction(): void
    {
        $source = $this->azguardSource();
        $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $this->azguardAttach($builder, $source));

        if (! $source instanceof StoresGrants) {
            $this->assertFalse($panel->isWritable(), 'A source that does not store grants makes the panel writable.');

            return;
        }
        $this->assertTrue($panel->isWritable(), 'The panel does not take the source as its writer.');
        $writer = $panel->writer() ?? $this->azguardAttached($panel, $source);

        if (! $writer instanceof StoresGrants) {
            $this->fail('The panel does not give the writer back.');
        }
        $change = Change::grant($panel->id(), AccessScope::in(TenantRef::global()),
            SubjectRef::of($this->azguardSubject()->getMorphClass(), $this->azguardSubject()->getKey()),
            PermissionPattern::of($panel->id(), ContractPermission::View->value), IdentityCodec::DEFAULT_ORIGIN, ActorRef::system('contract'));

        try {
            $writer->apply($change);
        } catch (Throwable) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('The writer stored a change outside the transaction of the change pipeline.');
    }

    /** The instance the panel works with: a panel keeps its own copy of the source it is given. */
    private function azguardAttached(Panel $panel, Source $source): Source
    {
        foreach ($panel->attachedSources() as $attached) {
            if ($attached->id() === $source->id()) {
                return $attached;
            }
        }

        return $source;
    }

    /** @param callable(): mixed $work */
    private function azguardSucceeds(callable $work): bool
    {
        try {
            $work();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What the source gives for the subject in each scope, as plain values.
     *
     * @param  list<AccessScope>  $scopes
     * @return list<string>
     */
    private function azguardRead(Source $source, SubjectRef $ref, Panel $panel, array $scopes, DateTimeImmutable $now): array
    {
        $values = [];

        foreach ($scopes as $scope) {
            $context = new ContractContext($panel, $scope, $now);

            if ($source instanceof ProvidesGrants) {
                foreach ($source->grants($ref, [$scope], $context) as $grant) {
                    $values[] = 'grant|'.$grant->pattern->full().'|'.$grant->source.'|'.$grant->scope->context->key().'|'.($grant->expiresAt?->format('c') ?? '-').'|'.json_encode($grant->fields());
                }
            }

            if ($source instanceof ProvidesRoleGrants) {
                foreach ($source->roleGrants($ref, [$scope], $context) as $contribution) {
                    $values[] = 'role|'.$contribution->role->full().'|'.$contribution->source.'|'.$contribution->scope->context->key().'|'.($contribution->expiresAt?->format('c') ?? '-');
                }
            }
        }

        return $values;
    }
}
