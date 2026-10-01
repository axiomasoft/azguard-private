<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Projects a CURATED map of ability => bool for the frontend (Inertia/Blade
 * shared props). Only the explicitly requested keys are evaluated and returned
 * — the full catalog is never dumped to the client. Swappable via
 * config('az-guard.abilities_resolver').
 *
 * @api
 */
interface AbilitiesResolver
{
    /**
     * @param  list<string>  $keys  Ability keys, short ('documents.view') or
     *                              full ('app.documents.view'); short keys are
     *                              scoped to $panelId.
     * @return array<string, bool> Keyed exactly by the requested keys.
     */
    public function forUser(Authenticatable $user, string $panelId, array $keys): array;
}
