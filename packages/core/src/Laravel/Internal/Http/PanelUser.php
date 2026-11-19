<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Internal\Http;

use AzGuard\Panels\Panel;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;

/**
 * The authenticated user of a request for a panel: the first user of the panel guards, otherwise of the default guard.
 *
 * @internal
 */
#[Internal]
final readonly class PanelUser
{
    public function __construct(private Auth $auth) {}

    /**
     * The user, or null for a guest and for a user that is not an Eloquent model.
     */
    public function of(?Panel $panel): ?Model
    {
        $guards = $panel?->guards() ?? [];

        foreach ($guards !== [] ? $guards : [null] as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user instanceof Model) {
                return $user;
            }
        }

        return null;
    }
}
