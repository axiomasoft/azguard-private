<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

use AzGuard\Authorization\Authorizer;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Backoffice\BackofficePanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\OrganizationMembership;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Parity\Panels\Flat\FlatPanelProvider;
use Generator;
use ReflectionClass;

/**
 * The world of the parity matrix (I9): the CRM stand (`CrmWorld`: tenants A=1/B=2, users 1–4, projects P1–P5 with P3
 * inactive and P4 of tenant B, clients 1–6) with three panels on one storage and different scope declarations:
 *
 * - `crm`: `inherit` (the stand), Audit plugin on or off (the 0.7.0 "module on/off");
 * - `backoffice`: `isolated` on odd seeds, `required` on even seeds; never journaled;
 * - `flat`: mode `AssignmentScopeMode::None`, the panel without a scope declaration.
 *
 * Every panel allows the `auditor` role globally (global roles) and keeps the stand's membership, restrictions and
 * region condition. A seed writes generated rows with the dimensions of `ParityPropertyTest`, `DecideManyParityTest`
 * and `ScopePropertiesTest`: role and permission grants of every panel, own, foreign and global tenants, tenant-wide
 * (`context_key = global`) and project rows, projects of a foreign tenant, NULL/past/future expiry and NULL, empty
 * and region meta. This fixture never computes an expected verdict; the matrix compares paths with each other.
 */
final class ParityWorld
{
    /** @var list<int> */
    public const array SEEDS = [1, 2, 3, 4, 5, 6, 7, 8];

    /**
     * The seeds of this run: all of SEEDS, or the listed ones of `PARITY_SEEDS=1,2` (the server-database lanes of a
     * pull request). The agreement of the paths is checked for each seed alone; only the frozen ledger of unsupported
     * cases describes the whole range, so a partial run does not compare it (`isComplete()`).
     *
     * @return list<int>
     */
    public static function seeds(): array
    {
        $listed = getenv('PARITY_SEEDS');

        if ($listed === false || $listed === '') {
            return self::SEEDS;
        }
        $seeds = array_values(array_intersect(self::SEEDS, array_map('intval', explode(',', $listed))));

        return $seeds === [] ? self::SEEDS : $seeds;
    }

    public static function isComplete(): bool
    {
        return self::seeds() === self::SEEDS;
    }

    /** The fixed draw of the assignment matrix: its cases do not depend on the stored rows. */
    public const int ASSIGNMENT_SEED = 1;

    /** Rows each seed writes; each its own origin, so equal keys are separate witnesses. */
    public const int ROWS = 48;

    /** @var list<string> */
    public const array PANELS = ['crm', 'backoffice', 'flat'];

    /** @var list<int> user 4 belongs to no tenant, user 1 to both, users 2 and 3 to A only */
    public const array SUBJECTS = [1, 2, 3, 4];

    /** @var list<string> */
    public const array PERMISSIONS = ['clients.view', 'clients.update', 'clients.view_own_profile'];

    /** @var list<int> */
    public const array TENANTS = [1, 2];

    /** @var list<int> */
    public const array CLIENTS = [1, 2, 3, 4, 5, 6];

    /** @var list<string> */
    public const array ROLES = ['seller', 'analyst', 'caller', 'auditor', 'tenant-admin'];

    /** @var list<int|null> null is the tenant-wide context */
    public const array CONTEXTS = [null, 1, 2, 3, 4, 5];

    /** Expiry of a generated row or a proposed grant, around the stand clock 2026-10-06 12:00 UTC. */
    public const array EXPIRY = ['none' => null, 'past' => '2026-10-01 00:00:00', 'future' => '2026-11-01 00:00:00'];

    /** The module states every case runs under, as path suffixes. */
    public const array MODULES = ['' => false, '+audit' => true];

    public static function crmGuards(): string
    {
        return dirname((string) (new ReflectionClass(CrmGuardPanelProvider::class))->getFileName());
    }

