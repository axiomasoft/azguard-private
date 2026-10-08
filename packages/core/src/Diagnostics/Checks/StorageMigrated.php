<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Sources\Database\StorageHealth;
use Throwable;

/**
 * `storage.migrated`: every table of every selected storage exists. A storage the doctor cannot reach is an error,
 * never a skipped check.
 *
 * @internal
 */
final readonly class StorageMigrated implements DoctorCheck
{
    public function key(): string
    {
        return 'storage.migrated';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->storages() as $storage) {
            $scope = 'storage:'.$storage->id();

            try {
                $missing = StorageHealth::missingTables($storage);
            } catch (Throwable $error) {
                yield DoctorFinding::error($this->key(), 'Storage '.$storage->id().' cannot be reached ('.$error::class.'); its migrations cannot be verified.',
                    $scope, ['exception' => $error::class]);

                continue;
            }

            if ($missing !== []) {
                yield DoctorFinding::error($this->key(), 'Storage '.$storage->id().' lacks the tables '.implode(', ', $missing)
                    .' (prefix "'.$storage->prefix().'"): publish and run the AzGuard migrations.', $scope, ['tables' => $missing]);
            }
        }
    }
}
