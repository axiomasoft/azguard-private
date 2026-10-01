<?php

declare(strict_types=1);

namespace AzGuard\Guard;

use AzGuard\Attributes\GuardPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/** @internal */
final class PolicyDiscovery
{
    /**
     * @return list<class-string>
     */
    public function discoverPolicyClasses(string $basePath, string $baseNamespace): array
    {
        if (! is_dir($basePath)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles(directory: $basePath) as $file) {
            if (! str_ends_with(haystack: $file->getFilename(), needle: 'Policy.php')) {
                continue;
            }

            $relativePath = $file->getRelativePathname();
            $class = $baseNamespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param  class-string  $policyClass
     */
    public function resolveModelClass(string $policyClass, string $basePath): ?string
    {
        $reflection = new ReflectionClass($policyClass);

        $attributes = $reflection->getAttributes(GuardPolicy::class);

        if ($attributes !== []) {
            /** @var GuardPolicy $attribute */
            $attribute = $attributes[0]->newInstance();

            return $attribute->model;
        }

        $modelsNamespace = rtrim(
            string: (string) config(key: 'az-guard.models_namespace', default: 'App\\Models\\'),
            characters: '\\',
        );

        $policyPath = $this->policyPath(policyClass: $policyClass);

        if ($policyPath === null) {
            return null;
        }

        $relativeToBase = Str::after(subject: $policyPath, search: $basePath.DIRECTORY_SEPARATOR);
        $segments = explode(separator: DIRECTORY_SEPARATOR, string: dirname(path: $relativeToBase));

        if ($segments[0] === '.' || $segments[0] === '') {
            $modelName = Str::replaceLast(search: 'Policy', replace: '', subject: class_basename(class: $policyClass));

            return class_exists("{$modelsNamespace}\\{$modelName}")
                ? "{$modelsNamespace}\\{$modelName}"
                : null;
        }

        $domain = $segments[0];
        $modelName = Str::singular(value: $domain);

        $modelClass = "{$modelsNamespace}\\{$domain}\\{$modelName}";

        return class_exists($modelClass) ? $modelClass : null;
    }

    /**
     * @param  class-string  $policyClass
     */
    private function policyPath(string $policyClass): ?string
    {
        $reflection = new ReflectionClass($policyClass);
        $filename = $reflection->getFileName();

        return is_string($filename) ? $filename : null;
    }
}
