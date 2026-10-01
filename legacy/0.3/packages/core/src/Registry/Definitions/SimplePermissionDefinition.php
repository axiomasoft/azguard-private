<?php

declare(strict_types=1);

namespace AzGuard\Registry\Definitions;

use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Contracts\PermissionMeta;
use Override;

/**
 * Generic, source-agnostic {@see PermissionDefinition}.
 *
 * Built directly from a resolved key (and optional group/meta) rather than
 * from an enum case or policy attribute — used by config- and
 * database-driven catalog sources (e.g. the Filament resource schema).
 */
final readonly class SimplePermissionDefinition implements PermissionDefinition
{
    public function __construct(
        private string $key,
        private string $panelId,
        private ?string $group = null,
        private ?PermissionMeta $meta = null,
        private bool $dynamic = false,
    ) {}

    #[Override]
    public function key(): string
    {
        return $this->key;
    }

    #[Override]
    public function shortKey(): string
    {
        $prefix = $this->panelId.'.';

        return str_starts_with($this->key, $prefix)
            ? substr($this->key, strlen($prefix))
            : $this->key;
    }

    #[Override]
    public function panelId(): string
    {
        return $this->panelId;
    }

    #[Override]
    public function group(): ?string
    {
        return $this->group;
    }

    #[Override]
    public function label(): ?string
    {
        return $this->meta()->label();
    }

    #[Override]
    public function meta(): PermissionMeta
    {
        return $this->meta ?? new SimplePermissionMeta;
    }

    #[Override]
    public function isDynamic(): bool
    {
        return $this->dynamic;
    }
}
