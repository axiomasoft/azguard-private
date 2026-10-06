<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Authorization\Authorizer;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Roles\TenantAdminRole;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ReaderRole;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use AzGuard\Tests\Fixtures\Scopes\WideReaderRole;
use Closure;
use DateTimeImmutable;
use Generator;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Seeded inputs only: this fixture never computes an authorization oracle. */
final class ScenarioGenerator
{
    public const int COUNT = 200;

    public readonly string $panel;

    public readonly SubjectRef $subject;

    public readonly TenantRef $tenant;

    public readonly TenantRef $foreignTenant;

    public readonly AssignmentScopeRef $context;

    public readonly AssignmentScopeRef $otherContext;

    public readonly DateTimeImmutable $now;

    private readonly Randomizer $random;

    public function __construct(public readonly int $seed)
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->panel = $seed % 2 === 0 ? 'alpha' : 'beta';
        $ids = ['01', 'A:B', 'a/b', (string) $this->random->getInt(100, 999999)];
        $id = $ids[$seed % count($ids)];
        $this->subject = SubjectRef::of('user', $id);
        $this->tenant = TenantRef::of('org', $id);
        $this->foreignTenant = $seed % 2 === 0 ? TenantRef::of('org', $id.'-other') : TenantRef::of('company', $id);
        $this->context = AssignmentScopeRef::of('store', $id);
        $this->otherContext = AssignmentScopeRef::of('store', $id.'-other');
        $this->now = (new DateTimeImmutable('2026-10-05 12:00:00 UTC'))->modify('+'.$this->random->getInt(0, 604800).' seconds');
    }

    /** @return Generator<int, self> */
    public static function scenarios(): Generator
    {
        $only = getenv('AZGUARD_PROPERTY_SEED');

        if ($only !== false) {
            yield (int) $only => new self((int) $only);

            return;
        }

        for ($seed = 1; $seed <= self::COUNT; $seed++) {
            yield $seed => new self($seed);
        }
    }

    public function flag(): bool
    {
        return $this->random->getInt(0, 1) === 1;
    }

    public function expiry(int $offset): DateTimeImmutable
    {
        return $this->now->modify(sprintf('%+d seconds', $offset));
    }

    public function scope(bool $context = false, bool $global = false): AccessScope
    {
        return AccessScope::in($global ? TenantRef::global() : $this->tenant, $context ? $this->context : null);
    }

    public function grant(?AccessScope $scope = null, string $source = 'generated', ?DateTimeImmutable $expires = null, array $fields = [], ?string $panel = null): Grant
    {
        return Grant::of(PermissionPattern::of($panel ?? $this->panel, 'orders.view'), $source, $scope ?? $this->scope(global: true), expiresAt: $expires, fields: $fields);
    }

    public function role(?AccessScope $scope = null, string $source = 'generated', string $key = 'root', ?DateTimeImmutable $expires = null, array $fields = [], ?string $panel = null): RoleContribution
    {
        return RoleContribution::of(RoleKey::of($panel ?? $this->panel, $key), $scope ?? $this->scope(global: true), $source, expiresAt: $expires, fields: $fields);
    }

    /**
     * Compile the real engine with two independent panel registries, using existing source contracts.
     *
     * @param  list<GeneratedSource>  $sources
     * @return array{Authorizer, Panel, AccessRequest, PanelResolver, PanelRegistry}
     */
    public function world(array $sources, ?Closure $configure = null, ?string $mode = null, ?GeneratedSource $otherSource = null): array
    {
        $definition = new StoreScope(fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, $this->tenant));
        app()->instance(StoreScope::class, $definition);
        $describe = function (PanelBuilder $panel, string $id, array $inputs) use ($configure, $mode, $definition): void {
            $panel->id($id)->for(User::class)->permissions([...$inputs, ScenarioPermission::class])
                ->roles([GrantableRootRole::class, $mode === null || $mode === 'none' ? WideReaderRole::class : ReaderRole::class, ...($mode === null || $mode === 'none' ? [] : [TenantAdminRole::class])])
                ->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);

            if ($mode !== null) {
                $policy = $mode === 'none' ? AssignmentScopePolicy::none() : AssignmentScopePolicy::{$mode}($definition);
                $panel->scopes($policy)->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership([$this->subject->id(), '1'])));
            }

            if ($configure !== null) {
                $configure($panel);
            }
        };
        [$resolver, , $registry] = PanelWorld::compile([
            AlphaPanel::class => fn (PanelBuilder $p) => $describe($p, 'alpha', $this->panel === 'alpha' ? $sources : [$otherSource ?? new GeneratedSource(name: 'other')]),
            BetaPanel::class => fn (PanelBuilder $p) => $describe($p, 'beta', $this->panel === 'beta' ? $sources : [$otherSource ?? new GeneratedSource(name: 'other')]),
        ]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return [app(Authorizer::class), $registry->get($this->panel), AccessRequest::for($this->subject, PermissionKey::of($this->panel, 'orders.view')), $resolver, $registry];
    }

    /** @param list<GeneratedSource> $sources
     * @return list<list<GeneratedSource>>
     */
    public function permutations(array $sources): array
    {
        [$a, $b, $c] = $sources;

        return [[$a, $b, $c], [$a, $c, $b], [$b, $a, $c], [$b, $c, $a], [$c, $a, $b], [$c, $b, $a]];
    }
}
