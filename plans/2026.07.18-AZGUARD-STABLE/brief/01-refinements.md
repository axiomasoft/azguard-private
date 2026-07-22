# 01 — Уточнения владельца (refinements)

## 2026-07-18 — Гейт P0.6: утверждение бэклога ремедиации

Владелец (Dmitry Vostrikov) на блокирующем гейте P0.6 (сводка: 44 находки — 1 Blocker /
18 Major / 24 Minor / 1 Nit; дублей 0; re-rated 0) ответил на 4 бинарных вопроса:

1. **Волны P1 — утверждены**: W0={C-01}, W1=12 Major (порядок security→cache→docs:
   C-13, C-11, C-10, C-08, C-02, C-03, C-04, C-05, B-04, B-01, A-05, D-06),
   W2=14 Minor+Nit. Без правок.
2. **Отклонения — подтверждены**: D-02 (recon историчен), D-07 и D-08 (маршрут P4 —
   штатный скоуп фазы, в бэклоге P1/P2 не дублируются). Blocker'ов среди отклонённых нет.
3. **Кластеры P2 — подтверждены**: 6 предписанных ТЗ + 3 добавленных синтезом
   (Testing DX `AzGuard::fake()`, headless-порог, контрактные швы baseline) — итого 9.
4. **Спорные партиции — оставлены в P1-W1**: B-04, C-02, C-08, C-11 (аддитивные/локальные
   фиксы без переименований и редизайна грамматики).

Следствие: findings/REGISTER.md и research/02-backlog.md утверждены как вход детализации
P1/P2 (D3). Запись решения — plan.md §5 D9. Q1 (версия тега) и Q2 (docker-матрица) гейтом
НЕ решались — остаются Decision pending (open-questions.md).

## 2026-07-18 — Детализация P2: разрешение развилок редизайна

При детализации фазы P2 (структурный канон + fluent/DX редизайн) владелец на 4 несущие
развилки (grant-грамматика/builders, TTL context-грантов, headless-порог, `AzGuard::fake()`)
дал сквозное указание: **«важна идеальная структура; на данный момент возможны любые
изменения, даже ломающие совместимость; применять любые лучшие решения»** (повтор акцента
брифа п.7 «полная свобода изменений» + п.9 «идеальный fluent, современные best practices»).

Практическое следствие — все развилки разрешены в пользу канона/идеала (plan.md §5 D14–D18):
grant = immutable-with единый fluent-корень core↔context (D16); TTL-парность context (D16);
`AzGuard::fake()` строим в 0.3.0 (D14); headless = doc-only minimal-setup, рантайм panel-less
НЕ строим — fail-closed приоритетнее (D14); wildcard-флип на Hierarchical сейчас (D18); cut-line
фасада исполняет P3. Синтез альтернатив — research/03-p2-canon.md §0; Обсуждение §4–§7 Resolved.

## 2026-07-18 — Детализация P1: принцип ремедиации

Владелец при детализации фазы P1 задал сквозной принцип для всех нагруженных находок:
**«максимальная надёжность, fail-closed по умолчанию, ничего не ослаблять»** (до-1.0
свобода это позволяет). Практическое следствие для «либо/либо»-рекомендаций осей: выбирается
СТРОГИЙ вариант как дефолт, opt-out оставляется явным (не дефолтным). Запись решений —
plan.md §5 D10 (единый контракт query-scope: bypass убран, конфиг `scope.on_missing_panel`
дефолт `exception`), D12 (строгие варианты C-13/C-08/C-10/C-11/C-04). Уточнение относится к
брифу п.2 «максимально стабильный/надёжный API» и п.3 «максимальная надёжность».

## 2026-07-18 — Детализация P5: scope релиза (split отложен)

