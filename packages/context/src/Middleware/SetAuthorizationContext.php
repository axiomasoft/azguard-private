<?php

declare(strict_types=1);

namespace AzGuard\Context\Middleware;

use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\Contracts\ResolvesContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: azguard.context
 *
 * Iterates over the registered ResolvesContext resolvers,
 * calls resolve($request) and sets the context on the manager.
 *
 * The 'azguard.context' alias is auto-registered by
 * AzGuardContextServiceProvider::boot() — no manual wiring in
 * bootstrap/app.php is needed.
 *
 * Applying it to a route:
 *   Route::middleware('azguard.context')->group(function () { ... });
 */
final readonly class SetAuthorizationContext
{
    /**
     * @param  iterable<ResolvesContext>  $resolvers
     */
    public function __construct(
        private AuthorizationContextManager $manager,
        private iterable $resolvers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->resolvers as $resolver) {
            $context = $resolver->resolve($request);

            if ($context !== null) {
                $this->manager->set($context);
            }
        }

        return $next($request);
    }
}
