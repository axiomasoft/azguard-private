<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\RolePermissionValidator;
use AzGuard\Models\Role;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Diff-based role-permission write on the role model's connection.
 *
 * Validation finishes before the transaction. A fingerprint mismatch rolls
 * back without changing rows. A later write failure rolls the diff back too.
 */
final readonly class RolePermissionSynchronizer
{
    public function __construct(private RolePermissionValidator $validator) {}

    public function sync(Role $role, RolePermissionSelection $selection): RolePermissionSyncResult
    {
        foreach ($selection->desired as $tuple) {
            $this->validator->validate($tuple[1], $tuple[0]);
        }

        $this->assertSameConnection($role);

        return $role->getConnection()->transaction(function () use ($role, $selection): RolePermissionSyncResult {
            $locked = $role->newQuery()->whereKey($role->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Role) {
                throw new RuntimeException('Role no longer exists.');
            }

            $current = $this->managedRows($this->rows($locked), $selection);
            $fingerprint = RolePermissionSelection::fingerprint($current);

            if ($selection->expectedFingerprint !== null && ! $this->fingerprintMatches($selection->expectedFingerprint, $fingerprint)) {
                throw RolePermissionSyncConflictException::staleFingerprint();
            }

            $currentMap = $this->index($current);
            $desiredMap = $this->index($selection->desired);
            $toRemove = array_values(array_diff_key($currentMap, $desiredMap));
            $toAdd = array_values(array_diff_key($desiredMap, $currentMap));

            if ($toRemove === [] && $toAdd === []) {
                return new RolePermissionSyncResult(added: 0, removed: 0);
            }

            $model = Config::rolePermissionModel();

            foreach ($toRemove as $tuple) {
                $model::query()
                    ->where('role_id', $locked->getKey())
                    ->where('panel_id', $tuple[0])
                    ->where('permission_key', $tuple[1])
                    ->delete();
            }

            foreach ($toAdd as $tuple) {
                $model::query()->create([
                    'role_id' => $locked->getKey(),
                    'panel_id' => $tuple[0],
                    'permission_key' => $tuple[1],
                ]);
            }

            return new RolePermissionSyncResult(added: count($toAdd), removed: count($toRemove));
        });
    }

    /** @param  list<array{0: string, 1: string}>  $managed */
    public function fingerprint(Role $role, array $managed): string
    {
        $selection = RolePermissionSelection::managedSubset(managed: $managed, desired: []);
        $this->assertSameConnection($role);

        return RolePermissionSelection::fingerprint($this->managedRows($this->rows($role), $selection));
    }

    private function assertSameConnection(Role $role): void
    {
        $permission = $this->permissionModel();
        $default = (string) config('database.default');
        $roleConnection = $role->getConnectionName() ?? $default;
        $permissionConnection = $permission->getConnectionName() ?? $default;

        if ($roleConnection !== $permissionConnection) {
            throw RolePermissionConnectionException::mismatch(
                roleConnection: $roleConnection,
                permissionConnection: $permissionConnection,
            );
        }
    }

    private function permissionModel(): Model
    {
        $class = Config::rolePermissionModel();

        return new $class;
    }

    /** @return list<array{0: string, 1: string}> */
    private function rows(Role $role): array
    {
        $class = Config::rolePermissionModel();

        return $class::query()
            ->where('role_id', $role->getKey())
            ->get(['panel_id', 'permission_key'])
            ->map(static fn (Model $row): array => [(string) $row->getAttribute('panel_id'), (string) $row->getAttribute('permission_key')])
            ->all();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     * @return list<array{0: string, 1: string}>
     */
    private function managedRows(array $rows, RolePermissionSelection $selection): array
    {
        if ($selection->managed !== null) {
            $allowed = $this->index($selection->managed);

            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($allowed[$row[0]."\t".$row[1]]),
            ));
        }

        $panels = array_flip($selection->panels);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($panels[$row[0]]),
        ));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tuples
     * @return array<string, array{0: string, 1: string}>
     */
    private function index(array $tuples): array
    {
        $indexed = [];

        foreach ($tuples as $tuple) {
            $indexed[$tuple[0]."\t".$tuple[1]] = $tuple;
        }

        return $indexed;
    }

    private function fingerprintMatches(string $expected, string $actual): bool
    {
        if (strlen($expected) !== strlen($actual)) {
            return false;
        }

        return hash_equals($expected, $actual);
    }
}
