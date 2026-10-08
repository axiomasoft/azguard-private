<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;
use AzGuard\Attributes\Concerns\DescribesPermissionCheck;
use AzGuard\Laravel\Http\Middleware\CheckPermission as CheckPermissionMiddleware;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use UnitEnum;

// One class name on every supported Laravel version: from Laravel 13 the router applies the attribute itself as
// controller middleware; before it `azguard.panel` reads the attribute and adds the same `azguard.can` middleware.
if (class_exists(Middleware::class)) {
    /**
     * Requires a permission for a controller action: `azguard.can:{permission}[,{on}]` middleware the router adds.
     *
     * On a class it covers every action, narrowed by `only` and `except` as Laravel controller middleware. `on` names
     * the route parameter that holds the resource or the assignment scope. A denial answers with `status` and
     * `message` (by default 403 and the standard message) and never explains the decision.
     *
     * @api
     */
    #[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    final class CheckPermission extends Middleware
    {
        use DescribesPermissionCheck;

        /**
         * @param  list<string>|null  $only
         * @param  list<string>|null  $except
         */
        public function __construct(
            public UnitEnum|string $permission,
            public ?string $on = null,
            public int $status = 403,
            public ?string $message = null,
            ?array $only = null,
            ?array $except = null,
        ) {
            parent::__construct(CheckPermissionMiddleware::using($permission, $on), $only, $except);
        }
    }
} else {
    /**
     * Requires a permission for a controller action: `azguard.can:{permission}[,{on}]` middleware that
     * `azguard.panel` adds, since this Laravel version has no controller middleware attributes.
     *
     * On a class it covers every action, narrowed by `only` and `except` as Laravel controller middleware. `on` names
     * the route parameter that holds the resource or the assignment scope. A denial answers with `status` and
     * `message` (by default 403 and the standard message) and never explains the decision.
     *
     * @api
     */
    #[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    final class CheckPermission
    {
        use DescribesPermissionCheck;

        public string $middleware;

        /**
         * @param  list<string>|null  $only
         * @param  list<string>|null  $except
         */
        public function __construct(
            public UnitEnum|string $permission,
            public ?string $on = null,
            public int $status = 403,
            public ?string $message = null,
            public ?array $only = null,
            public ?array $except = null,
        ) {
            $this->middleware = CheckPermissionMiddleware::using($permission, $on);
        }
    }
}
