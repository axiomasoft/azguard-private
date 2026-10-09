<?php

declare(strict_types=1);

namespace AzGuard\Filament\Support;

use AzGuard\Exceptions\InvalidIdentityException;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal The key of a stored model as an identity id; Eloquent types it as mixed.
 */
final class ModelKey
{
    /**
     * @throws InvalidIdentityException when the model is not saved or its key is not an int or a string
     */
    public static function of(Model $model): int|string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidIdentityException($model::class.' has no key: save the model first.');
        }

        return $key;
    }
}
