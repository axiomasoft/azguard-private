<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/plan-exec.md
Canonical SHA-256: sha256:62aefdb74d1dd3d9c50688fccd61960521fb027ecebaa83d778a7e8962fa4a01
Adapter: task.codex-command/1.0.3
-->
Это ИСПОЛНЕНИЕ item'а мастер-плана (plan-протокол). Ввод пользователя: «$ARGUMENTS».
Модель/effort запинены (implementation/medium) — не переключай вручную. Команда самодостаточна:
весь протокол ниже, скилл `plan-protocol` дочитывай только при явной ссылке из плана.
Несколько item'ов в аргументах — исполняй последовательно, каждый по полному циклу 1–8.
**Батч §3 Routing** (item'ы одной группы `Batch`) — та же серия: последовательно, полный цикл
каждому; глубина ревью батча = МАКСИМУМ риска участников (`Review=full` у любого → весь батч на
его глубину, plan-protocol §9 «Батчинг подфаз»). Глубина reviewer-стадии одиночного item'а
следует колонке `Review` §3 (пусто → дефолт `full`, plan-protocol §9 «Адаптивная глубина ревью»).

1. **Порядок чтения — РОВНО такой, ничего сверх:**
   1) `plans/<PLAN-ID>/plan.md` — Context, Execution Rules, **§3 Routing (читать ОБЯЗАТЕЛЬНО — п. 2)**,
      Status Board;
   2) `plans/<PLAN-ID>/handoff.md` — где мы;
   3) `plans/<PLAN-ID>/phases/Pn.md` — Phase Context + твой item Pn.m целиком;
   4) файлы из **Required Reads** item'а — в указанном порядке.
   Повторное чтение уже прочитанного ЗАПРЕЩЕНО (экономия контекста); «почитаю ещё по репо на всякий
   случай» — тоже. Всё нужное обязан нести план; не несёт — см. п. 5.

2. **Предусловия.** **Дом:** `## 0. Meta` несёт `Home: repo:<путь>`, а ты запущен НЕ в этом репо
   (в Brain/зеркале) — ошибка запуска: стоп и отчёт с правильным домом (голая команда перезапуска из
   `<путь>`); SSOT плана во время exec = репо, зеркало не исполняется (скилл `plan-protocol` §12).
   Статус item'а — `⬜ Not started` или `🟡 In progress`; иначе стоп и отчёт (терминальный item не
   переисполняется). **Phase-first:** item фазы N+1 не исполняется, пока фаза N не терминальна
   (все её items 🟢/🟠/⛔) — иначе стоп + отчёт, какая фаза держит.

   **Routing-гейт (ДО п. 3 — заблокированный item в 🟡 не остаётся).** Найди строку item'а в `plan.md`
   §3 Routing (секции/строки нет → дефолт SSOT-матрицы: `plan-exec` = implementation/medium). Колонка **Exec** —
   enum `plan-exec` | `manual` | `plan-design`; пуста → **деривация** по тир-порядку (модели `economy <
   implementation < frontier ≈ frontier`, effort `low < medium < high < xhigh < max`; недостающее в ячейке берётся из
   матрицы ролей скилла §9): `model ≤ implementation` И `effort ≤ medium` → `plan-exec`; `model > implementation` ИЛИ
   `effort > medium` → `manual`. Ветки:
   - `Exec = manual` (задан ИЛИ выведен) → item НЕ исполняется командой: СТОП, отчёт
     `ROUTING-BLOCKED: Pn.m — предписано <model/effort>, команда пинит implementation/medium`,
     launch-block формы «ЗАПУСК ВРУЧНУЮ» (п. 8). Статус item'а НЕ меняется.
   - `Exec = plan-exec`, Routing НИЖЕ пина (economy и/или low) → исполнять (перебор безвреден),
     process-отклонение — в Known Deviations; статус остаётся 🟢 (п. 7).
   - `Exec = plan-exec` ПРОТИВОРЕЧИТ model/effort (заполненная Exec перебивает деривацию только
     в сторону строгости) → СТОП, отчёт `ROUTING-INCONSISTENT: Pn.m — Exec=plan-exec при
     <model/effort>`; правит `plan-design`. Статус НЕ меняется.
   - `Exec = plan-design` → item не исполняется: СТОП, ре-дизайн (п. 5).
   - Routing неоднозначен (строки на item нет, «frontier ИЛИ frontier», проза без модели) И item —
     design/contract-класса (сработал бы любой триггер эскалации п. 5) → **СТОП. При сомнении —
     СТОП** (fail-safe: цена ложного стопа — перезапуск, цена ложного прохода — RED-аудит).
   Гейт — ЧТЕНИЕ §3 глазами, не парсинг (форм Routing во флоте много, regex запрещён). Разрешение
   владельца РЕПЛИКОЙ в чате гейт не снимает: оно выражается ЗАПИСЬЮ `Exec = plan-exec` в §3.

