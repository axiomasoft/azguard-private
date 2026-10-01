<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\FilamentPermissionCatalogBuilder;
use AzGuard\Filament\Permissions\PermissionDiscovery;
use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Filament\Permissions\PermissionSubject;

function fakeDiscovery(PermissionSubject ...$subjects): PermissionDiscovery
{
    return new class($subjects) implements PermissionDiscovery
    {
        /** @param list<PermissionSubject> $subjects */
        public function __construct(private array $subjects) {}

        public function subjects(string $panelId): array
        {
            return $this->subjects;
        }
    };
}

it('supports only its configured panel', function (): void {
    $builder = new FilamentPermissionCatalogBuilder('admin', new PermissionSchema, fakeDiscovery());

    expect($builder->supports('admin'))->toBeTrue()
        ->and($builder->supports('app'))->toBeFalse();
});

it('builds definitions only for its panel', function (): void {
    $discovery = fakeDiscovery(new PermissionSubject('Post', 'Posts', ['view_any', 'create']));
    $builder = new FilamentPermissionCatalogBuilder('admin', new PermissionSchema, $discovery);

    expect($builder->build('app'))->toBe([]);

    $definitions = $builder->build('admin');

    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->key())->toBe('admin.post.view_any')
        ->and($definitions[0]->group())->toBe('Posts')
        ->and($definitions[1]->key())->toBe('admin.post.create');
});
