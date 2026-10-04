# HANDOFF — 2026-10-04 — after P3.5

**Next:** run-items: task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.1

Фаза P4 детализирована (D14): пятнадцать пунктов — номера досье P4.1–P4.12, срез-review P4.13, Review P4 P4.14, CRM-фикстура P4.15. Порядок исполнения — execution sheet `roadmap.md`: `P4.1` → `P4.2 P4.3` → `P4.4 P4.5` → `P4.6 P4.7` → `P4.15` → `P4.13` → `P4.8 P4.9` → `P4.10 P4.11` → `P4.12` → `P4.14`. P4.1 работает на SQLite; СУБД поднимаются с P4.4 (`docker compose up -d --wait postgres mysql mariadb`), Redis — с P4.8; недоступный сервис — `unavailable`, не GREEN (D4).

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high |
| Capabilities | PHP 8 + composer vendor; SQLite (СУБД с P4.4, Redis с P4.8) |
| Context | continue-root |
| Essence | `Authorizer::decide()`: пайплайн 09 §2, authority dispatcher, `Restriction`/`GrantCondition`, `EvaluationFrame`, `PolicyDecider`, свойства V15 |

**Done:** P4 design: `phases/P4/P4.md` (Phase Context, Contract, Assurance v1, Acceptance, Validation), item-контракты P4.1–P4.15, D14 (состав и порядок, `Authorizer` как точка входа, владельцы швов: `PolicyDecider`, поля отказа `Decision`, трасса, граница по умолчанию, `FencesReads` и цикл fence в P4.4, `FiltersQueries`/`AssignmentScopeSelection`, `touch()`, CRM-фикстура P4.15, публичный `Storage::own()`; расхождения досье: FormerKeys не alias, `NotGrantable` из хранилища, проверка внутри транзакции хоста, V27 у P4.10, `toGateResult` в `GateBridge`, P03 → P4.11), `brief/P4-dossier-decisions.md` (выдержки D14–D20, D24–D27, D29, D31, D44, D48, D52, D53, D55, D59–D69, D74–D84), строки Routing и execution sheet.
**Remaining:** исполнение P4.1–P4.15 по execution sheet; детализация P5–P8 по фазам после закрытия предыдущей (D3).
**Sources of truth:** phases/P4/P4.md · decisions/D14-p4-authorization-closure.md · roadmap.md (execution sheet) · brief/P4-dossier-decisions.md
**Open risks:** design-context/finalize-design P4 выполнены исправленным Task runtime (ветка `fix/task-repeat-historical-phase-scoped` в swissknifeman): установленный runtime отвергает историю повторов P2.8/P2.5 (`REPEAT_HISTORY_DESIGN_UNQUALIFIED`, затем `REPEAT_SIBLING_STALE`) при любом построении статусов плана — до слияния исправления `plan-run` P4 на штатном runtime упадёт той же ошибкой. Находки среза P4.13 и Review P4.14 исправляются в owning items; механизм (repair-пункт как D7 или owning repeat как D11) выбирает владелец после verdict. Полный plan-lint сохраняет 12 исторических write-site ошибок P0/P1.
**Workarounds/Deferred/Open questions:** Решения D14 п.4, которые владелец может пересмотреть: FormerKeys не runtime-alias (поздний слой 19 §6/D80 против строки V10); проверка внутри транзакции хоста не ошибка, а обход кэша (09 §14 против 09 §8). Push запрещён.
