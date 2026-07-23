---
name: laravel-best-practices
bucket: php
version: 0.1.0
description: "Laravel best practices: 20 rule categories (Eloquent, validation, cache, queues, security) with routing by task."
risk: write
persona: oss-dev
tags: ["php", "laravel", "best-practices"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Laravel Best Practices

## Контекст

Мета-скилл для написания, ревью и рефакторинга Laravel-кода: контроллеры, модели,
миграции, Form Request, политики, джобы, шедулер, сервисы и Eloquent-запросы.
Сами правила лежат в 20 файлах `references/` — каждый учит «что делать и почему»
с примерами Incorrect/Correct. SKILL.md только маршрутизирует: открывай лишь те
файлы, которые относятся к текущей задаче.

**Laravel Boost**: в проектах с установленным Boost используй его встроенный скилл laravel-best-practices — он версионно-специфичен и обновляется с пакетом; этот скилл — для проектов без Boost. Пакет: https://github.com/laravel/boost (скиллы — `vendor/laravel/boost/.ai/`).

**Золотое правило — Consistency First.** Перед применением любого правила посмотри,
как уже делает приложение. Laravel допускает несколько валидных подходов — лучший
тот, который кодовая база уже использует, даже если другой паттерн теоретически
лучше. Несогласованность хуже субоптимального паттерна. Проверь соседние файлы
(родственные контроллеры, модели, тесты): если паттерн есть — следуй ему, не вводи
второй способ. Правила из `references/` — это дефолты для случаев, когда паттерна
ещё нет, а не способ переписать существующий.

## Алгоритм

1. Определи тип задачи/файла (миграция, контроллер, джоба, тест…).
2. По таблице ниже выбери релевантные файлы правил и прочитай их; не грузи все 20.
3. Проверь соседние файлы проекта на устоявшиеся паттерны — Consistency First,
   они приоритетнее правил.
4. Применяй правила: для каждого есть объяснение «почему» и пример кода.
5. Точный синтаксис API сверяй с документацией установленной версии Laravel
   (Boost `search-docs`, если доступен).
6. Пройди чеклист качества перед завершением.

## Когда какой файл правил открывать

| Задача / симптом | Файл правил |
|---|---|
| Пишешь Eloquent-запрос; жалоба на медленную страницу, N+1 | `references/db-performance.md` |
| Подзапросы, агрегаты, сортировка по has-many, сложные выборки | `references/advanced-queries.md` |
| Авторизация, mass assignment, SQL-инъекции, XSS, загрузка файлов, секреты | `references/security.md` |
| Кешируешь данные; гонки за кеш, stale-while-revalidate | `references/caching.md` |
| Модель: связи, скоупы, касты, даты, имена таблиц | `references/eloquent.md` |
| Новая валидация, Form Request, условные правила | `references/validation.md` |
| `env()`/`config()`, проверки окружения, магические строки | `references/config.md` |
| Пишешь или чинишь тесты, фабрики, фейки | `references/testing.md` |
| Джоба в очередь: retry, timeout, уникальность, батчи | `references/queue-jobs.md` |
| Роуты, контроллеры, model binding, толстый контроллер | `references/routing.md` |
| Запросы к внешним API: таймауты, retry, пулы, фейки | `references/http-client.md` |
| События, слушатели, нотификации, транзакции + очередь | `references/events-notifications.md` |
| Отправка писем, mailable, тесты почты | `references/mail.md` |
| Исключения: report/render, логирование, JSON для API | `references/error-handling.md` |
| Задачи по расписанию: overlap, мульти-сервер, окружения | `references/scheduling.md` |
| Структура кода: Action-классы, DI, интерфейсы, defer, Context | `references/architecture.md` |
| Новая миграция, индексы, foreign keys, rollback | `references/migrations.md` |
| Обработка коллекций, итерация больших выборок | `references/collections.md` |
| Blade: компоненты, слоты, composer'ы, фрагменты | `references/blade-views.md` |
| Нейминг, хелперы Str/Arr/Number, читаемый синтаксис | `references/style.md` |

Типовые комбинации: миграция → migrations + db-performance; контроллер → routing +
validation + security; модель → eloquent + db-performance; джоба с внешним API →
queue-jobs + http-client.

## Чеклист качества

- [ ] Проверены соседние файлы — новый код не вводит второй способ делать то же самое
- [ ] Нет N+1: связи загружены через `with()`, счётчики через `withCount()`
- [ ] Каждое действие авторизовано (policy/gate), модели имеют `$fillable`/`$guarded`
- [ ] В контроллер попадает только `$request->validated()`, не `$request->all()`
- [ ] `env()` только в config-файлах; секреты не в коде
- [ ] Очереди/события внутри транзакций — с `afterCommit`/`ShouldDispatchAfterCommit`
- [ ] HTTP-клиент: явные таймауты, retry, в тестах `Http::fake()` + `preventStrayRequests()`
- [ ] Миграции: индексы на колонках WHERE/ORDER BY/JOIN, одна забота на миграцию
- [ ] Нейминг соответствует конвенциям Laravel (`references/style.md`)

## Ссылки

- `references/db-performance.md` — производительность БД, N+1, chunk, индексы
- `references/advanced-queries.md` — продвинутые паттерны запросов
- `references/security.md` — безопасность
- `references/caching.md` — кеширование
- `references/eloquent.md` — паттерны Eloquent
- `references/validation.md` — валидация и Form Request
- `references/config.md` — конфигурация
- `references/testing.md` — тестирование
- `references/queue-jobs.md` — очереди и джобы
- `references/routing.md` — роутинг и контроллеры
- `references/http-client.md` — HTTP-клиент
- `references/events-notifications.md` — события и нотификации
- `references/mail.md` — почта
- `references/error-handling.md` — обработка ошибок
- `references/scheduling.md` — планировщик задач
- `references/architecture.md` — архитектура
- `references/migrations.md` — миграции
- `references/collections.md` — коллекции
- `references/blade-views.md` — Blade и представления
- `references/style.md` — конвенции и стиль
