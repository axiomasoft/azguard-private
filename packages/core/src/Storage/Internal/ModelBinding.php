<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use Illuminate\Database\Eloquent\Model;
use ReflectionProperty;

/**
 * The binding of a storage model to its storage. The models are an SPI that hosts extend, so they carry no storage
 * member: the binding lives in a private slot that only this class reaches, and it follows `newInstance()` (hydration,
 * replication) and `clone` with the instance.
 */
final class ModelBinding
{
    /** The classes that declare the private slot, one per model kind. */
    private const array BASES = [RoleGrant::class, PermissionGrant::class, Permission::class];

    public static function bind(Model $model, Storage $storage, string $table): Model
    {
        self::slot($model)->setValue($model, $storage);
        $model->setConnection($storage->connectionName());
        $model->setTable($table);

        return $model;
    }

    /** @throws StorageMismatchException when the model was not obtained through its storage */
    public static function storageOf(Model $model): Storage
    {
        $storage = self::slot($model)->getValue($model);

        return $storage instanceof Storage ? $storage : throw new StorageMismatchException('Model must be obtained through Storage::model().');
    }

    private static function slot(Model $model): ReflectionProperty
    {
        return new ReflectionProperty(self::base($model), 'boundStorage');
    }

    /** @return class-string<Model> */
    private static function base(Model $model): string
    {
        foreach (self::BASES as $base) {
            if ($model instanceof $base) {
                return $base;
            }
        }

        throw new StorageMismatchException('Model '.$model::class.' is not a storage model.');
    }
}