3. **До работы:** пометь `**Status:** 🟡 In progress` у item'а И строку в таблице
   `## Phase Status` (статусы — 6 канонических строк байт-в-байт, копируй из плана).
   **Журнал (D21, P1.1, хелпер `lib/task/journal.py`):** следом append старт-запись —
   `PYTHONPATH=packages/task/lib python3 -m task.journal append --plan-dir plans/<PLAN-ID>
   --plan <PLAN-ID> --item <Pn.m> --command <plan-exec|plan-run — фактически вызванная
   команда> --actor <model>/<effort> --result in-progress` (`<model>/<effort>` — модель/effort
   ЭТОГО исполнения: pinned implementation/medium у `plan-exec`, фактическая сессии у `plan-run`).
   Хелпера/каталога плана нет (standalone-раскладка вне монорепо) — предупреди и пропусти
   (не блокер, как `plan-lint`).

4. **Исполнение — СТРОГО в Scope Included.** Implementation Rules и Code Guidance item'а —
   жёсткие рамки, не рекомендации. Заметил «улучшить бы заодно» — запиши в **Pending Work**
   item'а, НЕ делай. План разошёлся с реальным кодом (файла нет, сигнатура другая, якорь
   не находится) → НЕ импровизировать, НЕ «чинить план на ходу» → эскалация (п. 5):
   план правит plan-design, а не исполнитель. Recon/диагноз-deliverable item'а (если Deliverables
   его декларируют) кладётся в `plans/<PLAN-ID>/findings/<Pn>-<slug>.md`.

5. **Эскалация.** Триггеры (закрытый enum): многопакетный рефакторинг · неясная архитектура ·
   public contract/SemVer · protected generated assets · конфликт правил · неясные acceptance.
   При срабатывании оставь ТРИ greppable-артефакта и остановись:
   - blockquote в item: `> 🔴 ESCALATION Pn.m: <причина> | trigger: <из enum> | нужен: plan-design`;
   - `**Status:** 🔴 Blocked` у item'а (и в Phase Status) + `**Escalation Needed:** yes`;
   - обнови `handoff.md` (Next: $ task:plan-design <PLAN-ID> Pn.m; причина — в Open risks);
   - финальная строка отчёта: `ESCALATION-REQUIRED: Pn.m — <причина>`.
   - **Журнал (D21, P1.1):** append финал-запись `--result escalated --friction escalation
     --note "<причина эскалации, ≤1 строка>"` (те же `--plan-dir/--plan/--item/--command/--actor`,
     что старт-запись п. 3).

6. **Validation.** Прогони КАЖДУЮ команду из поля **Validation** item'а; зафиксируй результат
   (команда → passed/failed + суть вывода). Красное — чини в рамках Scope; не чинится в рамках —
   это `🟠 Done with deviations` с Known Deviations либо эскалация, НЕ молчаливый пропуск.
   **Всплывшая ошибка → фикс-предложение СРАЗУ (plan-protocol §8, C3/D6).** Material-остаток
   (Validation красная вне Scope, lint N−K>0, посторонний дифф, найденный дефект) — отчёт несёт
   конкретное remediation-предложение (что + launch-block под фикс + 1 строка «почему») ПОВЕРХ
   записи в Known Deviations/Pending Work, а не пассивное «готов к следующему, были ошибки».

