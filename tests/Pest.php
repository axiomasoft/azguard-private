<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class)->in('Unit', 'Feature', 'Regression', 'Engines', 'Acceptance', 'Contracts');

uses(BootsFilament::class)->group('filament')->afterEach(fn () => FilamentFixture::reset())->in('Feature/Filament');

uses()->group('crm')->beforeEach(fn () => CrmWorld::seed())
    ->afterEach(function (): void {
        CrmWorld::resetRuntime();
        Carbon::setTestNow();
        Relation::morphMap([], false);
    })->in('Acceptance/Crm');

// `stand` marks every test that needs an external service (database engines, replica, Redis): ParaTest honours only
// the first --exclude-group, so the parallel run excludes this one umbrella group.
uses()->group('stand')->beforeEach(function (): void {
    if (! in_array(env('DB_CONNECTION', 'sqlite'), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Engine suite requires PostgreSQL, MySQL or MariaDB.');
    }
})->in('Engines');

uses()->beforeEach(function (): void {
    Relation::morphMap(['user' => User::class], false);
    RuntimePolicy::$result = true;
    RuntimePolicy::$callback = null;
    RuntimePolicy::$calls = 0;
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('department')->nullable();
        $table->boolean('is_root')->default(false);
    });
    User::query()->insert(['id' => 1, 'department' => 'sales']);
})->afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
})->in('Feature/Authorization');
