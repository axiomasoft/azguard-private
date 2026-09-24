<?php

// Optional examples: enable only the architecture invariants adopted by this project.
// Do not impose Action/DTO/readonly layers or a CI order from this template alone.

// Source: a Laravel package test suite — tests/Arch/ArchTest.php (anonymized)
// Pest Arch: исполняемые архитектурные инварианты. Заменить 'App' на корневой
// namespace проекта/пакета. Запускается отдельным suite, без БД, первым шагом CI.

declare(strict_types=1);

use Illuminate\Http\Request;

arch('весь код объявляет strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('debug-хелперы не утекают в прод')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('actions финальны')
    ->expect('App\Actions')
    ->toBeFinal();

arch('value objects readonly')
    ->expect('App\ValueObjects')
    ->toBeReadonly();

arch('DTO readonly')
    ->expect('App\Data')
    ->toBeReadonly();

arch('доменные события readonly')
    ->expect('App\Events')
    ->toBeReadonly();

arch('контракты — это интерфейсы')
    ->expect('App\Contracts')
    ->toBeInterfaces();

arch('actions не зависят от HTTP-запроса')
    ->expect('App\Actions')
    ->not->toUse(Request::class);

arch('repositories не зависят от HTTP-запроса')
    ->expect('App\Repositories')
    ->not->toUse(Request::class);

// Расширения по вкусу проекта:
// ->expect('App\Enums')->toBeEnums();
// ->expect('App\Models')->toExtend(Illuminate\Database\Eloquent\Model::class);
// arch()->preset()->php();   // встроенные пресеты Pest (php/security/laravel)
