<?php

declare(strict_types=1);

use AzGuard\Registry\Definitions\SimplePermissionMeta;

it('exposes optional label and description', function (): void {
    $empty = new SimplePermissionMeta;
    $full = new SimplePermissionMeta('Posts', 'View posts');

    expect($empty->label())->toBeNull()
        ->and($empty->description())->toBeNull()
        ->and($empty->toArray())->toBe(['label' => null, 'description' => null])
        ->and($full->label())->toBe('Posts')
        ->and($full->description())->toBe('View posts')
        ->and($full->toArray())->toBe(['label' => 'Posts', 'description' => 'View posts']);
});
