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
    public function __construct(private Container $container, private RuntimeInvoker $invoker) {}

    public function decide(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, PermissionAuthority $authority): ?Decision
    {
        $class = $catalog->bindings()[$request->permission()->local()] ?? null;

        if ($class === null) {
            return null;
        }
        $policy = $this->container->make($class);
        $method = $catalog->bindingMethod($request->permission()->local());

        if ($method === null || ! is_callable([$policy, $method])) {
            throw new RuntimeException('Declared policy method is unavailable.');
        }
        $result = $this->invoker->invoke([$policy, $method], ['user' => $frame->subjectModel(), 'resource' => $frame->resource(), 'request' => $request, 'context' => $frame]);

        if ($result instanceof Response) {
            $result = $result->allowed();
        }

        if ($result !== null && ! is_bool($result)) {
            throw new RuntimeException('Policy must return bool, null or Response.');
        }

        if ($result === false || ($result === null && $authority === PermissionAuthority::Policy)) {
            return Decision::deny(DecisionReason::Policy, $frame->state(), $frame->scope(), $class);
        }

        return null;
    }
}
