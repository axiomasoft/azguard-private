<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Visibility;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Roles\SelectsAutomaticRoleScopes;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use DateTimeImmutable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

class AuditParityVeto extends VisibilityPolicy
{
    #[Decides('orders.view')]
    public function view(?Model $user, ?Model $resource): ?bool
    {
        return parent::view($user, $resource);
    }
}

#[Role('audit-automatic')]
#[NotGrantable]
class AuditAutomaticRole extends BaseRole implements GrantedAutomatically, SelectsAutomaticRoleScopes
{
    public static bool $enabled = false;

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [new VisibilityProjectScope];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return self::$enabled && $subject->getKey() === 1 && in_array($scope->context->id(), ['1', '2', '3'], true);
    }

    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array
    {
        return self::$enabled && $subject->getKey() === 1
            ? array_map(static fn (int $id): AccessScope => VisibilityWorld::scope($id), [1, 2, 3]) : [];
    }
}

#[Role('audit-global-automatic')]
#[NotGrantable]
final class AuditGlobalAutomaticRole extends AuditAutomaticRole
{
    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return self::$enabled && $subject->getKey() === 1 && $scope->tenant->isGlobal()
            && in_array($scope->context->id(), ['1', '2', '3'], true);
    }

    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array
    {
        return self::$enabled && $subject->getKey() === 1
            ? array_map(static fn (int $id): AccessScope => AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', $id)), [1, 2, 3]) : [];
    }
}

/** Facts and reference semantics, deliberately independent of the authorization engine. */
final class ParityGenerator
{
    public readonly array $rows;

    public readonly array $contributions;

    public readonly bool $before;

    public readonly bool $restriction;

    public readonly bool $veto;

    public readonly bool $tenant;

    public readonly bool $automatic;

    public readonly bool $member;

    public function __construct(public readonly int $seed)
    {
        $random = new Randomizer(new Mt19937($seed));
        $this->before = $seed % 2 === 0;
        $this->restriction = $seed % 3 === 0;
        $this->veto = $seed % 5 === 0;
        $this->tenant = $seed % 2 === 1;
        $this->automatic = $seed % 7 === 0;
        $this->member = $seed % 11 !== 0;
        $rows = [];
        for ($id = 1; $id <= 200; $id++) {
            $rows[] = ['id' => $id, 'project_id' => null, 'org' => $id % 4 === 0 ? 'B' : 'A',
                'city' => ['Paris', 'Rome', null][$random->getInt(0, 2)],
                'state' => ['allow', 'deny', null][$random->getInt(0, 2)], 'active' => (bool) $random->getInt(0, 1)];
        }
        $this->rows = $rows;
        $contributions = [];
        for ($i = 0; $i < 12; $i++) {
            $contributions[] = ['role' => (bool) $random->getInt(0, 1), 'scope' => $i < 4 ? null : $random->getInt(1, 200),
                'city' => [null, 'Paris', 'Rome'][$random->getInt(0, 2)], 'expired' => (bool) $random->getInt(0, 1),
                'wildcard' => (bool) $random->getInt(0, 1)];
        }
        $this->contributions = $contributions;
    }

    /** @return list<int> */
    public static function seeds(): array
    {
        $only = getenv('AZGUARD_PROPERTY_SEED');

        if ($only !== false) {
            if (! ctype_digit($only) || (int) $only < 1 || (int) $only > 50) {
                throw new InvalidArgumentException('AZGUARD_PROPERTY_SEED must be an integer from 1 to 50.');
            }

            return [(int) $only];
        }

        $range = getenv('AZGUARD_PARITY_SEEDS');

        if ($range !== false) {
            $seeds = [];
            foreach (explode(',', $range) as $part) {
                if (! preg_match('/\A([1-9][0-9]*)(?:-([1-9][0-9]*))?\z/', $part, $matches)) {
                    throw new InvalidArgumentException('AZGUARD_PARITY_SEEDS must be a comma-separated list of seeds or inclusive ranges from 1 to 50.');
                }
                $first = (int) $matches[1];
                $last = isset($matches[2]) ? (int) $matches[2] : $first;

                if ($first > $last || $last > 50) {
                    throw new InvalidArgumentException('AZGUARD_PARITY_SEEDS must contain ascending ranges from 1 to 50.');
                }
                array_push($seeds, ...range($first, $last));
            }

            return $seeds;
        }

        return range(1, getenv('AZGUARD_FULL_SEEDS') === false ? 2 : 50);
    }

