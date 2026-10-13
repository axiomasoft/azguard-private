<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class)->in('Unit', 'Feature', 'Modules', 'Regression', 'Engines', 'Acceptance', 'Contracts');

/*
 * Zone groups from tests/zones.json: `zone:<name>` for the zone a test directory belongs to, `database` for the
 * directories whose result depends on the database engine (the only ones the PostgreSQL, MySQL and MariaDB lanes run).
 */
$zoneMap = json_decode((string) file_get_contents(__DIR__.'/zones.json'), true, flags: JSON_THROW_ON_ERROR);

foreach ($zoneMap['zones'] as $zoneName => $zone) {
    foreach ($zone['tests'] as $zoneTests) {
        pest()->group("zone:{$zoneName}")->in($zoneTests);
    }
}

// `database:<zone>` is the part of a zone that depends on the engine: `--group=database:storage` selects exactly it.
foreach ($zoneMap['database'] as $databaseTests) {
    pest()->group('database')->in($databaseTests);

    foreach ($zoneMap['zones'] as $zoneName => $zone) {
        foreach ($zone['tests'] as $zoneTests) {
            if ($databaseTests === $zoneTests || str_starts_with($databaseTests, $zoneTests.'/') || str_starts_with($zoneTests, $databaseTests.'/')) {
                pest()->group("database:{$zoneName}")->in($databaseTests);
            }
        }
    }
}

uses(BootsFilament::class)->group('filament')->afterEach(fn () => FilamentFixture::reset())->in('Feature/Filament');

uses()->group('crm')->beforeEach(fn () => CrmWorld::seed())
    ->afterEach(function (): void {
        CrmWorld::resetRuntime();
        Carbon::setTestNow();
        Relation::morphMap([], false);
    })->in('Acceptance/Crm');

// `stand` marks every test the parallel SQLite run leaves out: those that need an external service (database engines,
// replica, Redis) and the `extended` ones (scale, nightly). The run excludes this one umbrella group. `server-skip` marks every test the parallel run of a server driver leaves out: those
// of `stand` and the SQLite-only ones. ParaTest honours only the first --exclude-group, so each run excludes one group.
uses()->group('stand', 'server-skip')->beforeEach(function (): void {
    if (! in_array(env('DB_CONNECTION', 'sqlite'), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Engine suite requires PostgreSQL, MySQL or MariaDB.');
    }
})->in('Engines');

// Doctor reports a subject model without a morph alias (panels.valid); the fixture panels declare theirs like an
// application does.
uses()->beforeEach(fn () => Relation::morphMap(['user' => User::class, 'vendor' => Vendor::class], false))
    ->afterEach(fn () => Relation::morphMap([], false))
    ->in('Feature/Diagnostics');

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
})->in('Feature/Authorization', 'Modules/Scopes/Properties');
