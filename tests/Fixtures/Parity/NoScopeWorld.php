<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\ChangeResult;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Parity\Dataset\Dataset;
use AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Crm\CrmPanelProvider;
use AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Ops\OpsPanelProvider;
use AzGuard\Tests\Fixtures\Parity\Panels\NoScope\NoScopeCrmPanelProvider;
use AzGuard\Tests\Support\AuditTable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * The I7 world: the `Dataset` written through the public API with the scope declaration of panel `crm`, its CRM host
 * rows, and panel `crm` rebuilt with an explicit `AssignmentScopeMode::None` declaration (`NoScopeCrmPanelProvider`);
 * panel `ops` as seeded.
 *
 * Rows the cases rely on (tenant A = crm.organization:1): user 2 has `auditor` tenant-wide (meta region R1),
 * `analyst` in P1 until 2026-12-01 and `clients.view` in P1 until 2027-01-01; user 1 has `seller` in P5; user 3 has
 * `support` tenant-wide. The clock is `Dataset::PRUNE_CLOCK`, the last instant the dataset wrote at.
 */
final class NoScopeWorld
{
    public const string EXPIRED_CLOCK = '2026-12-02 00:00:00';

    /**
     * Seeds the dataset through the public API with the scoped declaration of panel `crm` (`CrmPanelProvider`), then
     * rebuilds `crm` without scope behavior.
     */
    public static function seed(): void
    {
        $storage = AzGuard::storage();
        app(StorageSchema::class)->create($storage->id);
        AuditTable::create($storage->id);
        Dataset::addApplicationColumns(app('db')->connection($storage->connection), $storage->prefix);
        Dataset::seed($storage->id);
        Carbon::setTestNow(Carbon::parse(Dataset::PRUNE_CLOCK, 'UTC'));
        self::compile(NoScopeCrmPanelProvider::class);
    }

    /** @param class-string<PanelProvider> $crm */
    private static function compile(string $crm): void
    {
        $registry = new PanelRegistry(app());
        $registry->register($crm);
        $registry->register(OpsPanelProvider::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);
    }

    /**
     * Each I7 case: whether it holds and what 0.7.0 does. The expectation is fixed by I7; a control case proves the
     * panel still decides and writes at all.
     *
     * @return array<string, Closure(): array{bool, string}>
     */
    public static function cases(): array
    {
        return [
            'a check in a project is denied (analyst row)' => static fn (): array => self::denied(2, 'clients.view_any', 1),
            'a check in a project is denied (seller row)' => static fn (): array => self::denied(1, 'clients.view_any', 5),
            'a global check is denied where only a scoped analyst row would allow' => static fn (): array => self::denied(2, 'clients.view_any'),
            'a global check is denied where only a scoped seller row would allow' => static fn (): array => self::denied(1, 'clients.view_any'),
            'control: the scoped rows allow in their projects with the dataset declaration' => static function (): array {
                self::compile(CrmPanelProvider::class);
                [$analyst, $seller] = [self::denied(2, 'clients.view_any', 1), self::denied(1, 'clients.view_any', 5)];

                return [! $analyst[0] && ! $seller[0], $analyst[1].' '.$seller[1]];
            },
            'control: a global check with a tenant-wide row is allowed' => static function (): array {
                [$denied, $observed] = self::denied(3, 'clients.view_any');

                return [! $denied, $observed];
            },
            'a role grant in a project is rejected' => static fn (): array => self::rejected(static fn () => self::user(2)->grantRole('analyst', self::project(5))),
            'a permission grant in a project is rejected' => static fn (): array => self::rejected(static fn () => self::user(1)->grantPermission('clients.view', self::project(1))),
            'control: a tenant-wide role grant is accepted' => static function (): array {
                [$rejected, $observed] = self::rejected(static fn () => self::user(1)->grantRole('auditor'));

                return [! $rejected && $observed === 'applied', $observed];
            },
            'a scoped role row is revoked without scope behavior' => static fn (): array => self::withoutScopeBehavior(static function (): array {
                $observed = self::change(static fn () => self::user(1)->revokeRole('seller', self::project(5)));

                return [$observed === 'applied' && ! self::stored('role', 1, 'seller', 'crm.project:5'), $observed];
            }),
            'a scoped permission row is revoked without scope behavior' => static fn (): array => self::withoutScopeBehavior(static function (): array {
                $observed = self::change(static fn () => self::user(2)->revokePermission('clients.view', self::project(1)));

                return [$observed === 'applied' && ! self::stored('permission', 2, 'clients.view', 'crm.project:1'), $observed];
            }),
            'an expired scoped row is pruned without scope behavior' => static fn (): array => self::withoutScopeBehavior(static function (): array {
                Carbon::setTestNow(Carbon::parse(self::EXPIRED_CLOCK, 'UTC'));
                $exit = Artisan::call('azguard:grants:prune');
                $pruned = ! self::stored('role', 2, 'analyst', 'crm.project:1');
                $kept = self::stored('permission', 2, 'clients.view', 'crm.project:1');

                return [$exit === 0 && $pruned && $kept, 'exit='.$exit.' analyst P1 '.($pruned ? 'pruned' : 'kept').', clients.view P1 '.($kept ? 'kept' : 'pruned')];
            }),
        ];
    }

    /** @return array{bool, string} */
    private static function denied(int $user, string $permission, ?int $project = null): array
    {
        $tenant = TenantRef::of('crm.organization', 1);
        $request = AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of('crm', $permission))
            ->inScope(AccessScope::in($tenant, $project === null ? null : self::project($project)));
        $decision = app(Authorizer::class)->decide(app(PanelRegistry::class)->get('crm'), $request);

        return [! $decision->allowed(), ($decision->allowed() ? 'allow' : 'deny').'('.$decision->reason->value.')'];
    }

    /**
     * @param  Closure(): ChangeResult  $write
     * @return array{bool, string}
     */
    private static function rejected(Closure $write): array
    {
        $observed = self::change($write);

        return [str_starts_with($observed, 'rejected('), $observed];
    }

    /** @param Closure(): ChangeResult $write */
    private static function change(Closure $write): string
    {
        try {
            return AzGuard::actingAs('parity i7', $write)->applied() ? 'applied' : 'unchanged';
        } catch (AzGuardException $error) {
            return 'rejected('.$error->code().')';
        }
    }

    /**
     * Runs a case with every scope behavior of the panel failing and its filters observed: revocation and expiry
     * never consult the scope evaluator (architecture, chain G).
     *
     * @param  Closure(): array{bool, string}  $case
     * @return array{bool, string}
     */
    private static function withoutScopeBehavior(Closure $case): array
    {
        ProjectScope::$failure = 'resolve';
        ActiveProjects::$observed = [];

        try {
            [$holds, $observed] = $case();
        } finally {
            ProjectScope::$failure = null;
        }
        $consulted = ActiveProjects::$observed !== [];

        return [$holds && ! $consulted, $observed.($consulted ? ', scope filter consulted' : '')];
    }

    private static function user(int $id): SubjectAccess
    {
        return AzGuard::panel('crm')->inTenant(TenantRef::of('crm.organization', 1))->for(User::query()->findOrFail($id));
    }

    private static function project(int $id): AssignmentScopeRef
    {
        return AssignmentScopeRef::of('crm.project', $id);
    }

    private static function stored(string $kind, int $user, string $key, string $context): bool
    {
        return CrmWorld::storage()->table($kind.'_grants')
            ->where('panel', 'crm')->where('subject_id', (string) $user)->where($kind, $key)->where('context_key', $context)->exists();
    }
}
