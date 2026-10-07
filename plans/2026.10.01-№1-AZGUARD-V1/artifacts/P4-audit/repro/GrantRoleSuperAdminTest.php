<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

beforeEach(function (): void {
    Relation::morphMap(['user' => User::class], false);
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    User::query()->insert(['id' => 1]);
});
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('A02 a direct Grant carrying a super-admin role key qualifies far beyond its own pattern', function (): void {
    // Third-party ProvidesGrants source: a direct grant for orders.view only, with role provenance "root" (#[SuperAdmin]).
    $grant = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', AccessScope::in(TenantRef::global()), role: RoleKey::of('admin', 'root'));
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [$grant]));

    $view = $engine->decide($panel, $request);
    $edit = $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.edit')));
    dump(['view' => [$view->allowed(), $view->reason->value], 'edit' => [$edit->allowed(), $edit->reason->value]]);

    // "прямой Grant — своим паттерном": orders.edit is not covered by the orders.view grant.
    expect($edit->allowed())->toBeFalse();
});
