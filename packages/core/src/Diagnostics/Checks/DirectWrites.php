<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;

/**
 * `direct_writes`: every model of a storage extends its base model, whose write methods refuse a direct write.
 * A raw SQL write is outside this check and outside the contract of the storage.
 *
 * @internal
 */
final readonly class DirectWrites implements DoctorCheck
{
    /** @var array<string, class-string> */
    private const array BASES = ['role_grant' => RoleGrant::class, 'permission_grant' => PermissionGrant::class, 'permission' => Permission::class];

    public function key(): string
    {
        return 'direct_writes';
    }

    public function run(DoctorContext $context): iterable
    {
        $models = [];
        foreach ($context->config()->defaultModels() as $kind => $class) {
            $models[$kind.' '.$class] = [$kind, $class, 'core'];
        }

        foreach ($context->panels() as $panel) {
            foreach ($panel->attachedSources() as $source) {
                if ($source instanceof DatabaseSource) {
                    foreach ($source->storageModels() as $kind => $class) {
                        $models[$kind.' '.$class] ??= [$kind, $class, 'panel:'.$panel->id()];
                    }
                }
            }
        }
        ksort($models, SORT_STRING);

        foreach ($models as [$kind, $class, $scope]) {
            yield from self::check($kind, $class, $scope);
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(string $kind, string $class, string $scope = 'core'): array
    {
        $base = self::BASES[$kind] ?? null;

        if ($base !== null && is_a($class, $base, true)) {
            return [];
        }

        return [DoctorFinding::error('direct_writes', 'The '.$kind.' model '.$class.' does not extend '.($base ?? 'a storage model')
            .', so nothing refuses its direct writes.', $scope, ['model' => $class])];
    }
}
