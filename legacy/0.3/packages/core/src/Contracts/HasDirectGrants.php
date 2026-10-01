<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Models\DirectGrant;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use UnitEnum;

/**
 * Public contract for direct (per-user, role-bypassing) grants.
 *
 * Mirrors the {@see \AzGuard\Concerns\HasDirectGrants} trait 1:1. Opt in by
 * declaring this interface and `use`-ing the trait alongside {@see AzGuardUser}.
 *
 * @api
 */
interface HasDirectGrants
{
    /** @return MorphMany<DirectGrant, Model> */
    public function directGrants(): MorphMany;

    /** @return Collection<int, DirectGrant> */
    public function grants(string|BackedEnum $panelId): Collection;

    public function hasGrant(string|UnitEnum $permission, string|BackedEnum|null $panelId = null): bool;

    public function grant(string|UnitEnum $permission, string|BackedEnum $panelId, ?DateTimeInterface $expiresAt = null): static;

    public function revoke(string|UnitEnum $permission, string|BackedEnum $panelId): static;
}
