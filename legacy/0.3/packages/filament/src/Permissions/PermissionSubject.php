<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

/**
 * One thing that owns permissions in a Filament panel — a Resource (backed by
 * a model) or a Page. Produced by discovery, consumed by {@see PermissionSchema}.
 */
final readonly class PermissionSubject
{
    /**
     * @param  string  $name  raw subject name used to build the key segment, e.g. "Post"
     * @param  string  $label  human group label for the UI, e.g. "Posts"
     * @param  list<string>  $abilities  ability slugs, e.g. ['view_any', 'view', ...]
     * @param  class-string|null  $model  backing model class (resources only), for gate mapping
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $abilities,
        public ?string $model = null,
    ) {}
}
