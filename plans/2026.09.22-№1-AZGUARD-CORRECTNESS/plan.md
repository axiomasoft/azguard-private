# 2026.09.22-№1-AZGUARD-CORRECTNESS — План №1 — Корректность авторизации и границы расширения AzGuard

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.09.22-№1-AZGUARD-CORRECTNESS |
| Short ID | PLAN1 |
| Title | План №1 — Корректность авторизации и границы расширения AzGuard |
| Layout | v2 |
| Document Type | Executable Master Plan |
| Authoring Model | GPT-6 (текущая root-сессия; точный selector/effort не аттестован) |
| Repository | azguard |
| Related Packages | azguard-core, azguard-context, azguard-filament |
| Execution Mode | contract-first; детализация P1–P7 → общий design audit → исполнение |
| Target Operator Classes | design: frontier/high; execution: implementation/medium для bounded items, frontier/high для expiry, concurrency, Gate и миграций; audit: frontier/high с отдельным provider pin |
| Approval Owner | Дмитрий Востриков |
| Home | repo:azguard |
| visibility | private |

## 1. Context

Вход: `audits/2026-09-22-audit.md`, статический аудит коммита
`7e7009cc7c26e173cfa6bfec42365a311fc2adb0`. Проверяемый checkout:
`67ebcde7b3ab32aa0205deb37a483b605e140cdb`. Это разные деревья; проверенные
расхождения и ограничения собраны в `findings/verification.md` (RAG:—).
Аудит содержит повторно назначенные AZG-ID, уже существующие возможности и
рекомендации без измеренного дефекта. Он является источником гипотез, а не готовым ТЗ.

Результат design — порядок, границы, инварианты и детализированные контракты P1–P7.
Исторически детализацию каждой фазы выполнял `gpt-5.6-sol/high` по запросу владельца;
после plan-wide audit исправления A1/A2/A3 внесены в дизайн до исполнения.
Замена названий, общий framework с Chatom и новые инфраструктурные зависимости
не являются условиями исправления ошибок авторизации.

### Целевые инварианты

1. Идентичность субъекта в кэше и инвалидировании совпадает с persistence identity:
   morph type + канонический ID; panel/context остаются отдельными измерениями.
   Два типа субъектов с ID=1 не разделяют права или scoped-role rows.
2. При `now >= expires_at` истёкший вклад не разрешает действие: при попадании в
   request cache, внешний cache, при `*`, нескольких источниках и context layer.
   Независимый действующий вклад в то же право сохраняется.
3. Официальная мутация либо атомарно сохраняет валидный desired state, либо оставляет
   прежний. Инвалидация учитывает commit/rollback, всех затронутых субъектов и старую
   identity при переносе grant; stale allow после успешного revoke недопустим.
4. Ошибка cache backend/lock не маскируется успехом и не оставляет незамеченный stale
   allow. After-commit сам по себе не является доказательством этой гарантии.
5. Конфигурационная подмена моделей действует на поддержанных read/write/UI путях;
   конкретные Eloquent subclasses допустимы в типах. Не обещаем произвольные ORM.
6. Панель восстанавливается после nested call/exception и не течёт между request/job.
   Singleton не удерживает однажды разрешённый scoped context.
7. Strict validation проверяет конечную панель и permission syntax на всех заявленных
   входах. Изменения default/публичных сигнатур требуют явно описанной совместимости.
8. Миграции проверяются отдельно для новой установки и обновления существующей;
   опубликованная история не переписывается молча. Никаких production migrations.

### Границы

Включено: подтверждённые correctness gaps, их регрессии, завершение уже заявленных
extension seams, проверка существующих диагностик и release/CI контрактов.
Исключено: публикация релиза, push, изменение Chatom или swissknifeman, table/class
rename ради единообразия, новый outbox/audit platform, повсеместные VO/repositories,
повышение coverage floor без baseline. Отложенные идеи: `roadmap/azguard-evolution.md`.

## 2. Execution Rules

