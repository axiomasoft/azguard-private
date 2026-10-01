<?php

declare(strict_types=1);

namespace AzGuard\Registry\Definitions;

use AzGuard\Registry\Contracts\PermissionMeta;
use Override;

/**
 * Simple immutable implementation of PermissionMeta.
 */
final readonly class SimplePermissionMeta implements PermissionMeta
{
    public function __construct(
        private ?string $label = null,
        private ?string $description = null,
    ) {}

    #[Override]
    public function label(): ?string
    {
        return $this->label;
    }

    #[Override]
    public function description(): ?string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
