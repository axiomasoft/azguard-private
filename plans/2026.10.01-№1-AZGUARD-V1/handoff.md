# HANDOFF — 2026-10-02 — after P1.8

**Next:** design-phase: task:plan-design 2026.10.01-№1-AZGUARD-V1 P2

| Parameter | Meaning |
|:--|:--|
| Batch | — |
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue-root |
| Essence | Детализация P2 (панели, выбор панели, плагины, каталог, фабрика источников, FolderSource) по разделам досье и реальному коду P1 (D3), включая принятое по D6 и строку «Принимает от P1.6» Phase Context |

````
/task:plan-design 2026.10.01-№1-AZGUARD-V1 P2
````

**Done:** P0 закрыт GREEN; P1 закрыт GREEN: P1.1–P1.6 (Kernel, исключения D37, arch-правила зон, роли и SPI областей), Review P1 (P1.7) — RED с F1–F3, исправлены в P1.8 (D7): `tests/Arch/SourceScan` — один token-скан, правило «no debug calls» доказанно краснеет; `ConsistencyException`/`InvalidSourceContributionException` вместо SPL; Phase Context P2 принимает отсрочку P1.6 — findings/P1-review.md «Повторная проверка».
**Remaining:** `task:plan-design 2026.10.01-№1-AZGUARD-V1 P2`, далее P2–P8 по D3.
**Sources of truth:** plans/2026.10.01-№1-AZGUARD-V1/plan.md · phases/P2/P2.md · decisions/D6-p1-dependency-closure.md · decisions/D7-p1-review-repair.md · decisions/D4-validation-and-engines.md · roadmap.md (execution sheet)
**Open risks:** `composer test:types`/`pest --type-coverage` без `-d memory_limit=1G` на холодном кэше молча пропускает файлы (`Warning: foreach() ... Analyser.php:140`, exit 0, `100.0 %`) — гейт запускать с 1G и сверять число файлов; исправление — обёртка гейтов D4 (P8); Pest arch: `expect([список])->not->toUse()` и `->ignoring()` вакуумны — правила по одному субъекту, функции — через `SourceScan`; до P6.9 рабочего 0.3 в сборке нет (D2).
**Workarounds/Deferred/Open questions:**
- workarounds: Task runtime запускался с `PYTHONPATH` на `core` из кэша плагина (commit a783b98f) — в окружении сессии `core` не на `PYTHONPATH`.
- deferred: значения, требующие Panel/Change/EvaluationContext, — в P2/P4/P5 по D6; компиляция ролей против панели и валидация override-значений `key()`/`formerKeys()` — P2.5/P2.8; рассылка ADR — решение владельца.
- open_questions: —
