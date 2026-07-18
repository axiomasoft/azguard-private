# HANDOFF — 2026-07-18 — after P4.1

**Next:** `/task:plan-exec 2026.07.18-AZGUARD-STABLE P4.2` — P4.1 (docker-стенд) закрыт;
Routing указывает P4.2 → sonnet/medium, `Exec = plan-exec` (БД-лейн CI+генерализация
хрупкого теста, каноны предписаны, открытых design-решений нет).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано Routing P4.2 (БД-лейн, механика без открытых решений) |
| Context | continue (/clear) — ручной item |
| Суть | Env-driven TestCase + composer test:pgsql/test:mysql + CI PG/MySQL-джобы + генерализация ScopeClassMigrationRollbackTest |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P4.2
```

**Done:** P4.1 (Docker-стенд: compose с реальными сервисами, D24) закрыт 🟢.

- `docker-compose.yml` — сервисы `postgres` (postgres:16-alpine), `mysql` (mysql:8),
  `redis` (redis:7-alpine), каждый с healthcheck (`pg_isready`/`mysqladmin ping`/
  `redis-cli ping`) и named volume; порты только `127.0.0.1`, конфигурируемы через
  `.env` (`PGSQL_PORT`/`MYSQL_PORT`/`REDIS_PORT`).
- `Makefile` — таргеты `up`/`down`/`logs`/`ps`.
- `.env.example` — креды/порты по умолчанию (5432/3306/6379); `.env` уже в `.gitignore`.
- `DEVELOPMENT.md` — раздел «Local database matrix».
- Валидация на хосте: все три сервиса `healthy` (порты переопределены через env — хост
  уже держит дефолтные 5432/3306/6379 под другими локальными проектами); health-команды
  `exit=0` для всех трёх; `make down` чисто убирает контейнеры/сеть (named volumes
  намеренно переживают down — данные, не мусор).

**Known Deviations:** — (P4.1 закрыт без material-отклонений; сервис назван `postgres`,
не `pgsql` из шаблона скилла `docker-postgres` — process-выбор для буквального
соответствия тексту Validation item'а, задокументирован в Completion Notes, статуса не
меняет).

**Remaining:** P4.2–P4.7 (БД-лейн · paratest · race-тесты C-05/C-14 · mutation-ratchet ·
чистка · collation MySQL) → P5 (шаблонизация дорожки → релиз v0.3.0+тег → миграция
root/→docs) → post-plan `/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Docs-sync:** обновлено — `DEVELOPMENT.md` (раздел «Local database matrix»).

**Lint:** `plan-lint.py plans/2026.07.18-AZGUARD-STABLE --baseline HEAD` → 0 ERROR / 1 WARN
(Update Log-запись P4.1 превышала 300 символов — укорочена) — новых от закрытия: 0 (на
bookkeeping-коммите).

**Sources of truth:** plan.md (v0.3.20, §4 P4=🟡 1/7) · phases/P4.md (P4.1 Completion
Notes) · docker-compose.yml · Makefile · .env.example · DEVELOPMENT.md · roadmap.md.

**Open risks:** без изменений от P3 — см. `root/known-limitations.md` (12 пунктов,
включая #10 «MySQL-ветки миграций не гонялись локально, верификация в P4.2/P4.7» — теперь
локальный MySQL-стенд для этого доступен через `make up`).

**Workarounds/Deferred/Open questions:** без изменений — `root/known-limitations.md` SSOT.
open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
