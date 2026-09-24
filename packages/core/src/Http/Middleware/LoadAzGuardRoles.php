<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
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

        if ($user instanceof Model && method_exists(object_or_class: $user, method: 'roles')) {
            $relations = ['roles'];
            $user->loadMissing(relations: $relations);
        }

        return $next($request);
    }
}