7. **ЗАКРЫТИЕ ОБЯЗАТЕЛЬНО** — без него работа НЕ выполнена, даже если код написан.

   **Топология — ДВА коммита (единственная; шаги 1–6 строго в этом порядке):**
   1) **Item-коммит.** Дифф Scope (код/артефакты) ТОЛЬКО по путям `Files` item'а — файлы плана в него
      НЕ входят; `validate.sh` затронутого пакета зелёный ДО коммита; Conventional Commits (рус.)
      `тип(scope): суть (Pn.m)`; `git add <пути из Files>`. Внутри шага дифф может собираться
      НЕСКОЛЬКИМИ атомарными коммитами — item-коммит ЗАВЕРШАЮЩИЙ, ИМЕННО его хеш идёт в handoff
      (шаг 3). Фикс по ревью — коммит СВЕРХ топологии (не `amend`: трассируемость).
   2) **Bookkeeping плана.** `**Status:**` item'а → `🟢 Done` / `🟠 Done with deviations` (status-rule
      ниже) · **Completion Notes** — только факты (файлы, тесты, прогнанные команды и их вывод; без
      оценок «всё отлично») · **Pending Work** (из п. 4, или «—») · **Known Deviations** · строка в
      `## Phase Status` фазы · `## 4. Phase Index & Status Board` в `plan.md` (счётчик `Items 🟢/всего`,
      статус фазы) · строка в `## 6. Update Log` («Что» ≤ 2 строк, формат `<item> закрыт: <1 строка
      сути> — детали см. phases/Pn.md <item> Completion Notes`; пересказ Completion Notes ЗАПРЕЩЁН) ·
      **`Last Updated`** в `## 0. Meta` = дата закрытия item'а · **Журнал (D21, P1.1)**: append
      финал-запись (те же `--plan-dir/--plan/--item/--command/--actor`, что старт-запись п. 3)
      `--result <closed-green|closed-deviations — по статусу выше> --attempt <N — 1-based счётчик
      попыток ЭТОГО item'а> --friction <из закрытого списка §2.1: deviation при непустых Known
      Deviations, retry при attempt>1, no-op если прогон не сдвинул статус, иначе пусто> --note
      "<1 строка сути закрытия>"` — файл `journal.jsonl` входит в carve-out `plans/<PLAN-ID>/**`
      (коммитится тем же `git add plans/<PLAN-ID>` шага 6, отдельного коммита не даёт).

      **Contracts (§11).** Item, бампающий ЗАМОРОЖЕННЫЙ контракт (semver-bump секции `## 7.
      Contracts` донора — секция опознаётся по ЗАГОЛОВКУ, не по номеру; плана без неё правило не
      касается), В ТОМ ЖЕ bookkeeping-коммите обновляет: (а) строку `Exported` донора (Версия +
      Уведомлены); (б) строку `Consumed` КАЖДОГО плана-потребителя В ТОМ ЖЕ корне `plans/`
      (`Замечено` + `Реакция`) — обязанность адресована ИМЕННО item'у, не «плану вообще». Потребитель
      в ДРУГОМ репо линтером не проверяется: запись в `open-questions.md` донора + строка в Open
      risks его `handoff.md` (кросс-репо push — задача шины, mAInd, не plan-протокола).
   3) **Перезапись `handoff.md` целиком** (схема ниже). Хеш item-коммита handoff берёт ИЗ ШАГА 1 —
      коммит уже в истории (`git rev-parse --short HEAD`), поэтому хеш РЕАЛЬНЫЙ. Плейсхолдер
      вместо хеша (`<Pn.m>`, «TBD», пустая ячейка) — дефект, а не форма.
   4) **`python3 "${CLAUDE_PLUGIN_ROOT}/scripts/plan-lint.py" plans/<PLAN-ID> --baseline HEAD`** —
      ПОСЛЕ шага 3, на итоговом дереве. Прогон ДО перезаписи handoff ЗАПРЕЩЁН: закрывающий item сам
      меняет handoff, и счёт «до» не описывает состояние коммита. (Нет переменной/скрипта — предупреди.)
   5) **Счёт публикуется С АТРИБУЦИЕЙ:** `N ERROR / M WARN — новых от диффа item'а: K`
      (K — детерминированно: `--baseline HEAD`). • K > 0 — чинится ДО коммита bookkeeping'а;
      • N − K > 0 (остаточные ошибки плана вне Scope item'а) — НЕ замалчиваются: строка в Pending
      Work item'а + Open risks handoff'а. «Зелёный lint» гейта закрытия = **K = 0**, а не N = 0.
   6) **Bookkeeping-коммит:** `git add plans/<PLAN-ID> plans/ACTIVE.md` — только файлы плана.
   Item без диффа кода (Scope = сам план) идёт той же топологией: шаг 1 коммитит `Files` item'а,
   шаг 6 — бухгалтерию. Слить шаги 1 и 6 в один коммит (бандл) = process-отклонение: 🟢 + запись в
   Known Deviations + ЧИНЯЩИЙ коммит с реальным хешем в handoff (права на плейсхолдер бандл не даёт).

   **Коммит-гигиена.** `git add -A` и `git commit -a` ЗАПРЕЩЕНЫ: дерево может нести чужой дифф
   (владелец правит код параллельно) — он уедет в item-коммит незамеченным. Коммит собирается ПО ЯВНЫМ
   ПУТЯМ. Посторонний дифф исполнитель НЕ трогает (не коммитит, не стэшит, не ревертит, не «прибирает»)
   — он НАЗЫВАЕТ его в отчёте (п. 8). Carve-out: `plans/<PLAN-ID>/**` и `plans/ACTIVE.md` — штатная
   часть закрытия (шаг 6), в `Files` не перечисляются и посторонним диффом не считаются.

   **Status-rule.** Непустые Known Deviations САМИ ПО СЕБЕ статус item'а НЕ меняют.
   `🟠 Done with deviations` ОБЯЗАТЕЛЕН при **material**-отклонении (любое из): Scope Included выполнен
   не полностью или иначе, чем предписано · Validation осталась красной · затронут public contract /
   SemVer / замороженный контракт СВЕРХ ТЗ item'а · acceptance-критерий не выполнен · принят workaround,
   который ЖИВЁТ В КОДЕ/артефакте (а не в процессе исполнения) · item-коммит несёт дифф вне `Files`
   item'а (считается только по НЕплановым путям — carve-out выше), верен сам дифф или нет.
   `🟢 Done` сохраняется при **process**-отклонении, но запись в Known Deviations ОБЯЗАТЕЛЬНА: перебор
   тира (исполнено моделью/effort ВЫШЕ предписанных Routing), бандлинг коммитов, порядок правок,
   стилистика отчёта. Тихое умолчание = дефект (находка аудита).
   Дефект САМОГО ТЗ (правило ТЗ ошибочно или невыполнимо) — тоже НЕ отклонение исполнителя: он
   эскалирует (п. 5: конфликт правил / неясные acceptance), владелец или `plan-design` вносит новый D#
   и СИНХРОНИЗИРУЕТ Scope Included item'а с решением; закрытие идёт по ОБНОВЛЁННОМУ ТЗ → 🟢 + запись в
   Known Deviations (что изменено, каким D#). Правка ТЗ по ходу БЕЗ D# — material-отклонение (scope
   drift), даже если результат «лучше». **Routing-adherence:** недобор модели/effort снят гейтом п. 2
   (структурно невозможен), остаётся ПЕРЕБОР — та же запись (`исполнено <факт> вместо <из Routing>`).

   **Якорь дерева для чисел.** Любое ЧИСЛО в Completion Notes / Validation / Known Deviations /
   Update Log (счётчик `grep`, число ERROR/WARN, коммитов, файлов) приводится с якорем дерева:
   `<число> (на <git-ref>)` — и с командой, которой получено. Якорь чисел о диффе Scope — **item-коммит**
   (шаг 1; bookkeeping его не меняет); чисел о файлах ПЛАНА (грепы по `phases/Pn.md`, счёт lint) —
   **bookkeeping-коммит** (шаг 6): считай на дереве, которое КОММИТИШЬ (после `git add` правок шагов
   2–3), а не на дереве момента написания. Число без якоря либо не воспроизводимое своей же командой
   на закоммиченном дереве = **ложное утверждение**, даже если в момент написания было верным.

   **Схема `handoff.md`** (шаг 3): `# HANDOFF — <ISO-дата> — after Pn.m`, затем **Next:** (launch-block
   п. 8 — таблица `Model / Thinking / Context / Суть` + голая команда ИЛИ голый промпт отдельным
   code-block'ом, копипаст без правок; самодостаточен из холодной сессии: plan-ID + cold-start reads;
   скелет — `snippets/launch-block-template.md` скилла) / **Done:** / **Remaining:** / **Sources of
   truth:** / **Open risks:** / **Workarounds/Deferred/Open questions:** (workarounds · deferred ·
   open_questions; «—» если нет).

