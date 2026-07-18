# HANDOFF — 2026-07-18 — after P1.3

**Next:** исполнить P1.4 (сквозной adversarial review диффа фазы P1 свежим контекстом:
security-review + reviewer + blade-review субагенты; см. phases/P1.md P1.4). Design/contract
review-класс → Exec=manual, Routing fable/high (эффорт high+ ОБЯЗАТЕЛЕН по Routing §3) —
`/task:plan-exec`/`/task:plan-run` пин sonnet/medium, гейт п.2 их отклонит.

| Параметр | Значение |
|:--|:--|
| Model | fable — Routing §3 P1.4 (manual, fable/high) |
| Thinking | high — предписано Routing §3 P1.4 (adversarial review — effort high+ обязателен) |
| Context | continue (/clear) — ручной item |
| Суть | Сквозной read-only adversarial review всего диффа P1.1–P1.3: security/корректность/docs-парность, снять находки до закрытия фазы |

**ЗАПУСК ВРУЧНУЮ: fable/high**

```
Исполни P1.4 (2026.07.18-AZGUARD-STABLE) — сквозной adversarial review диффа фазы P1
(P1.1+P1.2+P1.3) свежим контекстом: прогони azguard-security-review, azguard-reviewer,
azguard-blade-review (если задеты resources/views) на `git diff <база P1>..HEAD`; синтезируй
находки с вердиктами в findings/P1-review-2026-07-DD.md; проверь, что каждая security-находка
W0/W1 несёт доказывающий регрессионный тест; проверь отсутствие scope drift за пределы 27
находок бэклога. См. plans/2026.07.18-AZGUARD-STABLE/phases/P1.md раздел P1.4 (Scope
Included/Excluded, Validation, формат отчёта) — читать целиком перед началом.
```

**Done:** P1.3 закрыт (🟠 Done with deviations). 14 находок волны W2 закрыты, 15 item-коммитов
(A-01 разбит на 2 — EN-хаб + RU-парность), на дереве `cc067fc`:
`42e6dce`/`3d49729` A-01 (Laravel-версии `^11|^12|^13`, реальный вывод `guard:doctor`, снят
ложный TS-export) · `501c8ef` A-02 (`implements AzGuardUser` в golden-path сниппетах) ·
`ee8f15f` B-07 (сужен докблок `PermissionCatalog::flush()`) · `03d2330` B-09 (swap-тест
`merge_strategy`) · `8fc1cf6` B-10 (allowlist-тест `grant_sources` + починка ложного
«reorder» в комментарии конфига) · `01bd42f` B-11 (синхронизация `@method`-типов фасада
с интерфейсом после B-04) · `8cdc33e` C-09 (флаш кэша СТАРОЙ панели/грантуемого при смене
в `DirectGrant`) · `a7e713e` C-12 (экранирование LIKE-метасимволов + `ESCAPE`-клоза,
портируемо на SQLite) · `b3948cd` C-14 (`JobProcessing`-листенер сброса `currentPanel`,
симметрично Octane) · `f9c744b` C-15 (`AccessDecision::winningSource` заполняется через
новый `EffectivePermissionResolver::sources()`) · `f6643f3` C-16 (новая миграция:
unique на `model_has_roles`/`model_has_scopes`, MySQL-ветка с префиксом 191 символ на
morph-`*_type` — иначе превышает лимит InnoDB 3072 байт) · `1f6e458` D-01 (снята мёртвая
ссылка `DiscoveryTest.php`) · `d93ced5` D-03 (обратная parity-проверка trait⊆contract,
явный allowlist) · `cc067fc` D-04 (arch-правило `toBeInterfaces()` расширено на
`AzGuard\Registry\Contracts`). Полные детали — phases/P1.md P1.3 Completion
Notes/Pending Work/Known Deviations.

Validation на финальном дереве (`cc067fc`): `composer test` — 600 passed / 1619 assertions;
`composer lint:check` — pint passed; `composer analyse` — phpstan 0 errors, baseline не
менялся; `bash bin/docs-parity-gate.sh` — OK; grep-гейт `grep -n DiscoveryTest tests/Pest.php`
— пуст.

**Remaining:** P1.4 (adversarial review) → P2 канон (10 items) → P3 заморозка → P4
тест-углубление → P5 (шаблон → релиз+тег → миграция docs) → post-plan
`/task:plan-close archive`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.8, D1–D27) ·
phases/P1.md (P1.1/P1.2/P1.3 закрыты 🟠; P1.4 — следующий) · roadmap.md ·
research/{00-user-intent,02-backlog,03-p2-canon}.md · findings/ (REGISTER + оси + recon +
RAG) · brief/{00-brief,01-refinements}.md · open-questions.md (Q3→D27).

**Open risks:**
- P1.4 review должен покрыть ВЕСЬ дифф P1.1–P1.3, включая внe-Files дрейф трёх items
  (P1.1: `tests/Pest.php`; P1.2: `ResolvesRole.php`+4 контракта+`FakeAzGuardUser.php`+
  `SwapTestManager.php`+`DatabaseRoleGrantSource.php`; P1.3:
  `EffectivePermissionResolver.php` — новый `sources()`) — все зафиксированы как Known
  Deviations в соответствующих items, но review — независимая проверка, не переспрос автора.
- `HasScopedRoles::removeScopedRoleEverywhere()` публичен на трейте, но отсутствует в
  контракте `AzGuard\Contracts\HasScopedRoles` (найдено D-03/P1.3) — занесено в allowlist
  reverse-parity теста, кандидат для P2 contract review (НЕ добавлять в интерфейс без D#:
  breaking public-contract изменение).
- Премис-дефект бэклога, найденный в P1.2 (см. phases/P1.md P1.2 Known Deviations):
  формулировки находок про «firstOrCreate второй аргумент = safe path от fillable» —
  ФАКТИЧЕСКИ НЕВЕРНЫ. Если в P2 встретится похожая формулировка — перепроверить сигнатуру.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен
  (roadmap B5); red `composer check` → эскалация §10, не тихая починка.
- Split/Packagist отложены (D25); P4/P5-инфра-items требуют внешней среды →
  честный skip-note при недоступности, не слепой зелёный.
- `plan-lint.py` прогоняется по прямому пути (найден в
  swissknifeman/packages/task/scripts/, `${CLAUDE_PLUGIN_ROOT}` не задан в
  среде) — следующему исполнителю может понадобиться тот же обходной путь.

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` вызывается по абсолютному пути
  (`${CLAUDE_PLUGIN_ROOT}` пуст в этой сессии).
- deferred: RoleResource Livewire-тест (P1.2 Pending Work); split/Packagist one-time setup
  (D25); адоптация roave/bc-check (D20); per-token DB resolver для parallel на реальных БД
  (P4.3 YAGNI); снапшот filament/context-пакетов (P3.2); `removeScopedRoleEverywhere()` →
  контракт (P1.3 Pending Work, кандидат P2).
- open_questions: Q1→D22, Q2→D23/D24, Q3(D10-б/P1.1)→D27, scope релиза→D25.
  Открытых нет.
