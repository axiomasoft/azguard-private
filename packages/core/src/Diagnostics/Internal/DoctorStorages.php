<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal;

use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Storage\Internal\Storage;
use AzGuard\Storage\Internal\StorageRegistry;
use Illuminate\Container\Container;
use Packman\Attributes\Internal;
use Throwable;
use WeakMap;

/**
 * Associates resolved internal {@see Storage} handles with a {@see DoctorContext} without exposing `\Internal\` types in public signatures.
 */
#[Internal]
final class DoctorStorages
{
    /** @var WeakMap<DoctorContext, list<Storage>>|null */
    private static ?WeakMap $map = null;

    /** @param list<Storage> $storages */
    public static function attach(DoctorContext $context, array $storages): DoctorContext
    {
        self::store()->offsetSet($context, $storages);

        return $context;
    }

    public static function propagate(DoctorContext $from, DoctorContext $to): DoctorContext
    {
        $store = self::store();

        if ($store->offsetExists($from)) {
            $store->offsetSet($to, $store->offsetGet($from));
        }

        return $to;
    }

    /** @return list<Storage> */
    public static function of(DoctorContext $context, ?StorageRegistry $registry = null): array
    {
        $store = self::store();

        if ($store->offsetExists($context)) {
            return $store->offsetGet($context);
        }

        $registry ??= Container::getInstance()->bound(StorageRegistry::class)
            ? Container::getInstance()->make(StorageRegistry::class)
            : null;

        if (! $registry instanceof StorageRegistry) {
            return [];
        }

        $resolved = [];
        foreach ($context->storages() as $descriptor) {
            try {
                $resolved[] = $registry->get($descriptor->id);
            } catch (Throwable) {
                continue;
            }
        }

        return $resolved;
    }

    /** @return WeakMap<DoctorContext, list<Storage>> */
    private static function store(): WeakMap
    {
        if (! self::$map instanceof WeakMap) {
            /** @var WeakMap<DoctorContext, list<Storage>> $map */
            $map = new WeakMap;
            self::$map = $map;
        }

        return self::$map;
    }
}