Recon release-инфраструктуры (findings/P5-rag-release-guard-2026-07-18.md) показал:
монорепо приватный (`axiomasoft/azguard-private`), split-репо `axioma-studio/azguard-*`
не созданы, `MONOREPO_SPLIT_TOKEN` не настроен, Packagist не подключён — one-time setup
из RELEASING.md не выполнялся; тег уронил бы split.yml. Владелец на вопрос о scope релиза
выбрал: **«тег + GH Release, split отложить»** — P5.2 ограничивается тегом v0.3.0 +
GH Release + CHANGELOG в приватном репо; split.yml нейтрализуется guard'ом (repo-переменная
`SPLIT_ENABLED`), публикация split/Packagist — отдельный follow-up вне плана, когда владелец
решит открывать пакет. Запись решения — plan.md §5 D25; механика закрытия/архивации — D26.
Относится к брифу п.8 (финал — тег новой версии).

## 2026-07-21 — Полный переход исполнения на ChatGPT/Codex

Владелец уточнил, что Claude Code больше не будет использоваться и простая смена названий
моделей недостаточна: остаток плана должен быть подготовлен как самостоятельный Codex-run
с явным переносом контекста, командами, моделями, effort, review-гейтами и экономией.

Следствие: все незавершённые items P4/P5 исполняются только GPT-5.6 Luna/Terra/Sol через
Codex и `task@swissknifeman`; обязательный контракт —
`research/05-codex-execution-contract.md`. Исторические Claude-записи закрытых items не
переписываются. Dirty diff P4.8 принимается только как недоверенный вход и повторно
валидируется Codex. Решение зафиксировано в plan.md D34.

## 2026-07-21 — P4.8: минимальная классификация без расширения migration-scope

После двухкоммитного P4.8 migration-fix и независимого review две PG wildcard-assertions
остались красными. Владелец требует не расширять уже выполненный P4.8 и не открывать
широкий redesign P4: только минимальный отдельный follow-up, который честно подтверждает
fixture-классификацию или эскалирует runtime/P3-вопрос. Следствие — P4.11 меняет лишь
два test fixtures при строгих PG assertions; никакой production/API/SemVer правки без
нового D-решения.

## 2026-07-22 — P4.10: минимальное восстановление валидаторов

Полный clean proof P4.10 обнаружил два новых, независимых блокера после P4.12: security
architecture запрещает `sha1()` в private index-name helper, а ожидаемое исключение rollback
теста отравляет PostgreSQL transaction `RefreshDatabase`. Владелецский принцип остаётся
неизменным: не ослаблять architecture/test gates, не переписывать provenance закрытых items и
не принимать CI/docs до честного full green. Следствие — два минимальных follow-up items
P4.13/P4.14 (D38), затем только повтор P4.10.

## 2026-07-22 — P4.14: не ослаблять контракт ожидаемого исключения

Владелец отдельно потребовал не считать `QueryException` заранее обязательным: сравнить
сохранение класса с driver-neutral assertion по фактическому тесту, Laravel API и потребителям.
Решение D39: класс сохраняется, потому что это явный внутренний контракт negative proof и
общий cross-driver helper уже применяет `pgsql`-only savepoint seam. PostgreSQL получает
savepoint recovery, SQLite/MySQL остаются на исходном direct seam; `Throwable`/message/SQLSTATE
assertion не вводится. Изменения остаются test-only, P3/SemVer не затрагиваются.

## 2026-07-22 — P4.10: ULID failure must preserve the production migration contract

После P4.14 clean PostgreSQL proof обнаружил `SQLSTATE[22P02]`: random-order suite использовал
уже мигрированную integer-схему до запуска `MorphTypeTestCase`, хотя его ULID override корректно
живёт в раннем Testbench environment hook. Владелецский принцип — не «чинить» production
`MorphColumns`/migration из-за test static-state timing и не расширять глобальный Pest harness.
Следствие D40/P4.15: применить supported class-local Testbench
`ResetRefreshDatabaseState`, затем только P4.10 повторяет clean union proof и принимает
CI/docs/B6.