    /** Run the same generated facts through each entry point with cold, request memo and shared-store reads. */
    public static function cachedOracle(string $store): void
    {
        self::configureSignedCache();
        app(StorageSchema::class)->create('default');
        $cacheEvents = (object) ['hits' => 0, 'writes' => 0];
        $cacheExpected = false;
        app('events')->listen(CacheHit::class, static function (CacheHit $event) use ($cacheEvents, $store): void {
            if ($event->storeName === $store && str_starts_with($event->key, 'azguard:sets:')) {
                $cacheEvents->hits++;
            }
        });
        app('events')->listen(KeyWritten::class, static function (KeyWritten $event) use ($cacheEvents, $store): void {
            if ($event->storeName === $store && str_starts_with($event->key, 'azguard:sets:')) {
                $cacheEvents->writes++;
            }
        });
        foreach (self::seeds() as $seed) {
            try {
                $facts = new self($seed);
                $cacheExpected = $cacheExpected || ! $facts->tenant || $facts->member;
                VisibilityProject::query()->delete();
                VisibilityProject::query()->insert($facts->rows);
                VisibilityWorld::$tenanted = $facts->tenant;
                AuditAutomaticRole::$enabled = $facts->automatic;
                DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation) use ($facts): void {
                    $mutation->table('permission_grants')->delete();
                    $mutation->table('role_grants')->delete();
                    foreach ($facts->contributions as $i => $fact) {
                        $kind = $fact['role'] ? 'role' : 'permission';
                        $value = $fact['role'] ? 'viewer' : ($fact['wildcard'] ? 'orders.*' : 'orders.view');
                        $mutation->table($kind.'_grants')->insert(DatabaseWorld::row($kind, VisibilityWorld::scope($fact['scope']), [
                            $kind => $value, 'origin' => 'generated-'.$i,
                            'expires_at' => $fact['expired'] ? '2026-10-06 12:00:00' : null,
                            'meta' => json_encode($fact['city'] === null ? [] : ['city' => $fact['city']], JSON_THROW_ON_ERROR),
                        ]));
                    }
                    $mutation->touch('admin');
                });
                $source = DatabaseSource::make()->decisionFields(roleGrant: ['city'], permissionGrant: ['city']);
                $records = VisibilityProject::query()->orderBy('id')->get();
                $requests = $expected = [];
                foreach ($records as $i => $record) {
                    if ($facts->tenant && $facts->rows[$i]['org'] !== 'A') {
                        continue;
                    }
                    $requests[] = AccessRequest::for(VisibilityWorld::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record);

                    if ($facts->allows($facts->rows[$i])) {
                        $expected[] = $record->id;
                    }
                }
                foreach (['scalar', 'batch', 'visibility'] as $generation => $entry) {
                    [$visibility, $panel, $engine] = VisibilityWorld::compile(configure: static function (PanelBuilder $p) use ($facts, $store, $generation): void {
                        $p->cache($store, 60, generation: $generation + 1)
                            ->fields(FieldTarget::RoleGrant, [Field::string('city')->inMeta()])
                            ->fields(FieldTarget::PermissionGrant, [Field::string('city')->inMeta()])
                            ->grantConditions([VisibilityCondition::class])->roles([AuditAutomaticRole::class]);

                        if ($facts->tenant && ! $facts->member) {
                            $p->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership([])));
                        }

                        if ($facts->before) {
                            $p->before([VisibilityBefore::class]);
                        }

                        if ($facts->restriction) {
                            $p->restrictions([VisibilityRestriction::class]);
                        }

                        if ($facts->veto) {
                            $p->policies([PolicyBinding::for('orders.view', AuditParityVeto::class)]);
                        }
                    }, tenant: $facts->tenant, extra: [$source]);
                    app()->forgetScopedInstances();
                    $writesBefore = $cacheEvents->writes;
                    $coldWrites = 0;
                    foreach (['cold', 'memo', 'shared'] as $lane) {
                        if ($lane === 'shared') {
                            app()->forgetScopedInstances();
                        }
                        $hitsBefore = $cacheEvents->hits;
                        $message = "seed=$seed entry=$entry lane=$lane; reproduce: AZGUARD_PROPERTY_SEED=$seed vendor/bin/pest tests/Feature/Parity/".($store === 'parity' ? 'SharedRedisParityTest.php' : 'AuditParityTest.php');
                        $actual = [];

                        if ($entry === 'visibility') {
                            $actual = $visibility->visibleTo($panel, VisibilityProject::query(), VisibilityWorld::subject(), 'orders.view', $facts->tenant ? VisibilityWorld::scope() : null)->orderBy('id')->pluck('id')->all();
                        } else {
                            $batch = $entry === 'batch' ? $engine->decideMany($requests) : null;
                            foreach ($requests as $i => $request) {
                                $decision = $batch === null ? $engine->decide($panel, $request) : $batch->get($i);
                                Assert::assertNull($decision->failure(), $message.' row='.$request->resource()->getKey().' failure: '.$decision->reason->value);

                                if ($decision->allowed()) {
                                    $actual[] = $request->resource()->getKey();
                                }
                            }
                        }
                        Assert::assertSame($expected, $actual, $message);

                        // Exact visibility reads selections directly. Prime the same generation through scalar
                        // after its cold query so the next queries run with populated authority caches.
                        if ($entry === 'visibility' && $lane === 'cold') {
                            foreach ($requests as $request) {
                                $decision = $engine->decide($panel, $request);
                                Assert::assertNull($decision->failure(), $message.' cache primer failure: '.$decision->reason->value);
                            }
                        }

                        if ($lane === 'cold') {
                            $coldWrites = $cacheEvents->writes - $writesBefore;
                        }

                        if ($lane === 'shared' && $entry !== 'visibility' && $coldWrites > 0) {
                            Assert::assertGreaterThan($hitsBefore, $cacheEvents->hits, $message.' must read the shared authority cache');
                        }
                    }
                }
            } catch (Throwable $error) {
                fwrite(STDERR, "Parity seed=$seed store=$store; reproduce: AZGUARD_PROPERTY_SEED=$seed vendor/bin/pest tests/Feature/Parity/".($store === 'parity' ? 'SharedRedisParityTest.php' : 'AuditParityTest.php')."\n");

                throw $error;
            }
        }

        if ($cacheExpected) {
            Assert::assertGreaterThan(0, $cacheEvents->writes, 'Generated scenarios must populate the shared authority cache.');
            Assert::assertGreaterThan(0, $cacheEvents->hits, 'Generated scenarios must read the shared authority cache after clearing request memo.');
        }
    }

    public function source(): VisibilitySource
    {
        $direct = $roles = [];
        foreach ($this->contributions as $fact) {
            $expiry = $fact['expired'] ? new DateTimeImmutable('2026-10-06 12:00:00 UTC') : null;
            $fields = $fact['city'] === null ? [] : ['city' => $fact['city']];

            if ($fact['role']) {
                $roles[] = VisibilityWorld::role($fact['scope'], $fields, expiry: $expiry);
            } else {
                $direct[] = Grant::of(PermissionPattern::of('admin', $fact['wildcard'] ? 'orders.*' : 'orders.view'),
                    'generated', VisibilityWorld::scope($fact['scope']), expiresAt: $expiry, fields: $fields);
            }
        }

        return new VisibilitySource(direct: $direct, roles: $roles);
    }

    /** Expected result from the generated facts, without consulting grants, decisions or SQL predicates. */
    public function allows(array $row): bool
    {
        if (($this->tenant && (! $this->member || $row['org'] !== 'A')) || ($this->before && ! $row['active'])
            || ($this->restriction && $row['city'] !== 'Paris') || ($this->veto && $row['state'] === 'deny')) {
            return false;
        }

        if ($this->automatic && in_array($row['id'], [1, 2, 3], true)) {
            return true;
        }
        foreach ($this->contributions as $fact) {
            if (! $fact['expired'] && ($fact['scope'] === null || $fact['scope'] === $row['id'])
                && ($fact['city'] === null || $fact['city'] === $row['city'])) {
                return true;
            }
        }

        return false;
    }

    /** Run each entry point before and after revocation against the same cached authority. */
    public static function cacheGenerations(string $store): void
    {
        self::configureSignedCache();
        app(StorageSchema::class)->create('default');
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'orders.view'])]);
        [$visibility, $panel, $engine] = VisibilityWorld::compile(configure: fn (PanelBuilder $p) => $p->cache($store, 60), extra: [DatabaseSource::make()]);
        $record = VisibilityProject::query()->findOrFail(1);
        $request = AccessRequest::for(VisibilityWorld::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record);
        foreach ([true, false] as $expected) {
            for ($round = 0; $round < 2; $round++) {
                $scalar = $engine->decide($panel, $request);
                $batch = $engine->decideMany([$request])->get(0);
                expect($scalar->failure())->toBeNull()->and($batch->failure())->toBeNull()
                    ->and($scalar->allowed())->toBe($expected)->and($batch->allowed())->toBe($expected)
                    ->and($visibility->visibleTo($panel, VisibilityProject::query(), VisibilityWorld::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())
                    ->toBe($expected ? [1, 2, 3, 4] : []);
                app()->forgetScopedInstances();
            }

            if ($expected) {
                DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
                    $mutation->table('permission_grants')->where('panel', 'admin')->delete();
                    $mutation->touch('admin');
                });
                app()->forgetScopedInstances();
            }
        }
    }

    private static function configureSignedCache(): void
    {
        config([
            'azguard.cache.namespace' => 'parity-'.bin2hex(random_bytes(16)),
            'azguard.cache.signing_key' => bin2hex(random_bytes(32)),
            'azguard.cache.key_id' => 'parity',
            'azguard.cache.previous_keys' => [],
        ]);
    }
}
