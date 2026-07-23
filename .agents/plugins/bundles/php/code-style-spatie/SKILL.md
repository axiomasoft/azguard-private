---
name: code-style-spatie
bucket: php
version: 0.1.0
description: "Laravel/PHP code style per Spatie guidelines: Laravel conventions over PSR-12, typing instead of docblocks, early returns, happy path last."
risk: write
persona: oss-dev
tags: ["php", "laravel", "code-style", "spatie"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

## Контекст

Гайдлайны Spatie для Laravel/PHP-кода: применять при создании, правке, ревью и рефакторинге `.php` и `.blade.php` файлов — контроллеры, модели, роуты, конфиги, валидация, миграции, тесты. Главный принцип: **Laravel-конвенции прежде PSR-12** — если у Laravel есть документированный способ, используй его; отклоняйся только с явным обоснованием.

## Алгоритм

1. Определи артефакт (контроллер, роут, конфиг, модель, Blade, тест).
2. Открой `references/spatie-laravel-php-guidelines.md`, секции по теме артефакта.
3. Применяй: сначала Laravel-конвенцию, затем стандарты PHP, затем правила секции.
4. При конфликте с конвенциями конкретного проекта — следуй проекту, но консистентно.

### Ключевые правила

- Типизированные свойства, а не докблоки; явные return types, включая `void`.
- Короткий nullable: `?string`, не `string|null`.
- Constructor property promotion, когда все свойства можно продвинуть.
- Один трейт на один `use`.
- Early returns, избегай `else`; фигурные скобки всегда, даже для одного выражения.
- Happy path последним: сначала обработка ошибок, успех в конце.
- Интерполяция строк (`"Hi, {$name}"`) вместо конкатенации.
- Роуты: kebab-case URL, camelCase имена роутов и параметры, tuple-нотация `[Controller::class, 'method']`.
- Resource-контроллеры — во множественном числе (`PostsController`), только CRUD-методы; не-CRUD действия — отдельный контроллер.
- Валидация: массивная нотация правил (`'email' => ['required', 'email']`).
- `config()` вместо `env()` вне `config/`; сервисные конфиги — в `config/services.php`.
- Переводы: `__()`, не `@lang`.
- Enum-значения и константы классов — PascalCase.

### Don't

- Докблоки при полной типизации (кроме генериков/array shapes и случаев, где нужно описание).
- FQN в докблоках — всегда импортируй классы.
- `final` / `readonly` «по умолчанию».
- `else`, когда работают early returns; пробелы после Blade-директив (`@if($x)`, не `@if ($x)`).
- `down()`-методы в миграциях — только `up()`.

> **Конфликт со static-analysis.** Правило «не использовать `final` по умолчанию» противоречит `pint.json`-конфигу скилла `static-analysis` (правила `final_class` / `final_internal_class`). Это осознанная развилка: проект выбирает ОДНО из двух — либо Spatie-стиль без тотального `final`, либо Pint с `final_class` — и следует выбору консистентно по всей кодовой базе.

## Когда какой сниппет открывать

| Ситуация | Файл |
|:---|:---|
| Любая работа с PHP/Blade-кодом: полный свод правил (типизация, докблоки, control flow, роуты, конфиги, enum, Blade, валидация, naming) | `references/spatie-laravel-php-guidelines.md` |

Сниппетов нет — все детали в reference-файле.

## Чеклист качества

- [ ] Типизированы свойства, параметры и возвраты (включая `void`); лишних докблоков нет
- [ ] Нет `else` там, где возможны early returns; happy path последним
- [ ] Все control structures с фигурными скобками
- [ ] Роуты: kebab-case URL + camelCase имена + tuple-нотация
- [ ] `env()` только в `config/`; переводы через `__()`
- [ ] Правила валидации в массивной нотации
- [ ] Вопрос `final`/`readonly` согласован с конфигом Pint проекта (см. предупреждение выше)

## Ссылки

- `references/spatie-laravel-php-guidelines.md` — полный reference
- https://spatie.be/guidelines — первоисточник
- `general/naming-conventions` — именование *сущностей* (Action/Repository/DTO/VO/Enum, разбор `findByXOrFail`); этот скилл задаёт naming роутов/контроллеров, тот — классов и методов
- Скилл `static-analysis` (bucket php) — Pint/PHPStan/Rector конфиги, конфликт `final_class`
