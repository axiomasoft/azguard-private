<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Tests\Stubs\CustomDirectGrant;
use AzGuard\Tests\Stubs\CustomRole;

it('resolves RoleResource model through Config', function (): void {
    config(['az-guard.models.role' => CustomRole::class]);

    expect(RoleResource::getModel())->toBe(CustomRole::class);
});

it('resolves DirectGrantResource model through Config', function (): void {
    config(['az-guard.models.direct_grant' => CustomDirectGrant::class]);

    expect(DirectGrantResource::getModel())->toBe(CustomDirectGrant::class);
});

it('defaults to package models when config is unchanged', function (): void {
    expect(RoleResource::getModel())->toBe(Config::roleModel())
        ->and(DirectGrantResource::getModel())->toBe(Config::directGrantModel());
});
