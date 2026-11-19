<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Gate;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Exceptions\Changes\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Internal\PanelResolver;
use AzGuard\Support\ModelKey;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Throwable;

/**
 * Authoritative Laravel Gate adapter; null is reserved for foreign abilities.
 */
#[Api(since: '1.0')]
final readonly class GateBridge
{
    #[Internal('built by the container')]
    public function __construct(private Container $container) {}

    /** @param array<mixed> $arguments */
    public function __invoke(?object $user, string $ability, array $arguments = []): ?Response
    {
        $owned = false;

        try {
            $resolver = $this->container->make(PanelResolver::class);
            $registry = $this->container->make(PanelRegistry::class);
            $authorizer = $this->container->make(Authorizer::class);
            $subject = $user instanceof Model || $user instanceof SubjectRef ? $user : null;
            $resource = $arguments[0] ?? null;
            $model = $resource instanceof Model ? $resource::class
                : (is_string($resource) && is_subclass_of($resource, Model::class) ? $resource : null);
            $owner = $resolver->owner($ability, $subject, $model);

            if ($owner === null) {
                return null;
            }
            $owned = true;

            if ($subject === null) {
                throw new InvalidArgumentException('Owned abilities require an accepted subject.');
            }
            $catalog = $registry->catalog($owner->id());
            $qualified = str_contains($ability, ':') || ($owner->prefix() !== null && str_starts_with($ability, $owner->prefix().'.'));
            $dynamicLocal = ! $qualified && str_contains($ability, '.') && ! $catalog->has($ability) && $catalog->isDynamic();
            $permission = $ability;

            if (! str_contains($ability, '.') && ! str_contains($ability, ':')) {
                if ($model === null || $catalog->abilityIsAmbiguous($model, $ability)) {
                    throw new InvalidArgumentException('The model ability is ambiguous. Use an explicit permission name.');
                }
                $permission = $catalog->permissionForAbility($model, $ability)
                    ?? throw new InvalidArgumentException('Model permission is unavailable.');
            }
            [$panel, $key] = $authorizer->resolve($subject, $permission);
            $definition = $catalog->find($key);

            if ($model !== null && $definition?->resourceModel !== null && ! is_a($model, $definition->resourceModel, true)) {
                throw new InvalidArgumentException('Resource class does not match the permission binding.');
            }
            $ref = $subject instanceof SubjectRef ? $subject : SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject));
            $context = $arguments[1] ?? null;
            $request = AccessRequest::for($ref, $key);

            if ($subject instanceof Model) {
                $request = $request->withSubjectModel($subject);
            }

            if ($context instanceof AccessScope) {
                $request = $request->inScope($context, is_object($resource) ? $resource : null);
            } else {
                $context = $context instanceof ProvidesAssignmentScope ? $context->azguardAssignmentScope() : $context;

                if ($context !== null && ! $context instanceof AssignmentScopeRef) {
                    throw new InvalidArgumentException('Context must be an assignment scope or an access scope.');
                }
                $request = $request->on($context, is_object($resource) ? $resource : null);
            }

            try {
                return self::toGateResult($authorizer->decide($panel, $request));
            } catch (UnknownPermissionException $error) {
                if ($dynamicLocal) {
                    return null;
                }

                throw $error;
            }
        } catch (Throwable $error) {
            Log::warning('AzGuard Gate evaluation failed.', ['exception' => $error::class, 'owned' => $owned]);

            return Response::deny(code: 'gate_error');
        }
    }

    public static function toGateResult(Decision $decision): Response
    {
        $response = $decision->allowed()
            ? Response::allow($decision->message, $decision->code)
            : Response::deny($decision->message, $decision->code ?? $decision->reason->value);

        return $response->withStatus($decision->status);
    }
}
