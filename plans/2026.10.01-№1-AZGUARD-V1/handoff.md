# HANDOFF — 2026-10-02 — after P2

**Next:** run-items: task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.1 P2.2 P2.3

| Parameter | Meaning |
|:--|:--|
| Batch | B2a |
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue-root |
| Essence | Первая группа P2: панель, builder с происхождением записей, реестр и заморозка (P2.1), единое правило выбора панели и префиксы (P2.2), порядок настроек (P2.3) |

````
/task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.1 P2.2 P2.3
````

**Done:** P0 и P1 закрыты GREEN. P2 детализирована по досье и коду HEAD `3e55618`: D8 фиксирует состав по замыканию зависимостей, порядок по номерам досье `P2.1 → … → P2.10` с распределением проверок, которым нужен более поздний код, владение методами `PanelBuilder`/`Panel` по пунктам, отложенные возможности источников и решения по пробелам досье; добавлены P2.9 (кадры областей, D6) и P2.10 (Review P2); Phase Context скелетов P3–P6 принимает отложенное по D8.
**Remaining:** B2a (`P2.1 P2.2 P2.3`) → B2b (`P2.4 P2.5 P2.6`) → B2c (`P2.7 P2.8 P2.9`) → `P2.10` (Review P2, свежая сессия) → `task:plan-design 2026.10.01-№1-AZGUARD-V1 P3`, далее P3–P8 по D3.
**Sources of truth:** plans/2026.10.01-№1-AZGUARD-V1/plan.md · phases/P2/P2.md · decisions/D8-p2-dependency-closure.md · decisions/D6-p1-dependency-closure.md · decisions/D4-validation-and-engines.md · roadmap.md (execution sheet)
**Open risks:** D8 п.5 вводит то, чего в досье нет: ключ конфига `catalog.build_id`, `PermissionKey::prefixed(?string $prefix)` с аргументом, неабстрактный `DefinitionException`, методы `PermissionCatalog` и форму `PanelSettings`/`SourceDescription` — владелец может отменить до старта P2.1/P2.2; `StoresGrants` до P5.2 несёт только `transaction()`; `pest --type-coverage` без `-d memory_limit=1G` на холодном кэше молча пропускает файлы — гейт запускать с 1G и сверять число файлов; Pest arch: `expect([список])->not->toUse()` и `->ignoring()` вакуумны — правила по одному субъекту, функции — через `SourceScan`; до P6.9 рабочего 0.3 в сборке нет (D2).
**Workarounds/Deferred/Open questions:**
- workarounds: Task runtime берётся из directory-marketplace `swissknifeman/packages/task` (0.26.1): записанный путь установки `~/.claude/plugins/cache/swissknifeman/task/0.24.3` отсутствует.
- deferred: по D8 п.2, п.3, п.7 — `tenants()`/`scopes()`/`fields()`, `StoresGrants::apply()`, `FiltersQueries`, `ChecksHealth`, реализация `EvaluationContext`, строки V64 адаптеров, `SourceContractTests`, полный конфиг и остальные методы фасада — в P3–P6 (строки «Принимает по D8» их Phase Context); рассылка ADR — решение владельца.
- open_questions: —