- Протокол: Task `plan-protocol-v2`; canonical source в установленном Task package.
- Чтение: этот файл → выбранный Phase Context → нужные finding/decision → item.
- Перед исполнением сверить HEAD и только затронутые findings; повторный полный аудит не нужен.
- Исполнять детализированные item-контракты с точными Files/Required Reads/Validation; новые незакрытые Q# разрешать у owning item.
- Обычный объём фазы — 2–3 outcome items; red reproduction и fix принадлежат одному item.
- RAG:— означает код/локальную проверку; RAG:✅ — открытый первоисточник; внешние пробелы явно помечаются.
- Старые AZG-ID цитировать вместе с заголовком/строкой аудита; новые находки — PLAN1.F#.
- Сначала воспроизводить минимальный сценарий; исправлять через owning package, сохранять чужие изменения.
- Проверять поведение, а не строго «epoch увеличился один раз»: обязательны отсутствие stale allow и no-op contract.
- Тесты только в SQLite `:memory:` или доказанно изолированных `*_test` DB; реальный Redis отдельно от array fake.
- Не менять существующие GrantSource/PermissionLayer/PermissionSet сигнатуры без анализа API snapshot и migration path.
- Scope state, cache identity и revision protocol должны совпадать у core, context и Filament.
- Поддерживаемые версии брать из composer/CI, не копировать предполагаемую матрицу из аудита.
- Перед handoff — affected checks; новые проверки не аннулируют неизменённые доказательства.
- Release-visible изменения будущих фаз — один root CHANGELOG, docs RU/EN и UPGRADING по влиянию.
- Generated state/status/decision views и receipts создаёт Task; журнал append-only.
- Каждый item читает фазовые `research/Pn-design.md`, `research/Pn-design-rag.md` и `artifacts/Pn-design/examples.md` как digest-bound execution inputs; сырой search capture — только provenance.
- Лимиты объёма phase/item не ограничивают supporting dossier: дизайн должен быть самодостаточен для fresh/weaker executor.
- `roadmap.md` `execution-sheet/v1` — единственный источник launch-команд; handoff выбирает ready строку, но не меняет batch и route.
- Batch использует максимум semantic class/effort/review своих items; provider selector в плане запрещён.
- Review=light — один целевой review изменённых интеграционных швов в рамках item; он не создаёт трёхстадийный review-ритуал, повторный review или phase audit без новой материальной гипотезы и явного owner consent.
- До первого `plan-exec` требуется GREEN plan-wide design audit по D4. Детализация P1–P7 и design finish уже выполнены; RED-проверка и исправления A1/A2/A3 требуют повторного design verdict. Item/phase review не заменяет этот gate.
- Дизайн сам не разрешает публикацию, внешние записи или изменение чужих репозиториев.

## 3. Routing

Ниже маршруты детализированных implementation items. Исторический design route P1–P7:
`frontier/high → gpt-5.6-sol/high`; исполнение выбирает свой provider adapter.
Batch recommended допускает готовое подмножество при выполненных зависимостях.

| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| solo | P1.1 | implementation/medium | plan-exec | none | recommended: identity — bounded implementation с focused checks; отдельный review не нужен |
| solo | P1.2 | frontier/high | plan-exec | light | recommended: expiry/envelope и stale-allow boundary потребляют GREEN P1.1 |
| B2 | P2.1 | frontier/high | plan-exec | none | recommended: одна session B2 покрывает атомарную mutation и revision seam |
| B2 | P2.2 | frontier/high | plan-exec | none | recommended: transaction-local bypass и commit/cache race требуют frontier |
| B2 | P2.3 | frontier/high | plan-exec | light | recommended: итоговый review role-sync/revision/failure seams |
| B3 | P3.1 | implementation/medium | plan-exec | none | recommended: core model resolution закрывается focused checks |
| B3 | P3.2 | implementation/medium | plan-exec | light | recommended: итоговый review core/Filament/diagnostics model seams |
| B4 | P4.1 | frontier/high | plan-exec | none | recommended: lifecycle и auth-recursion вместе с Gate boundary в одной session |
| B4 | P4.2 | frontier/high | plan-exec | light | recommended: strict validation и Gate fallback — security-external seam |
| B5 | P5.1 | implementation/medium | plan-exec | none | recommended: role identity определяется targeted checks |
| B5 | P5.2 | implementation/medium | plan-exec | light | recommended: итоговый review role identity/scaffold/doctor seams |
| B6 | P6.1 | frontier/high | plan-exec | none | recommended: destructive migration recovery в одной session с SQL identity |
| B6 | P6.2 | frontier/high | plan-exec | light | recommended: exact multi-engine DDL и безопасный upgrade требуют frontier |
| B7 | P7.1 | implementation/medium | plan-exec | light | recommended: qualification запускает фиксированные проверки и возвращает дефекты owning item |
| B7 | P7.2 | implementation/medium | plan-exec | light | recommended: docs/release recipe потребляют qualification evidence |

## Execution map

| Фаза | Результат | Зависимости | Детализация |
|:--|:--|:--|:--|
| P1 | Изолированный и учитывающий время кэш | — | sol/high |
| P2 | Атомарные мутации и согласованная инвалидация | P1 | sol/high |
| P3 | Рабочие model overrides | P2 | sol/high |
| P4 | Runtime panel и единые validation boundaries | P1 | sol/high |
| P5 | Безопасная role identity и scaffolding | P3, P4 | sol/high |
| P6 | Проверенная схема и безопасный upgrade | P3, P5 | sol/high |
| P7 | Qualification, документация и release recipe | P2–P6 | sol/high |

Рекомендуемый порядок P1 → P2 → P3 → P4 → P5 → P6 → P7 сохраняет компактный
рабочий контекст. P4 технически независима от P2/P3 после P1; P6 учитывает решения P5,
чтобы не провести конкурирующие миграции role identity. P1/P2 образуют ранний correctness
срез и могут быть подготовлены к отдельному выпуску без ожидания косметики/эволюции.
Статусы принадлежат generated `status.md`.

## 5. Decision Log

Нормативные решения — `decisions/`; D11 закрепляет self-contained supporting-artifact contract, D12 — global batching/execution sheet, D13/D14 исправляют A1/A2 и supersede D5/D9.
Собрать виды: `plan-views.py decisions`.

Update Log генерируется в `status.md` из journal actor/note. Открытые design-вопросы —
`open-questions.md`; разрешаются owning фазой.
