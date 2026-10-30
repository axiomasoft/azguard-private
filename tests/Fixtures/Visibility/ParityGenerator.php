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
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Roles\SelectsAutomaticRoleScopes;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

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

        return range(1, getenv('AZGUARD_FULL_SEEDS') === false ? 2 : 50);
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
}
