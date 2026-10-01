<?php

declare(strict_types=1);

namespace AzGuard\Commands\Concerns;

use Illuminate\Support\Str;

trait ResolvesGuardNamespaces
{
    protected function guardBasePath(string $path, string $panel): string
    {
        return base_path(path: trim(string: $path, characters: '/').'/'.$panel);
    }

    protected function guardBaseNamespace(string $path, string $panel): string
    {
        $namespacePath = preg_replace(pattern: '/^app\b/i', replacement: 'App', subject: trim(string: $path, characters: '/'));

        return str_replace(search: '/', replace: '\\', subject: (string) $namespacePath).'\\'.$panel;
    }

    protected function domainNamespace(string $baseNamespace, string $domain): string
    {
        return $baseNamespace.'\\'.$domain;
    }

    protected function domainPath(string $basePath, string $domain): string
    {
        return $basePath.'/'.$domain;
    }

    protected function domainKey(string $domain): string
    {
        return Str::snake(value: $domain);
    }
}
