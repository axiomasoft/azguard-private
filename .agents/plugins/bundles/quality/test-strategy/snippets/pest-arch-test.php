<?php

// Optional examples: enable only the architecture invariants adopted by this project.
// Do not impose Action/DTO/readonly layers or a CI order from this template alone.

// Source: a Laravel package test suite — tests/Arch/ArchTest.php (anonymized)
// Pest Arch: executable architectural invariants. Replace 'App' on root
// namespace project/package. Runs separately suite, without database, first step CI.

declare(strict_types=1);

use Illuminate\Http\Request;

arch('all code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('debug-helpers do not leak into production')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal();

arch('value objects readonly')
    ->expect('App\ValueObjects')
    ->toBeReadonly();

arch('DTO readonly')
    ->expect('App\Data')
    ->toBeReadonly();

arch('domain events readonly')
    ->expect('App\Events')
    ->toBeReadonly();

arch('contracts are interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

arch('actions do not depend on HTTP-request')
    ->expect('App\Actions')
    ->not->toUse(Request::class);

arch('repositories do not depend on HTTP-request')
    ->expect('App\Repositories')
    ->not->toUse(Request::class);

// Extensions to suit the project's taste:
// ->expect('App\Enums')->toBeEnums();
// ->expect('App\Models')->toExtend(Illuminate\Database\Eloquent\Model::class);
// arch()->preset()->php();   // built-in presets Pest (php/security/laravel)
