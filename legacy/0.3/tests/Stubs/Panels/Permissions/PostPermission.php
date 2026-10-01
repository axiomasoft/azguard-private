<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Panels\Permissions;

final class PostPermission
{
    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            'post.view' => 'viewAny',
            'post.create' => 'create',
        ];
    }
}
