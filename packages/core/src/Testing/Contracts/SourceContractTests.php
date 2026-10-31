<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Sources\Internal\PanelSources;
use AzGuard\Support\ModelKey;
use AzGuard\Testing\Internal\Contracts\ContractContext;
use AzGuard\Testing\Internal\Contracts\ContractPermission;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use AzGuard\Testing\Internal\Contracts\OtherPermission;
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
 * - `describe()` lists the capabilities the source implements and what it returns has the type of its capability.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
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

    /**
     * Optional mutation fixture for a fenced source. Return a callable which accepts `grant` or `revoke` and applies
     * that operation to the suite subject in the global scope. Repeating either operation must be a no-op.
     * The callable must mutate the authority read by the attached source, not an unrelated copy of it.
     */
    protected function azguardFenceMutation(Source $source, Panel $panel): ?callable
    {
        return null;
    }

    /**
     * Optional finite resource universe: each callback returns resource-id => allowed (including denied resources).
     * The visibility callback may instead throw VisibilityNotSupportedException for an unsupported runtime.
     *
     * `expected` optionally supplies an independent resource-id => boolean oracle, including tenant/context denials.
     *
     * @return array{scalar: callable(): array, batch: callable(): array, visibility: callable(): array, supported: bool, expected?: array}|null
     */
    protected function azguardQueryParity(): ?array
    {
        return null;
    }

    public function sourceVisibilityMatchesScalarAndBatch(): void
    {
        $case = $this->azguardQueryParity();

        if ($case === null) {
            return;
        }

        $this->azguardAssertQueryParity($case);
    }

    /** @param array{scalar: callable(): array, batch: callable(): array, visibility: callable(): array, supported: bool, expected?: array} $case */
    private function azguardAssertQueryParity(array $case): void
    {
        $scalar = ($case['scalar'])();
        $batch = ($case['batch'])();
        foreach ($scalar as $allowed) {
            $this->assertIsBool($allowed, 'Scalar parity results must be booleans.');
        }
        foreach ($batch as $allowed) {
            $this->assertIsBool($allowed, 'Batch parity results must be booleans.');
        }

        if (isset($case['expected'])) {
            $this->assertSameSize($case['expected'], $scalar, 'Scalar decisions differ from the expected authority.');
            foreach ($case['expected'] as $id => $allowed) {
                $this->assertIsBool($allowed, 'Expected parity results must be booleans.');
                $this->assertArrayHasKey($id, $scalar, 'Scalar decisions differ from the expected authority.');
                $this->assertSame($allowed, $scalar[$id], 'Scalar decisions differ from the expected authority.');
            }
        }
        $this->assertContains(true, $scalar, 'The parity universe must contain an allowed resource.');
        $this->assertContains(false, $scalar, 'The parity universe must contain a denied resource.');
        $this->assertSameSize($scalar, $batch, 'Batch decisions differ from scalar decisions.');
        foreach ($scalar as $id => $allowed) {
            $this->assertArrayHasKey($id, $batch, 'Batch decisions differ from scalar decisions.');
            $this->assertSame($allowed, $batch[$id], 'Batch decisions differ from scalar decisions.');
        }

        try {
            $visible = ($case['visibility'])();
        } catch (VisibilityNotSupportedException) {
            $this->assertFalse($case['supported'], 'A supported visibility case returned unsupported.');

            return;
        }

        $this->assertTrue($case['supported'], 'An unsupported visibility case returned a result.');
        $this->assertSameSize($scalar, $visible, 'Visibility differs from scalar decisions.');
        foreach ($scalar as $id => $allowed) {
            $this->assertArrayHasKey($id, $visible, 'Visibility differs from scalar decisions.');
            $this->assertSame($allowed, $visible[$id], 'Visibility differs from scalar decisions.');
        }
    }

    public function fencedAuthorityChangesItsStateOnlyOnMutation(): void
    {
        $source = $this->azguardSource();

        if (! $source instanceof FencesReads) {
            return;
        }
        $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $this->azguardAttach($builder, $source));
        $source = $this->azguardAttached($panel, $source);
        $mutate = $this->azguardFenceMutation($source, $panel);

        if ($mutate === null) {
            return;
        }

        $this->azguardAssertFenceMutation($source, $panel, $mutate);
    }

    /** @param callable(string): void $mutate */
    private function azguardAssertFenceMutation(FencesReads $source, Panel $panel, callable $mutate): void
    {
        $tenant = TenantRef::global();
        $ref = SubjectRef::of($this->azguardSubject()->getMorphClass(), ModelKey::of($this->azguardSubject()));
        $scopes = [AccessScope::in($tenant)];
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $mutate('revoke');
        $before = $source->state($panel, $tenant);
        $empty = $this->azguardRead($source, $ref, $panel, $scopes, $now);
        $mutate('revoke');
        $this->assertTrue($before->equals($source->state($panel, $tenant)), 'A no-op revoke changed the state.');
        $this->assertEqualsCanonicalizing($empty, $this->azguardRead($source, $ref, $panel, $scopes, $now), 'The same state returned different raw authority after a no-op revoke.');
        $mutate('grant');
        $granted = $source->state($panel, $tenant);
        $authority = $this->azguardRead($source, $ref, $panel, $scopes, $now);
        $this->assertNotEquals($empty, $authority, 'The grant fixture did not change the subject authority.');
        $this->assertFalse($before->equals($granted), 'A grant changed authority without changing state.');
        $mutate('grant');
        $this->assertTrue($granted->equals($source->state($panel, $tenant)), 'A no-op grant changed the state.');
        $this->assertEqualsCanonicalizing($authority, $this->azguardRead($source, $ref, $panel, $scopes, $now), 'The same state returned different raw authority.');
        $mutate('revoke');
        $revoked = $source->state($panel, $tenant);
        $this->assertFalse($granted->equals($revoked), 'A revoke changed authority without changing state.');
        $this->assertEqualsCanonicalizing($empty, $this->azguardRead($source, $ref, $panel, $scopes, $now), 'Revoke did not restore the original authority.');
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
        $ids = array_map(static fn (SourceDescription $description): string => $description->id, $panel->sources());

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

        $source = $this->azguardAttached($panel, $source);

        if ($source instanceof FencesReads) {
            $tenant = TenantRef::global();
            $ref = SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject));
            $scopes = [AccessScope::in($tenant)];
            $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
            $before = $source->state($panel, $tenant);
            $first = $this->azguardRead($source, $ref, $panel, $scopes, $now);
            $middle = $source->state($panel, $tenant);
            $second = $this->azguardRead($source, $ref, $panel, $scopes, $now);
            $after = $source->state($panel, $tenant);

            if ($before->equals($middle) && $middle->equals($after)) {
                $this->assertEqualsCanonicalizing($first, $second, 'The same state returned different raw authority.');
            }
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
        $ref = SubjectRef::of($this->azguardSubject()->getMorphClass(), ModelKey::of($this->azguardSubject()));
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
        $ref = SubjectRef::of($this->azguardSubject()->getMorphClass(), ModelKey::of($this->azguardSubject()));
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

        if ($source instanceof FencesReads && ($mutate = $this->azguardFenceMutation($source, $panel)) !== null) {
            $this->azguardAssertFenceMutation($source, $panel, $mutate);
        }

        if (($case = $this->azguardQueryParity()) !== null) {
            $this->azguardAssertQueryParity($case);
        }
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
                    $values[] = serialize($grant);
                }
            }

            if ($source instanceof ProvidesRoleGrants) {
                foreach ($source->roleGrants($ref, [$scope], $context) as $contribution) {
                    $values[] = serialize($contribution);
                }
            }
        }

        return $values;
    }
}