    /** What every parity panel shares beyond the stand: the change roles and global auditor roles. */
    public static function common(PanelBuilder $panel): PanelBuilder
    {
        return $panel->roles([AuditorRole::class, SupportRole::class])
            ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new OrganizationMembership)->allowGlobalRoles([AuditorRole::class]));
    }

    /** Registers `crm`, `backoffice` and `flat` for one seed and module state, as `CrmWorld::compile()` does. */
    public static function compile(int $seed, bool $audit): void
    {
        CrmWorld::$exactMapping = true;
        CrmWorld::$sources = [CrmWorld::database()];
        CrmWorld::$configure = static function (PanelBuilder $panel) use ($audit): void {
            self::common($panel);

            if ($audit) {
                $panel->plugins([AuditPlugin::make()]);
            }
        };
        CrmWorld::$backofficeConfigure = static function (PanelBuilder $panel) use ($seed): void {
            $scope = ProjectScope::make()->filter(new ActiveProjects);
            self::common($panel)->assignmentScopes(new AssignmentScopeDeclaration($seed % 2 === 1 ? AssignmentScopeMode::Isolated : AssignmentScopeMode::Required, [$scope], evaluator: ScopeEvaluator::class));
        };
        $registry = new PanelRegistry(app());
        $registry->register(CrmGuardPanelProvider::class);
        $registry->register(BackofficePanelProvider::class);
        $registry->register(FlatPanelProvider::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);
    }

    /** Replaces every stored grant of the storage with the rows of `$seed`. */
    public static function write(int $seed): void
    {
        $rows = self::rows($seed);
        CrmWorld::storage()->mutate(self::PANELS, static function (StorageMutation $mutation) use ($rows): void {
            foreach (['role_grants', 'permission_grants'] as $table) {
                $mutation->table($table)->delete();
            }
            foreach ($rows as [$kind, $row]) {
                $mutation->table($kind.'_grants')->insert($row);
            }
            foreach (self::PANELS as $panel) {
                $mutation->touch($panel);
            }
        });
    }

    /**
     * The generated rows of a seed, in insertion order.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    public static function rows(int $seed): array
    {
        $draw = new Draw($seed);
        $rows = [];

        for ($i = 0; $i < self::ROWS; $i++) {
            $panel = $draw->pick(self::PANELS);
            $kind = $draw(2) === 0 ? 'role' : 'permission';
            $key = $kind === 'role' ? $draw->pick(self::ROLES) : $draw->pick(['clients.view', 'clients.update']);
            $subject = $draw->pick(self::SUBJECTS);
            $tenant = [TenantRef::of('crm.organization', 1), TenantRef::of('crm.organization', 2), TenantRef::global()][$draw(3)];
            // A row of the global tenant is tenant-wide; a project row may name a project of the other tenant.
            $context = $tenant->isGlobal() ? null : $draw->pick(self::CONTEXTS);
            $scope = AccessScope::in($tenant, $context === null ? null : CrmWorld::scope(project: $context)->context);
            $meta = [null, '{}', '{"region":"R1"}', '{"region":"R2"}', '{"region":null}'][$draw(5)];
            $expiry = $draw->pick(array_values(self::EXPIRY));
            $rows[] = [$kind, [
                'panel' => $panel, 'tenant_key' => $scope->tenant->key(), 'tenant_type' => $scope->tenant->type(), 'tenant_id' => $scope->tenant->id(),
                'context_key' => $scope->context->key(), 'context_type' => $scope->context->type(), 'context_id' => $scope->context->id(),
                'subject_type' => 'crm.user', 'subject_id' => (string) $subject, $kind => $key, 'origin' => 'parity-'.$i,
                'expires_at' => $expiry, 'meta' => $meta,
            ]];
        }

        return $rows;
    }

    /** @return Generator<int, ParityCase> */
    public static function decisionCases(int $seed): Generator
    {
        foreach (self::PANELS as $panel) {
            foreach (self::SUBJECTS as $subject) {
                foreach (self::PERMISSIONS as $permission) {
                    foreach (self::TENANTS as $tenant) {
                        foreach (self::CLIENTS as $client) {
                            yield new ParityCase(ParityCase::DECISION, $seed, $panel, $subject, $permission, $tenant, $client);
                        }
                    }
                }
            }
        }
    }

    /** @return Generator<int, ParityCase> */
    public static function assignmentCases(): Generator
    {
        $draw = new Draw(self::ASSIGNMENT_SEED);

        foreach (self::PANELS as $panel) {
            foreach (self::SUBJECTS as $subject) {
                foreach (self::ROLES as $role) {
                    foreach (self::TENANTS as $tenant) {
                        foreach (self::CONTEXTS as $context) {
                            yield new ParityCase(ParityCase::ASSIGNMENT, self::ASSIGNMENT_SEED, $panel, $subject, $role, $tenant, $context,
                                $draw->pick(array_keys(self::EXPIRY)));
                        }
                    }
                }
            }
        }
    }

    public static function decisionCount(): int
    {
        return count(self::PANELS) * count(self::SUBJECTS) * count(self::PERMISSIONS) * count(self::TENANTS) * count(self::CLIENTS);
    }

    public static function assignmentCount(): int
    {
        return count(self::PANELS) * count(self::SUBJECTS) * count(self::ROLES) * count(self::TENANTS) * count(self::CONTEXTS);
    }
}
