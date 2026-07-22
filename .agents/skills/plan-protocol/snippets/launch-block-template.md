# Шаблон launch-block — фиксированная форма «что запускать дальше»

Каждая выдача следующего шага — handoff.Next и финалы отчётов
plan-exec/plan-close/plan-audit — обязана иметь эту форму (SKILL раздел 8):
semantic carrier + таблица требований + provider-rendered invocation одним code-block.

`````markdown
**Next:** <kind>: task:<plan-command> <PLAN-ID> <arguments>

| Параметр | Значение |
|:--|:--|
| Model class | <economy / implementation / frontier> |
| Effort | <low / medium / high / xhigh> |
| Capabilities | <минимальный набор semantic capability IDs; `—` если дополнительных нет> |
| Context | <enum §8: same-session — item · cold-start — orchestration · cold-start — plan-step> |
| Суть | <1 строка: что делает запуск> |

````
<provider renderer: Claude `/task:…` ИЛИ Codex `$ task:…`; не править semantic payload>
````
`````

Правила:

- **Самодостаточность (cold-start).** Команда обязана работать из холодной сессии:
  plan-ID + пути входных доков (cold-start reads) + встроенное утверждение гейта,
  если исполнение гейтится. Ссылки вида «эта сессия» — только как дополнение.
- **Scope-payload — launch-block, несущий НАХОДКИ** (§8, D-канон 2026-07-17): вердикт
  `plan-audit` `RED`/`ATTENTION` и фикс-предложение C3/D6 голыми быть не могут. Правило
  целиком в SKILL разделе 8 (единственный SSOT), здесь форма — четыре строки под командой
  в том же code-block'е, порядок фиксирован:

  `````
  <provider-rendered invocation>

  РЕЖИМ: <реопен по §3 (4 шага) | детализация | ремедиация>
  ВХОД: <файл → блок с находками; cold-start read>
  СКОУП: <нумерованный список: что сделать>
  НЕ ТРОГАТЬ: <анти-скоуп: что оставить как есть>
  `````

  `plan-audit` кладёт этот launch-block подсекцией `### Что запускать` в конец своего блока
  `## Audit Pn` — чат машиной не проверяется, диск проверяется. `plan-lint` гейтит присутствие
  всех четырёх строк (датовые планы от 2026.07.16 — ERROR, старее — WARNING). `GREEN` находок
  не несёт — payload'а не требует.
- **Model class/Effort** — из Routing плана (SSOT-матрица — SKILL раздел 9); отклонение
  от матрицы обосновывается там же, не в launch-block.
- **Capabilities** проверяются до invocation/work/write. Оркестрация обязана перечислить
  `command.subagents` и `command.workflow-carrier`; missing/unknown/unsupported блокирует запуск.
- **Context** — закрытый enum ТРЁХ значений (SKILL раздел 8, D16/D30), выбирается по типу
  СЛЕДУЮЩЕГО шага; статичное «рекомендован /clear» независимо от шага — запрещено:
  - `same-session — item` — следующий шаг обычный item-by-item exec;
  - `cold-start — orchestration` — следующий шаг оркестрован (payload
    фоновых тасков растит контекст быстрее самой работы — нужна новая сессия);
  - `cold-start — plan-step` — следующий шаг над планом (`plan-audit`/`plan-close`/
    `plan-design`): читает план с диска свежим контекстом.
- **Exec = manual (§9)** → форма «ЗАПУСК ВРУЧНУЮ» — правило целиком в SKILL разделе 8
  (единственный SSOT), здесь только напоминание: `Model class` = предписанная Routing,
  code-block = **ГОЛЫЙ ПРОМПТ item'а** вместо invocation, `Context` = обычное значение
  enum'а по типу шага (`same-session — item`).
- **Форма «ОЖИДАНИЕ: `<условие>` → `<целевая форма>`» (§8, D8)** — шаг реален, но неисполним
  сейчас (time-gate/накопление данных/кросс-план-гейт не терминален; НЕ дефект `🔴`, НЕ терминал
  «план закрыт»). Правило целиком в SKILL разделе 8; здесь напоминание: условие СТРУКТУРНО
  (дата/гейт), первая строка code-block'а — **guard `# НЕ раньше: <условие>`**, `Model class`/`Effort`/
  `Context` — **целевого шага**. Прозаическую оговорку в объявлении не писать (`plan-lint` метит C2).
- `plan-lint` проверяет: semantic `Next`, таблицу с `| Model class |`
  и code-block (грепаемо).
