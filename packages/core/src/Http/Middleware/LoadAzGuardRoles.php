<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LoadAzGuardRoles
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && method_exists(object_or_class: $user, method: 'roles')) {
            // @phpstan-ignore larastan.relationExistence (The optional roles relation is checked above on the concrete user model.)
            $user->loadMissing(relations: 'roles');
        }

        return $next($request);
    }
}
