<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;
use AzGuard\Attributes\Internal\DescribesPermissionCheck;
use AzGuard\Laravel\Internal\Http\Middleware\CheckPermission as CheckPermissionMiddleware;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use UnitEnum;

/**
 * Requires a permission for a controller action: `azguard.can:{permission}[,{on}]` middleware the router adds.
 *
 * On a class it covers every action, narrowed by `only` and `except` as Laravel controller middleware. `on` names
 * the route parameter that holds the resource or the assignment scope. A denial answers with `status` and
 * `message` (by default 403 and the standard message) and never explains the decision. On Laravel 13.0–13.4 a parent
 * controller attribute is still applied by `azguard.panel`, because the router does not walk parent classes there.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0')]
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
