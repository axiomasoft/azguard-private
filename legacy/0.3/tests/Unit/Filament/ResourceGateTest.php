<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\PermissionDiscovery;
use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Filament\Permissions\PermissionSubject;
use AzGuard\Filament\Permissions\ResourceGate;

const DEMO_MODEL = 'App\\Demo\\Post';

/**
 * @param  list<string>  $granted
 * @return array{0: ResourceGate, 1: object}
 */
function gateWith(array $granted): array
{
    $discovery = new class implements PermissionDiscovery
    {
        public function subjects(string $panelId): array
        {
            return [new PermissionSubject('Post', 'Posts', ['view_any', 'delete'], DEMO_MODEL)];
        }
    };

    $user = new class($granted)
    {
        /** @param list<string> $granted */
        public function __construct(private array $granted) {}

        public function hasPermission(string $key, string $panelId = 'app'): bool
        {
            return in_array($key, $this->granted, true);
        }
    };

    return [new ResourceGate('admin', new PermissionSchema, $discovery), $user];
}

it('defers for an unknown ability', function (): void {
    [$gate, $user] = gateWith([]);

    expect($gate->check($user, 'frobnicate', [DEMO_MODEL]))->toBeNull();
});

it('defers for an unmanaged model', function (): void {
    [$gate, $user] = gateWith([]);

    expect($gate->check($user, 'viewAny', ['App\\Other\\Thing']))->toBeNull();
});

it('grants viewAny when the user has the resource permission', function (): void {
    [$gate, $user] = gateWith(['admin.post.view_any']);

    expect($gate->check($user, 'viewAny', [DEMO_MODEL]))->toBeTrue();
});

it('defers (does not deny) viewAny when the user lacks the permission (union-only, C-08)', function (): void {
    [$gate, $user] = gateWith([]);

    // NOT false: a Gate::before hook returning false would short-circuit the
    // entire gate, denying even abilities a later policy could still grant.
    expect($gate->check($user, 'viewAny', [DEMO_MODEL]))->toBeNull();
});

it('maps bulk *Any actions to the singular permission', function (): void {
    [$gate, $user] = gateWith(['admin.post.delete']);

    expect($gate->check($user, 'deleteAny', [DEMO_MODEL]))->toBeTrue();
});
