<?php

declare(strict_types=1);

namespace AzGuard\Registry\Contracts;

/**
 * Immutable descriptor of a single permission from the catalog.
 * The source of truth is always PHP code (enum, attribute).
 *
 * @api
 */
interface PermissionDefinition
{
    /**
     * Fully-resolved key: "app.documents.view"
     */
    public function key(): string;

    /**
     * Short key without the panel prefix: "documents.view"
     */
    public function shortKey(): string;

    /**
     * Panel ID: "app", "admin"
     */
    public function panelId(): string;

    /**
     * Group for UI/CLI: "Documents", "Dashboard"
     */
    public function group(): ?string;

    /**
     * Human-readable label for UI/CLI: "View Documents".
     * Null when the source provides none — consumers fall back to key().
     */
    public function label(): ?string;

    /**
     * Metadata for Filament / TypeScript generation.
     */
    public function meta(): PermissionMeta;

    /**
     * Whether the key is a dynamic pattern such as app.team.{id}.admin.
     */
    public function isDynamic(): bool;
}
