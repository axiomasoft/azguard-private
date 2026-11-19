<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\Internal\DoctorStorages;
use AzGuard\Sources\Database\Internal\StorageHealth;
use AzGuard\Storage\Internal\StorageRegistry;
use Packman\Attributes\Internal;
use Throwable;

/**
 * `storage.migrated`: every table of every selected storage exists. A storage the doctor cannot reach is an error,
 * never a skipped check.
 *
 * @internal
 */
#[Internal]
final readonly class StorageMigrated implements DoctorCheck
{
    public function __construct(private ?StorageRegistry $storages = null) {}

    public function key(): string
    {
        return 'storage.migrated';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach (DoctorStorages::of($context, $this->storages) as $storage) {
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
