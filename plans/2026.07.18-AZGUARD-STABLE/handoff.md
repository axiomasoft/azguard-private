# HANDOFF — 2026-07-18 — after P4.2

**Next:** `/task:plan-run 2026.07.18-AZGUARD-STABLE P4.8` — P4.8 = `Exec: manual` (sonnet/high),
ремедиация миграции 000005 (COALESCE morph-type-aware + down()-порядок MySQL, D30). ОБЯЗАН идти
первым из фиксов — MySQL-каскад «table already exists» маскирует нижележащие сбои (research/04 §3).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано Routing §3 (raw-SQL cross-driver корректность + снятие каскадов) |
| Context | continue (/clear) — ручной item |
| Суть | P4.8: COALESCE-fallback в 000005 сделать morph-type-aware (не хардкод `0`), починить порядок `down()` под MySQL FK-covering-index, продиагностировать ULID-truncation `model_id`; verify снятия PG-каскада (boolean-суперадмин + transaction-abort) и MySQL «table exists» |

```
/model sonnet
/effort high
/task:plan-run 2026.07.18-AZGUARD-STABLE P4.8
```

**Done:** P4.1 (Docker-стенд) 🟢. **P4.2 закрыт (🟢)** — БД-лейн-харнесс закоммичен (env-TestCase +
composer test:pgsql/test:mysql + union-doc + generalized rollback-тест) + фикстура
`ContextTableNameConfigTest` дополнена `expires_at` (R4, findings-anchors §5).

**Что сделано в P4.2:**
- Item-коммит `208943e` — 5 файлов (`tests/TestCase.php`, `composer.json`, `DEVELOPMENT.md`,
  `tests/Feature/ScopeClassMigrationRollbackTest.php`, `tests/Feature/Context/ContextTableNameConfigTest.php`),
  54 insertions/10 deletions.
- Харнесс: `TestCase::databaseConnectionConfig()` env-driven (sqlite дефолт, pgsql/mysql через
  `DB_CONNECTION`), composer-scripts `test:pgsql`/`test:mysql`, union-правило DEVELOPMENT.md,
  driver-agnostic assert в `ScopeClassMigrationRollbackTest` (`toThrow(QueryException::class)` без текста).
- Фикс R4: `createContextRolesTable()` получил `$table->timestamp('expires_at')->nullable()` —
  фикстура отставала от канона миграции 000011; `ContextPermissionLayer::apply` фильтрует по
  `expires_at`, custom-таблица без колонки ломала запрос.
- `.github/workflows/tests.yml` (CI-джоб `test-db-matrix`) НЕ закоммичен — остаётся в дереве до P4.10.
- Validation: `composer test` (sqlite) 667 passed/1774 assertions (HEAD 208943e); фильтры
  ScopeClassMigrationRollback (2 passed) и ContextTableNameConfig (3 passed) зелёные;
  `composer test:pgsql` — 660/667, 2 failed + 5 errors (документированный PG-каскад COALESCE/000005);
  `composer test:mysql` (запущен напрямую через `vendor/bin/pest`, composer-обёртка упирается в свой
  300s process-timeout на этом объёме) — воспроизводит документированный MySQL «table already exists»-
  каскад. Оба лейна ЗАПУСКАЮТСЯ и красны по ожиданию (baseline, не форсировались) — acceptance D31
  выполнен.

**Docker-стенд:** поднят на `PGSQL_PORT=25432`/`MYSQL_PORT=23306`/`REDIS_PORT=26379` (дефолтные заняты
локально). Для прогона лейнов исполнитель поднимает стенд (`make up`) и передаёт порты через env.

**Remaining:** P4.8 → P4.7 → P4.9 → P4.10 (portability) → P4.3–P4.6 → P5 → post-plan archive.

**Порядок исполнения (жёсткий, research/04 §3):** P4.8 ОБЯЗАН идти первым из фиксов — MySQL-каскад
«table exists» маскирует нижележащие сбои, чистая валидация R2/R3 на MySQL невозможна до фикса teardown
(R1). Оркестрация НЕ объявляется (общий docker-стенд, данные-сцепка).

**Sources of truth:** plan.md (v0.3.21, §4 P4=🟡 2/10, D30–D32, §3 Routing P4.7/P4.8/P4.9/P4.10) ·
phases/P4.md (P4.2 Completion Notes; P4.7 expand, P4.8/P4.9/P4.10 new; Phase Context — порядок+контракт) ·
research/04-p4.2-remediation.md (синтез) · findings/P4.2-remediation-anchors-2026-07-18.md (якоря) ·
findings/P4.2-db-portability-failures.md (исходный baseline сбоев) · roadmap.md (карта P4).

**Open risks:** utf8mb4_bin (P4.7) делает прод-MySQL case-sensitive — breaking для инсталов,
полагавшихся на схлопывание регистра (намеренно, fail-closed D10) → строка в UPGRADING (P4.10 deliverable).
Если после фиксов лейн всё ещё красный на реальном баге — §10, не continue-on-error.
`root/known-limitations.md` (12 пунктов) без изменений.

**Workarounds/Deferred/Open questions:** open_questions без изменений (Q1→D22, Q2→D23/D24, Q3→D27).
Deferred: коммит CI-джоба + полный green → P4.10; UPGRADING utf8mb4_bin-заметка → P4.10.
