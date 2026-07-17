# HANDOFF — 2026-07-17 — after P1.1

**Next:** ЗАПУСК ВРУЧНУЮ: sonnet/high — P1.2 (§3 Routing: Exec=manual; `/task:plan-exec`
пинит sonnet/medium и по routing-гейту §9 обязан отказать)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — concurrency/race correctness, invariant-класс (SKILL §9) |
| Context | continue (/clear) — ручной item |
| Суть | T6 — атомарный epoch bump в `PermissionCache::forgetForUser()` (снять `increment()`/`put()` гонку) |

```
Repo: /home/vostrikov/projects/packages/azguard.

Прочитать (в этом порядке): 1) plans/2026.07.17-AZGUARD-TAILS/plan.md (Meta, Context,
Routing — подтвердить P1.2 Exec=manual) 2) plans/2026.07.17-AZGUARD-TAILS/handoff.md (этот
файл) 3) plans/2026.07.17-AZGUARD-TAILS/phases/P1.md item P1.2 целиком (все 16 полей — Code
Guidance обязателен к исполнению буквально) 4) все файлы из Required Reads P1.2.

Реализовать T6 (атомарный epoch bump через Cache Lock) по Code Guidance P1.2. Закрыть item по
plan-protocol §8 «Item close order»: item-commit (git add ТОЛЬКО paths из Files P1.2) →
composer test/pint --test/phpstan analyse зелёные → bookkeeping-commit
(plans/2026.07.17-AZGUARD-TAILS/** — Status P1.2 → 🟢/🟠, Phase Status, Status Board
plan.md, Update Log) → перезаписать handoff.md (Next → P1.3, форма ОЖИДАНИЕ Q1 — P1.3
заблокирован до D# по open-questions.md, НЕ запускать без него).
```

**Done:** P1.1 (T1) закрыт — panel-aware query-scope guard (D5) + eager-load recursion fix
(D9) в `bootHasScopedRoles()`, новый query-scope тест (3 кейса D5), CHANGELOG/REMAINDER/
IMPROVEMENT/docs синхронизированы (D6). Item-commit `c166538`. Статус 🟠 Done with deviations
(process-отклонения, не material — см. `phases/P1.md` P1.1 Known Deviations: голый
`composer test` OOM на локальном `memory_limit=128M`, эквивалент через `-d memory_limit=1G`
548/548 зелёный; `IMPROVEMENT_PLAN.md` «Хвосты» без колонки `Status` — отмечено в ячейке
`Действие`). Ранее: план создан целиком за один design-заход (Design Passes 1/1).
**Remaining:** P1.2 (следующий, manual sonnet/high) → P1.3 (заблокирован Q1) — по одному;
P2.1-P2.3 — через `Workflow({scriptPath: "plans/2026.07.17-AZGUARD-TAILS/workflows/wf-azguard-tails-p2.js"})`.
НЕ гнать W1 параллельно ручной сессии P1: scope по КОДУ независим, но обе ветки закрывают
items в ОДНОМ рабочем дереве и пишут одни и те же файлы бухгалтерии (`plan.md` Status
Board + Update Log, `handoff.md`) двумя коммитами каждая. Гнать W1 и P1
ПОСЛЕДОВАТЕЛЬНО (аудит A10).
**Sources of truth:** plans/2026.07.17-AZGUARD-TAILS/plan.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P2.md ·
plans/2026.07.17-AZGUARD-TAILS/roadmap.md · `REMAINDER_REPORT.md` (T1 теперь 🟢, T2-T7 актуальны).
**Open risks:** P1.3 заблокирован продуктовым решением владельца (Q1,
`open-questions.md`) — не начинать без `D#`; P1.2 (T6) может потребовать эскалации,
если `array`-кеш-стор тестового окружения не позволяет достоверно проверить
сериализацию через `Cache::lock()` (см. Escalation Needed P1.2 — но A4 уже снял ложное
основание для этой эскалации); P2.3 (T5) может потребовать эскалации, если SQLite не
воспроизводит задокументированный rollback-отказ (см. Escalation Needed P2.3). Новое:
голый `composer test` OOM-ит на этом локальном окружении (`memory_limit=128M`) — P1.2/P1.3
и P2.1-P2.3 столкнутся с тем же, гонять Validation через `php -d memory_limit=1G vendor/bin/pest`
(эквивалент, не отклонение).
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: T6 — реальный Redis-интеграционный тест на гонку явно вынесен ЗА scope
  этого item'а (см. Escalation Needed P1.2) — если нужен, отдельный follow-up.
- open_questions: Q1 (`open-questions.md`) — семантика `removeScopedRole(panelId=null)`,
  блокирует P1.3.
