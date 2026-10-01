<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Registry\Values\PermissionSet;
use BackedEnum;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Public contract for the permission-checking surface of an AzGuard user.
 *
 * Mirrors the {@see \AzGuard\Concerns\HasPermissions} trait 1:1 — declare this
 * interface on your User model and `use` the trait to satisfy it with no extra
 * code. Type-hint this (or the composite {@see AzGuardUser}) instead of a trait,
 * which cannot be type-hinted.
 *
 * @api
 */
interface HasPermissions
{
    public function hasPermission(string|UnitEnum $permission, string|BackedEnum|null $panelId = null, ?PermissionContext $context = null): bool;

    public function hasPermissionIn(string $contextType, int|string $contextId, string|UnitEnum $permission, string|BackedEnum|null $panelId = null): bool;

    public function checkPermission(string|UnitEnum $permission, string|BackedEnum|null $panelId = null, ?PermissionContext $context = null): bool;

    public function permissionSet(string|BackedEnum|null $panelId = null): PermissionSet;

    /** @return Collection<int, string> */
    public function permissions(string|BackedEnum|null $panelId = null): Collection;

    public function isSuperAdmin(string|BackedEnum|null $panelId = null): bool;

    public function flushPermissions(string|BackedEnum|null $panelId = null): void;

    public function hasContextGuard(): bool;
}