8. **Expected output — фиксированный формат (по блоку на item):**
   ```
   ### Pn.m — <Title>
   Status: 🟢 Done | 🟠 Done with deviations | 🔴 Blocked
   Completed: <что сделано, 1–3 строки>
   Pending Work: <…|—>
   Files Changed: <список путей>
   Commits: <item-коммит: hash — subject; bookkeeping: hash — subject>
   Validation: passed|failed — <команды и суть>
   Lint: N ERROR / M WARN — новых от диффа item'а: K
   Посторонний дифф в дереве: <файлы> | нет
   Known Deviations: <…|—>
   Escalation Needed: no|yes (тогда финальная строка ESCALATION-REQUIRED: …)
   Launch-block (следующий шаг):
   | Параметр | Значение |
   |:--|:--|
   | Model | <implementation|frontier|economy|frontier> |
   | Thinking | <effort> — <причина в 3–8 слов> |
   | Context | <РОВНО одно из трёх значений enum'а ниже> |
   | Суть | <1 строка: что делает следующий шаг> |

   <голая команда ИЛИ голый промпт — ОТДЕЛЬНЫМ code-block'ом, копипаст без правок>
   ```
   При `ROUTING-BLOCKED` / `ROUTING-INCONSISTENT` (п. 2) блок несёт ТОЛЬКО эту строку + launch-block:
   работа не начиналась, статуса/коммитов/lint'а нет.

   **Material-остаток → фикс-предложение, не только Known Deviations (plan-protocol §8, C3/D6).**
   Непустые `Known Deviations`/остаточный `N−K>0` — отчёт называет конкретный фикс (что чинит) и,
   когда этот фикс — самое ценное следующее действие, **Launch-block ниже указывает на него**
   (`$ task:plan-design <ID> Pn.m` ре-дизайн либо follow-up fix-item), а не молча на следующий item.

   **Launch-block** — не статичный шаблон, а разовая оценка СЛЕДУЮЩЕГО шага по факту его текста в
   `phases/Pn.md` (уже прочитан в п. 1), `plan.md` §3 Routing И `plans/<PLAN-ID>/roadmap.md`
   (SKILL §8): если roadmap существует и следующий шаг входит в batch/workflow-группу —
   launch-block несёт КОМАНДУ ГРУППЫ (`$ task:plan-run <ID> Pn.a-Pn.b` / `native orchestration carrier for the referenced plan-owned workflow script`),
   а не одиночный item; расхождение roadmap↔Routing — эскалация, не молчаливый выбор:
   - Следующий item, `Exec = plan-exec` → голая команда `$ task:plan-exec <PLAN-ID> Pn.k`; Model/Thinking
     — из Routing (дефолт `implementation/medium`).
   - Следующий item с **`Exec = manual`** (§9 скилла) → форма **«ЗАПУСК ВРУЧНУЮ»**: `Next` =
     `«ЗАПУСК ВРУЧНУЮ: <model/effort>»`; `Model`/`Thinking` — **предписанные Routing**, НЕ
     implementation/medium; code-block несёт **ГОЛЫЙ ПРОМПТ item'а** (копипаст без правок), а НЕ slash-команду
     (`$ task:plan-exec` пинит implementation/medium и по гейту п. 2 обязан такой item отклонить — команда в
     code-block'е была бы инструкцией нарушить гейт); `Context` = `continue (reset the session context) — ручной item`. Либо: `select session model class <M>` + `select session effort <E>` + `$ task:plan-run <ID> Pn.m` (D18).
   - Следующая фаза ещё скелет (16 полей не заполнены) → `$ task:plan-design <PLAN-ID> Pn`, не
     `plan-exec` (упадёт на предусловии). Фаза терминальна → `$ task:plan-close <PLAN-ID> Pn`.
   - **`Context` — РОВНО одно из ТРЁХ значений** (`plan-protocol` §8): `continue (reset the session context) — ручной
     item` (обычный item-by-item exec, в т.ч. manual — рестарт сессии ему не нужен) ·
     `NEW SESSION — Workflow-оркестрован` (следующий шаг — Workflow-оркестрованный кусок: фоновый
     payload растит контекст сессии быстрее самой работы, `reset the session context` этого не решает) ·
     `NEW SESSION — шаг-не-item` (`plan-audit`/`plan-close`/`plan-design` — читают план с диска
     заново; аудиту наследовать контекст исполнителя запрещено).
