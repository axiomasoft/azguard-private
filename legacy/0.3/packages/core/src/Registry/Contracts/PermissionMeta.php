<?php

declare(strict_types=1);

namespace AzGuard\Registry\Contracts;

/**
 * Permission metadata for UI, CLI and TypeScript generation.
 * Immutable value object.
 *
 * @api
 */
interface PermissionMeta
{
    public function label(): ?string;

    public function description(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
