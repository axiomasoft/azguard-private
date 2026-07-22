# RAG: Pest 4 parallel / paratest + Testbench DB-изоляция (2026-07-18)

> Слой 1. RAG-выжимка для детализации P4.3 (параллельные прогоны). Движок: perplexity-web.
> Вердикт: verified (внешний факт — механизм Pest/paratest/testbench). Дата: 2026-07-18.

## Запрос

«Pest 4 --parallel flag paratest Laravel Testbench package parallel database isolation
per-token 2025».

## Верифицированные факты (RAG:✅)

1. **Pest `--parallel` = фронтенд к `pest-plugin-parallel` → ParaTest.** Под капотом
   вызывает ParaTest CLI (`--processes`, `--order-by`), поднимает воркеры с env-переменными
   `PARATEST=1`, `TEST_TOKEN` (мелкий int на воркер, переиспользуется между прогонами),
   `UNIQUE_TEST_TOKEN` (уникальный per-run). Требует dev-dep `brianium/paratest`.
2. **Пакетный харнесс — `./vendor/bin/testbench package:test --parallel`** (Testbench
   ≥6.19), НЕ `php artisan test` (нет app-рантайма в пакете). Testbench включает
   Laravel-parallel-bootstrap внутри sandbox-app, с per-worker DB-setup.
3. **Laravel-parallel DB-именование дефолтит на `TEST_TOKEN`** (`*_test_1`, `*_test_2`…).
   `UNIQUE_TEST_TOKEN` — для более сильной изоляции (кросс-прогон/кросс-worktree), нужен
   кастомный resolver. Инвариант безопасности: имя БД обязано содержать `test` (защита от
   затирания не-тест-БД) — согласуется со скиллом `test-isolation-guard`.
4. **CI-паттерн 2025:** host-sharding (`--shard N/M` matrix) × `--parallel` внутри шарда;
   ParaTest-токены работают одинаково в шардированном прогоне.

## Ключевой вывод для azguard (repo-grounded наложение)

- **SQLite `:memory:` изолирован ПО ПРОЦЕССУ автоматически** (phpunit.xml:48-49,
  TestCase.php:28-32): каждый parallel-воркер получает СВОЮ in-memory БД — для sqlite-лейна
  parallel-изоляция БЕСПЛАТНА, кастомный resolver НЕ нужен. `RefreshDatabase` на `:memory:`
  безопасен параллельно.
- **Реальные БД (PG/MySQL, лейн P4.2)** — общий сервер: parallel требует per-token имён БД
  (`TEST_TOKEN`) ЛИБО прогон реального лейна последовательно (`--processes=1`) при малой
  матрице. Дешёвый honest-путь: parallel только на sqlite-лейне (быстрый дефолт), реальный
  БД-лейн — последовательный (изоляция важнее скорости, D10 fail-closed). Per-token resolver
  для реальных БД — opt-in follow-up, не обязателен для 3 воркеров CI.

## Источники

- downing.tech/posts/pest-parallel-testing-is-now-available
- github.com/pestphp/pest/issues/1053
- rias.be/blog/using-laravel-parallel-testing-inside-your-package-tests
- laravel-news.com/pest-4-5-0 · laravel-news.com/everything-we-know-about-pest-4
- reddit r/laravel «Stop your parallel agents fighting over one test DB» (UNIQUE_TEST_TOKEN)
