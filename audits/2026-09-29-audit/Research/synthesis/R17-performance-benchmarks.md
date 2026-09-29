# R17 — Производительность и измерения

**Статус:** предварительный Perplexity synthesis, сохранён из этой сессии. Вопрос сформулирован на уровне области, без точных фрагментов текущего кода. Советы о локальном AzGuard использовать только после сверки с [репозиторием](../repository-inventory.json) и [первичными источниками](../primary-sources.md). Пересечения с другими темами смотрите в [карте исследования](../README.md).

## Исходный вопрос

Benchmark design for Laravel permission engine with DB revision each check, global counter write contention, role/source query fanout, catalog filtering, wildcard matching, warm/cold cache and Filament listings. Query-count budgets versus real p95/p99 latency, batch decisions, replica constraints and N+1. Compare global revision versus scoped revision only after evidence. Reproducible workloads and minimally intrusive diagnostics.

## Ответ Perplexity

Исследовать следует не «сколько SQL-запросов на проверку», а поведение целого пути решения доступа под реалистичной нагрузкой: задержки p95/p99, пропускную способность, конкуренцию за revision fence и корректность после изменений прав. Ниже — исследовательский план на 2026-09-29; частные репозитории и его исходники я не проверял.

## Зафиксированные факты

- Laravel 12 документирует DB::listen() для наблюдения за выполненными запросами и их временем, а также whenQueryingForLongerThan() для реакции на накопленное время SQL за запрос. В конфигурации read/write Laravel опция sticky направляет последующие чтения на write-соединение после записи в рамках текущего запроса; это не равнозначно гарантиям консистентности реплики между запросами. Источник: Laravel 12.x — Database, документация на дату обращения 2026-09-29.
- PostgreSQL 18 описывает блокировку строки при конкурентном обновлении; pg_locks помогает инспектировать активные блокировки. Это обосновывает проверку contention, если конкретная реализация revision fence обновляет одну общую строку. Это не доказывает, что данная библиотека вообще выполняет такое обновление на каждом check. Источник: , страница текущей документации, проверена 2026-09-29.
- Filament 5 указывает, что dot-notation для relationship-колонок автоматически eager-load’ит связи, рекомендует учитывать стоимость очень больших страниц и поддерживает отложенную загрузку таблиц. Это нельзя экстраполировать на все пользовательские колонки, фильтры, policy callbacks или версии Filament 2–4. Источник: , проверена 2026-09-29.
- Для генерации нагрузки полезен постоянный целевой throughput: документация wrk2 объясняет, что нагрузка по модели «запрос после ответа» может недоучесть хвостовые задержки из-за coordinated omission. Источник: , версия master, дата страницы не указана; проверено 2026-09-29.

## План нагрузок и метрики

Сначала закрепить воспроизводимую матрицу: PHP/Laravel и драйвер БД, СУБД и версия, конфигурация connection pool, топология primary/replica/cache, число worker’ов, CPU/RAM, размеры таблиц и индексы, а также точные commit/package versions. Задать фиксированные наборы субъектов, permissions, ролей, источников, contexts и resource IDs; сохранить seed генератора и шаблоны сценариев.
Сценарии измерять отдельно и в смешанном профиле: одиночные решения; пакетная проверка множества действий для одного субъекта; wildcard hit/miss и глубина/ширина шаблонов; catalog filtering по разным селективностям; cache warm, cold, истёкший envelope и массовый cache miss; Filament-страница с обычной и максимальной допустимой пагинацией, relationship-колонками, фильтрами и проверками действий. Включить параллельные записи, меняющие роли или grants: измерить write latency и влияние на check latency.
Для каждого сценария зафиксировать throughput, error rate, p50/p95/p99, время приложения и SQL, запросы на HTTP-request и на batch, число вызовов authorization API, cache hit/miss/expiry, ожидание соединения, блокировки и нагрузку primary. Query budget полезен как регрессионный ограничитель, но одинаковое число запросов не означает одинаковую стоимость: один индексный lookup и сканирование/ожидание блокировки радикально различаются. p95/p99 при фиксированном throughput — главные критерии пользовательской задержки; query count — диагностический сигнал, не замена latency.

## Архитектурные выводы и открытые вопросы

Вывод: сравнивать global revision и scoped revision стоит только после базового профиля, который показывает узкое место. Сначала проверить, сколько времени занимает сам revision read, где он выполняется, cache invalidation и source/role fanout. Если write path сериализуется на одной горячей строке, scoped revision может распределить contention, но добавит сложность инвалидации и риск пропустить зависимость между scopes. Требование эксперимента — доказать эквивалентность решений и отсутствие устаревшего allow после каждого типа изменения, а не только выигрыш throughput.
Batching: проверить bulk API как отдельную гипотезу: меньше повторных чтений revision и общих загрузок context/roles, но сохранить индивидуальные результаты и границы tenant/context. Важно сравнить один batch с N одиночными вызовами при одинаковых решениях, включая холодный кэш и непопулярные ключи.
Replica: если revision читается с асинхронной реплики, возможна задержка видимости записи. Измерить stale-allow окно после отзыва права; зафиксировать, какие reads обязаны идти на primary и хватает ли внутрипроцессного sticky при последовательности запросов. Документация Laravel не обещает межзапросную read-after-write консистентность.
Минимально intrusive diagnostics: включать listener выборочно по sampling/feature flag; собирать нормализованный SQL fingerprint, connection, duration, request trace, счётчики cache и authorization calls, но не SQL bindings с персональными данными. Сопоставлять с pg_locks/ожиданиями БД и трассировать query fanout по операции. Отдельно сравнить latency с диагностикой включённой и выключенной.
Неизвестно без кода и профиля: реальная семантика revision fence, пакетная поверхность API, cache-store, источники ролей, DB isolation и поддерживаемые версии Filament. Поэтому конкретные бюджеты запросов и утверждение о выигрыше scoped revision пока не обоснованы.

Sources:
1. [Laravel 12.x — Database, документация на дату обращения 2026-09-29](https://laravel.com/framework/docs/12.x/database)
2. [PostgreSQL 18 — Explicit Locking](https://www.postgresql.org/docs/current/explicit-locking.html)
3. [Filament 5.x — Tables overview](https://filamentphp.com/docs/5.x/tables/overview)
4. [wrk2 README](https://github.com/giltene/wrk2/blob/master/README.md)
