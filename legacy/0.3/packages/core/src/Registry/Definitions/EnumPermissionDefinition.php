<?php

declare(strict_types=1);

namespace AzGuard\Registry\Definitions;

use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Contracts\PermissionMeta;
use Override;
use UnitEnum;

/**
 * PermissionDefinition built from a backed enum case.
 *
 * Example:
 *   DocumentsPermission::View → key "app.documents.view"
 */
final readonly class EnumPermissionDefinition implements PermissionDefinition
{
    public function __construct(
        private string $resolvedKey,
        private string $panelId,
        private ?string $group,
        private PermissionMeta $meta,
        private string $enumClass,
        private string $caseName,
    ) {}

    /**
     * Create from an enum case and a panel.
     * The resolvePermission logic comes from Panel::resolvePermission().
     */
    public static function fromCase(UnitEnum $case, string $panelId, string $resolvedKey, ?string $group = null): self
    {
        return new self(
            resolvedKey: $resolvedKey,
            panelId: $panelId,
            group: $group ?? self::inferGroupFromClass($case::class),
            meta: new SimplePermissionMeta(
                label: self::formatLabel($case->name),
            ),
            enumClass: $case::class,
            caseName: $case->name,
        );
    }

    #[Override]
    public function key(): string
    {
        return $this->resolvedKey;
    }

    #[Override]
    public function shortKey(): string
    {
        $prefix = $this->panelId.'.';

        return str_starts_with($this->resolvedKey, $prefix)
            ? substr($this->resolvedKey, strlen($prefix))
            : $this->resolvedKey;
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
        return $this->meta->label();
    }

    #[Override]
    public function meta(): PermissionMeta
    {
        return $this->meta;
    }

    #[Override]
    public function isDynamic(): bool
    {
        return false;
    }

    public function enumClass(): string
    {
        return $this->enumClass;
    }

    public function caseName(): string
    {
        return $this->caseName;
    }

    /**
     * Infer the group from the enum class name: DocumentsPermission → Documents
     */
    private static function inferGroupFromClass(string $enumClass): string
    {
        $short = class_basename($enumClass);

        return str_ends_with($short, 'Permission')
            ? substr($short, 0, -strlen('Permission'))
            : $short;
    }

    /**
     * Format a label from a PascalCase case name: ViewAny → View Any
     */
    private static function formatLabel(string $caseName): string
    {
        return trim((string) preg_replace('/([A-Z])/', ' $1', $caseName));
    }
}
