<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\PermissionAuthority;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

final readonly class PolicyDecider
{
    public function __construct(private Container $container, private RuntimeInvoker $invoker, private NativeGateBinding $native) {}

    public function decide(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, PermissionAuthority $authority, bool $qualified = true): ?Decision
    {
        $binding = $catalog->policyBindings()[$request->permission()->local()] ?? null;

        if ($binding === null) {
            if ($authority === PermissionAuthority::Policy) {
                throw new RuntimeException('Required policy binding is unavailable.');
            }

            return null;
        }
        $definition = $catalog->get($request->permission());
        $inputs = ['user' => $frame->subjectModel(), 'resource' => $frame->resource(), 'resourceClass' => $binding->resourceModel ?? $definition->resourceModel, 'request' => $request, 'context' => $frame];

        if ($binding->kind === 'gate') {
            $resolved = $this->native->resolve($binding);
            $policy = $resolved['policy'];
            $callback = $resolved['callback'];
            $ability = $resolved['ability'];
            $component = $policy === null ? 'gate:'.$ability : $policy::class;
        } else {
            $class = $binding->policy ?? throw new RuntimeException('PHP policy binding is unavailable.');
            $policy = $this->container->make($class);
            $method = $catalog->bindingMethod($request->permission()->local());

            if ($method === null || ! is_callable([$policy, $method])) {
                throw new RuntimeException('Declared policy method is unavailable.');
            }
            $callback = [$policy, $method];
            $ability = $request->permission()->local();
            $component = $class;
        }
        $result = null;

        if (is_object($policy) && method_exists($policy, 'before')) {
            $result = $this->invoker->invoke(callback: [$policy, 'before'], inputs: ['ability' => $ability, ...$inputs]);
        }

        if ($result === null) {
            $result = $this->invoker->invoke(callback: $callback, inputs: $inputs);
        }
        $message = $status = $code = null;
        $response = $result instanceof Response;

        if ($response) {
            $message = $result->message();
            $status = $result->status();
            $code = $result->code();

            if ($code !== null && ! is_scalar($code)) {
                throw new RuntimeException('Policy Response details must be scalars.');
            }
            $result = $result->allowed();
        }

        if ($result !== null && ! is_bool($result)) {
            throw new RuntimeException('Policy must return bool, null or Response.');
        }

        if ($result === false || ($result === null && $authority === PermissionAuthority::Policy)) {
            return Decision::deny(reason: DecisionReason::Policy, state: $frame->state(), scope: $frame->scope(), component: $component, message: $message, status: $status, code: $code);
        }

        if ($authority === PermissionAuthority::Policy || ($qualified && $response)) {
            $reason = $authority === PermissionAuthority::Policy ? DecisionReason::Policy : ($frame->qualifiedSuperAdmin ? DecisionReason::SuperAdmin : DecisionReason::Granted);

            return Decision::allow(reason: $reason, state: $frame->state(), scope: $frame->scope(), component: $component, grants: $request->isTraced() ? $frame->matchingGrants() : [], message: $message, status: $status, code: $code);
        }

        return null;
    }
}
