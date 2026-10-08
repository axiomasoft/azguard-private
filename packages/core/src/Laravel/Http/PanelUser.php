<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Http;

use AzGuard\Panels\Panel;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Model;

/**
 * The authenticated user of a request for a panel: the first user of the panel guards, otherwise of the default guard.
 *
 * @internal
 */
final readonly class PanelUser
{
    public function __construct(private Auth $auth) {}

    /**
     * The user, or null for a guest and for a user that is not an Eloquent model.
     */
    public function of(?Panel $panel): ?Model
    {
        foreach ($panel?->guards() ?: [null] as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user !== null) {
                // Larastan types user() as the model of the configured provider; a custom guard returns any Authenticatable.
                return $user instanceof Model ? $user : null; // @phpstan-ignore instanceof.alwaysTrue
            }
        }

        return null;
    }
}
