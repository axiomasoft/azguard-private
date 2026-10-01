<?php

declare(strict_types=1);

namespace AzGuard\Commands\Concerns;

trait ResolvesUserModel
{
    protected function resolveUserModelClass(): string
    {
        /** @var string|null $option */
        $option = $this->option('model');

        return ($option !== null && $option !== '')
            ? $option
            : (string) config('auth.providers.users.model', 'App\Models\User');
    }
}
