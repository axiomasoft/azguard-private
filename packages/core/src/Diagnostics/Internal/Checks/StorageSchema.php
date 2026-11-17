<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\Internal\DoctorStorages;
use AzGuard\Exceptions\Storage\StorageMismatchException;
use AzGuard\Sources\Database\Internal\StorageHealth;
use AzGuard\Storage\Internal\StorageRegistry;
use Packman\Attributes\Internal;
use Throwable;

/**
 * `storage.schema`: the stored `storage_state` matches the configuration of each selected storage and the host key
 * columns have the type of its host keys. Columns of the own models are checked by the database source of the panel.
 *
 * @internal
 */
#[Internal]
final readonly class StorageSchema implements DoctorCheck
{
    public function __construct(private ?StorageRegistry $storages = null) {}

    public function key(): string
    {
        return 'storage.schema';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach (DoctorStorages::of($context, $this->storages) as $storage) {
            $scope = 'storage:'.$storage->id();

            try {
                $storage->verifySchema();
            } catch (StorageMismatchException $error) {
                $previous = $error->getPrevious();

                yield $previous === null
                    ? DoctorFinding::error($this->key(), $error->getMessage(), $scope, ['code' => $error->code()])
                    : DoctorFinding::error($this->key(), 'Storage '.$storage->id().' cannot read storage_state ('.$previous::class.'); the schema cannot be verified.',
                        $scope, ['code' => $error->code(), 'exception' => $previous::class]);

                continue;
            } catch (Throwable $error) {
                yield DoctorFinding::error($this->key(), 'Storage '.$storage->id().' cannot be reached ('.$error::class.'); the schema cannot be verified.',
                    $scope, ['exception' => $error::class]);

                continue;
            }

            try {
                $mismatches = StorageHealth::hostKeyMismatches($storage);
            } catch (Throwable $error) {
                yield DoctorFinding::error($this->key(), 'Storage '.$storage->id().' cannot list its columns ('.$error::class.').', $scope, ['exception' => $error::class]);

                continue;
            }

            if ($mismatches !== []) {
                yield DoctorFinding::error($this->key(), 'Storage '.$storage->id().' declares '.$storage->hostKeys().' host keys, but the columns '
                    .implode(', ', array_map(static fn (array $column): string => $column['column'].' ('.$column['type'].')', $mismatches))
                    .' do not hold them.', $scope, ['host_keys' => $storage->hostKeys(), 'columns' => array_column($mismatches, 'column')]);
            }
        }
    }
}
