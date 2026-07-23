# Шаблон handoff.md — rolling-указатель plans/<PLAN-ID>/handoff.md

Перезаписывается ЦЕЛИКОМ при каждом закрытии item/фазы (история — в git).
Ключи блока Workarounds/Deferred/Open questions совпадают с run-report
`devloop{workarounds,deferred,open_questions}` — сверяемость с телеметрией.

**Заголовок** — две формы (иная = ERROR линтера; канон — SKILL §8):

| Форма заголовка | Когда |
|:--|:--|
| `# HANDOFF — <YYYY-MM-DD> — after Pn.m` | закрыт item |
| `# HANDOFF — <YYYY-MM-DD> — after Pn` | закрыта/проаудирована/ре-дизайнена ФАЗА — шаг без «последнего закрытого item'а» как адресата |

**Поле `Context`** launch-block'а — закрытый enum трёх значений (SKILL §8):
`same-session — item` · `cold-start — orchestration` · `cold-start — plan-step`
(`plan-audit`/`plan-close`/`plan-design`). Статичное «рекомендован /clear»
независимо от типа следующего шага — запрещено.

Next обязан нести launch-block (полная форма и правила cold-start —
`snippets/launch-block-template.md`, SKILL раздел 8): таблица параметров запуска
+ provider-rendered invocation одним code-block. `plan-lint` проверяет их наличие.

**Формы `Next`** — закрытый enum (иная форма = ERROR линтера; канон — SKILL §8):

| Форма Next | Когда |
|:--|:--|
| `exec-items: task:plan-exec <ID> Pn.m` | следующий item детализирован, Exec = plan-exec (§9) |
| `run-items: task:plan-run <ID> Pn.a[-Pn.b]` | manual-item(ы) под проверенным inherited-plan route |
| `audit-design: task:plan-audit <ID> design` | pre-exec аудит дизайна |
| `design-phase: task:plan-design <ID> Pn` | следующая фаза — скелет |
| `design-item: task:plan-design <ID> Pn.m` | item эскалирован (🔴) либо требует ре-дизайна |
| `audit-phase: task:plan-audit <ID> Pn` | фаза Pn закрыта (терминальна) — аудит перед следующей |
| `close: task:plan-close <ID> Pn[.m]` | items терминальны: закрыть фазу / сверить item |
| `archive: task:plan-close archive <ID>` | план терминален целиком |
| `terminal: план закрыт` | все items терминальны, следующий шаг вне протокола |
| `manual: <model-class/effort>` | Exec = manual (§9): команды нет — launch-block несёт route и ГОЛЫЙ ПРОМПТ item'а |
| `waiting: <условие> → <целевая semantic форма>` | шаг реален, но неисполним сейчас — launch-block несёт целевой carrier с guard-строкой `# НЕ раньше:` |

Historical `/task:…` declarations remain compatibility inputs. New plans write the semantic
form above; provider renderers emit Claude `/task:…` or Codex `$ task:…` without changing payload.

Форма «ЗАПУСК ВРУЧНУЮ» (item с Exec = manual — модель выше пина `plan-exec`):

`````markdown
**Next:** manual: frontier/high — Pn.m (§3 Routing: Exec = manual;
`task:plan-exec` minimum implementation/medium обязан отказать)

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high — <почему high+ MANDATORY по §9> |
| Capabilities | — |
| Context | same-session — item |
| Суть | <1 строка> |

````
<ГОЛЫЙ ПРОМПТ item'а — не slash-команда: что прочитать, что сделать, как закрыть>
````
`````

Форма «ОЖИДАНИЕ» (шаг реален, но неисполним сейчас — time-gate/накопление данных/гейт не
терминален; условие структурно, launch-block несёт целевую команду с guard-строкой):

`````markdown
**Next:** waiting: ≥2026-07-16 (baseline usage-trend ≥1–2 недель) → exec-items: task:plan-exec <ID> P8.5

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | <1 строка: что сделает целевой шаг по снятии гейта> |

````
# НЕ раньше: baseline usage-trend ≥1–2 недель, ≥2026-07-16
<provider-rendered invocation for `exec-items: task:plan-exec <ID> P8.5`>
````
`````

`````markdown
# HANDOFF — <YYYY-MM-DD> — after Pn.m

**Next:** exec-items: task:plan-exec <PLAN-ID> Pn.k

| Параметр | Значение |
|:--|:--|
| Model class | <economy / implementation / frontier> |
| Effort | <low / medium / high / xhigh> |
| Capabilities | <semantic capability IDs; `—` если дополнительных нет> |
| Context | <enum §8: same-session — item · cold-start — orchestration · cold-start — plan-step> |
| Суть | <1 строка: что делает следующий запуск> |

````
<provider-rendered invocation for `task:plan-exec <PLAN-ID> Pn.k`; cold-start payload не менять>
````

**Done:** <что закрыто с прошлого handoff: items, фазы, решения>
**Remaining:** <что осталось до конца фазы/плана>
**Sources of truth:** plans/<PLAN-ID>/plan.md · plans/<PLAN-ID>/phases/Pn.md · <ключевые файлы кода>
**Open risks:** <открытые риски; «—» если нет>
**Workarounds/Deferred/Open questions:**
- workarounds: <обходки, принятые в ходе исполнения; «—» если нет>
- deferred: <осознанно отложенное; «—» если нет>
- open_questions: <вопросы, требующие решения человека/plan-design; «—» если нет>
`````
