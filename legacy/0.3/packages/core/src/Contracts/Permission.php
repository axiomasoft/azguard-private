<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

/**
 * A class-based permission, for open or multi-module permission sets where a
 * closed enum would be too rigid (every added/removed case is a breaking change
 * for exhaustive matches in consumer code).
 *
 * Reference it by its class-string anywhere a permission is accepted, exactly
 * like an enum case:
 *
 *   final class UpdatePost implements Permission
 *   {
 *       public static function ability(): string
 *       {
 *           return 'posts.update';
 *       }
 *   }
 *
 *   $user->can(UpdatePost::class);
 *   $user->hasPermission(UpdatePost::class, 'app');   // -> "app.posts.update"
 *
 * The returned ability is the bare (unscoped) key; the owning panel prefixes it
 * just like an enum case value.
 *
 * @api
 */
interface Permission
{
    /**
     * The bare permission key (without the panel prefix), e.g. "posts.update".
     */
    public static function ability(): string;
}
