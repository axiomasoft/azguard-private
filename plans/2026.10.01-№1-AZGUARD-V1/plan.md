# 2026.10.01-№1-AZGUARD-V1 — Plan №1 — AzGuard 1.0: перестройка по целевой архитектуре

## 0. Meta

| Field | Meaning |
|:--|:--|
| Plan ID | 2026.10.01-№1-AZGUARD-V1 |
| Short ID | PLAN2 |
| Title | Plan №1 — AzGuard 1.0: перестройка по целевой архитектуре |
| Layout | v2 |
| Document Type | Executable Master Plan |
| Authoring Model | Claude Opus 5.5 (`claude-opus-5-5`; effort сессии не аттестован) |
| Repository | azguard |
| Related Packages | axiomasoft/azguard (packages/core), axiomasoft/azguard-filament (packages/filament); legacy azguard-core/-context/-filament 0.3 |
| Execution Mode | contract-first; phase-by-phase: детализация фазы по реальному коду → исполнение → следующая фаза |
| Target Operator Classes | design: frontier/high; execution: implementation/medium для bounded items, frontier/high для семантики авторизации, хранилища, конкурентности и UI-эскалации; review: frontier/high read-only |
| Approval Owner | Дмитрий Востриков |
| Home | repo:azguard |
| visibility | private |
| Design Audit | not-required |

## 1. Context

Вход — целевая архитектура AzGuard 1.0 `audits/2026-09-29-audit/opus/` (далее «досье»): решения
D01–D84 (`02`), фазы F0–F8 и пункты `Pn.m` (`13`), сценарии V01–V120 (`14`), CRM-приёмка R01–R68
(`17`), сквозные процессы F01–F24 (`20`), probes 0.3 (`evidence/`). Вход владельца — `brief/00-brief.md`.

Пакет 0.3 не в эксплуатации (D01): старый код не патчится, версия 1.0 строится с нуля по досье,
без алиасов, нормализатора конфига и миграции данных. Дефекты N01–N24 и probes P01–P14 становятся
регрессионными тестами нового API с обратным ожиданием. Результат — `axiomasoft/azguard` и
`axiomasoft/azguard-filament` 1.0.0-beta.1, затем 1.0.0 с блокирующими гейтами совместимости.

Досье — нормативный источник смысла и имён; фактическая раскладка, сигнатуры и порядок шагов
уточняются при детализации фазы по реальному коду. Расхождение досье с кодом или внутри досье —
запись в `open-questions.md` и решение `D#`, а не импровизация. Q30–Q33 исполняются с defaults
(`15-owner-questions.md`): встроены Folder/Database/Relation/Gate sources; метаданные ролей —
атрибуты с методом-эквивалентом; FolderSource не отключается; Laravel 11/12/13.

### Целевые инварианты (проверяются во всех фазах)

1. Одно правило выбора панели (`PanelResolver`) для всех входов; неразрешимая панель — исключение.
2. Идентичность субъекта/tenant/scope/роли одна в SQL, кэше и событиях; без склейки строк (D07).
3. Ошибка любого шага проверки = отказ; Before/Restriction только запрещают (D17, D20, D55).
4. Явный authority mode права: PolicyOnly не читает назначения; RequiresGrant не обходится policy true (D53, D83).
5. Роли — только PHP-классы; БД хранит назначения, не definitions (D13, D80).
6. Единственный путь записи — ChangePipeline с транзакцией, версией панели и событиями после commit (D22, D49).
7. Kernel без Laravel; зоны и `@api/@spi` границы держат arch-тесты и `api-manifest.json` (D02, D12).

## 2. Execution Rules

- Протокол Task `plan-protocol-v2`; executor читает plan → Phase Context → item → bundle → state.
- Перед детализацией фазы прочитать её разделы досье и реальный код предшествующих фаз; фаза
  исполняется только после детализации всех её item (D3).
- Номера пунктов досье сохраняются; новые пункты получают следующий свободный номер фазы.
- Каждая фаза реализации P1–P7 заканчивается read-only пунктом `Review Pn` (verdict-файл в `findings/`);
  P4 дополнительно получает срез-review между группами. Item Review по умолчанию `none`.
- Код 1.0 пишется в `packages/core` и `packages/filament`; legacy 0.3 — только справочник
  `legacy/0.3/`, не автозагружается и удаляется в P6.9 (D2). Код Vaulter и других пакетов не меняется.
- Probe из колонки «Probe» досье переписывается в owning item как регрессия с обратным ожиданием;
  item без него не закрыт. V-сценарий owning item — его acceptance.
- Имена — только из `03-glossary-and-renames.md`; внутренние коды задач в docblock `src` запрещены.
- Гейты каждого item: targeted Pest + полный набор `composer test`, `vendor/bin/pint --test`,
  `vendor/bin/phpstan analyse` (уровень проекта), arch-тесты. Падение/пропуск ≠ успех.
- SQLite `:memory:` — по умолчанию; PG/MySQL/Redis — только docker compose test DB (`*_test`);
  SQLite green не доказывает PG/MySQL DDL, блокировки и конкурентность (D4).
- Built-in sources пишутся только через публичные контракты; не хватает контракта — сначала контракт.
- Без новых runtime-зависимостей сверх досье (`illuminate/*`, Filament 5 для UI-пакета).
- Release-visible изменения — `CHANGELOG.md` `[Unreleased]`; коммиты — Russian Conventional Commits.
- Push, теги, релизы, Packagist, чужие репозитории — только owner-gated пункты P8.

## 3. Routing

Маршрут — минимальный semantic class; batch финализируется детализацией фазы и execution sheet.
Класс исходного досье: opus → frontier/high, sonnet → implementation/medium.

| Batch | Items | Model class/effort | Exec | Review | Why |
|:--|:--|:--|:--|:--|:--|
| B0 | P0.5 | implementation/high | run | none | recommended: legacy freeze и каркас 1.0 задают инфраструктуру всех фаз |
| B0 | P0.4 | implementation/medium | run | none | recommended: composer-имена в том же каркасе |
| B0 | P0.2 | implementation/medium | run | none | recommended: probes → спецификации регрессий |
| B0 | P0.3 | implementation/medium | run | none | recommended: consumer fixture skeleton в CI |
| B0 | P0.1 | implementation/medium | run | none | recommended: ADR текстом из досье |
| B1 | P1.2 | implementation/medium | run | none | recommended: грамматика первой — все значения валидируют через неё (D6) |
| B1 | P1.1 | frontier/high | run | none | recommended: identity/codec — основа безопасности кэша и SQL |
| B1 | P1.3 | implementation/medium | run | none | recommended: значения решения поверх ссылок P1.1 |
| B1 | P1.4 | implementation/medium | run | none | recommended: arch-правила зон и api-manifest на коде P1.1–P1.3 |
| solo | P1.6 | frontier/high | run | none | отдельная frontier-сессия: authority semantics, BaseRole, SPI областей до API freeze (D6) |
| solo | P1.7 | frontier/high | run | none | Review P1: независимая read-only проверка фазы (D3) |
| solo | P1.8 | frontier/high | run | none | исправление находок Review P1 в сессии review по указанию владельца (D7) |
| B2a | P2.1 | frontier/high | run | none | recommended: панель, builder с происхождением, реестр — на них стоит вся фаза (D8) |
| B2a | P2.2 | frontier/high | run | none | recommended: единое правило выбора панели на реестре P2.1 |
| B2a | P2.3 | frontier/high | run | none | recommended: порядок настроек и происхождение значений |
| solo | P2.4 | frontier/high | run | none | recommended: плагины и изоляция экземпляров |
| solo | P2.5 | frontier/high | run | none | recommended: каталог, коллизии, O(1)-поиск, кэш |
| solo | P2.6 | implementation/medium | run | none | recommended: фасад панелей и модульная фикстура на каталоге |
| solo | P2.7 | frontier/high | run | none | recommended: фабрика источников, писатель, экземпляры |
| solo | P2.8 | frontier/high | run | none | recommended: FolderSource и discovery наполняют каталог |
| solo | P2.9 | implementation/medium | run | none | recommended: кадры областей и directories по D6 |
| solo | P2.10 | frontier/high | run | none | Review P2: независимая read-only проверка фазы в свежей сессии (D3) |
| B3a | P3.1 | frontier/high | run | none | recommended: единственный путь записи, блокировки, повторы, версия панели (D12) |
| B3a | P3.2 | frontier/high | run | none | recommended: схема 08 §2 и DDL на 4 СУБД поверх Storage P3.1 |
| B3b | P3.3 | frontier/high | run | none | recommended: модели, свои модели и поля поверх схемы P3.2 |
| B3b | P3.4 | implementation/medium | run | none | recommended: запрет прямых записей на моделях P3.3 |
| solo | P3.5 | frontier/high | run | none | Review P3: независимая read-only проверка фазы в свежей сессии (D3) |
| solo | P4.1 | frontier/high | run | none | Пайплайн проверки и authority dispatcher (D15) |
| B4a | P4.2 | frontier/high | run | none | recommended: каталог, роли и фикстуры вместе с P4.3; отдельные item gates (D16) |
| B4a | P4.3 | frontier/high | run | none | recommended: политики и canonical binding на том же каталоге P4.2; отдельные item gates (D16) |
| solo | P4.4 | frontier/high | run | none | отдельный допуск: Storage/SPI, raw DB reads и fence на трёх engines (D15/D16) |
| solo | P4.17 | frontier/high | run | none | отдельный допуск coherent Prepare; затем переиспользовать root для P4.5 в разрешённом scope (D16) |
| solo | P4.5 | frontier/high | run | none | отдельный допуск RelationSource; контекст P4.17 переиспользуется, numeric batch невозможен (D16) |
| solo | P4.6 | frontier/high | run | none | отдельный допуск: структурная tenant/scope/owner граница и конфигурация (D15/D16) |
| solo | P4.19 | frontier/high | run | none | отдельный допуск eligibility/adapters; затем root continuation P4.7 в разрешённом scope (D16) |
| solo | P4.7 | implementation/medium | run | none | отдельный допуск admin semantics; переиспользовать контекст P4.19, numeric batch невозможен (D16) |
| solo | P4.16 | frontier/high | run | none | отдельный допуск property oracle; затем root continuation P4.18 в разрешённом scope (D16) |
| solo | P4.18 | frontier/high | run | none | отдельный допуск scoped integration/P08 и трёх engines; reuse P4.16, numeric batch невозможен (D16) |
| solo | P4.15 | implementation/high | run | none | отдельный допуск: полноценная CRM-фикстура, discovery и ранняя acceptance matrix (D15/D16) |
| solo | P4.13 | frontier/high | run | none | независимый Grok 4.7/high read-only срез-review перед cache; граница батчей (D16) |
| solo | P4.8 | frontier/high | run | none | отдельный допуск: raw cache, key/refresh/expiry и lifecycle (D15/D16) |
| solo | P4.20 | frontier/high | run | none | отдельный допуск: root transactions, marker, incarnation/touch и commit publication (D15/D16) |
| solo | P4.21 | frontier/high | run | none | отдельный допуск: concurrency, Redis, real replica lag и benchmarks (D15/D16) |
| solo | P4.9 | frontier/high | run | none | отдельный допуск: set-based batch engine, общий fence и budgets на engines (D15/D16) |
| B4e | P4.10 | implementation/medium | run | none | recommended: explain и Gate P4.11 используют ScenarioGenerator, resolver и паритет (D16) |
| B4e | P4.11 | frontier/high | run | none | recommended: GateBridge на том же сценарном корпусе P4.10; отдельные item gates (D16) |
| solo | P4.22 | frontier/high | run | none | отдельный допуск: публичный predicate SPI, partitions и SQL compiler (D15/D16) |
| solo | P4.12 | frontier/high | run | none | отдельный допуск: exact visibility integration, unsupported/cross-connection bounds (D15/D16) |
| solo | P4.23 | frontier/high | run | none | отдельный допуск: CRM/parity, scale и настоящий SQL EXPLAIN (D15/D16) |
| solo | P4.14 | frontier/high | run | none | независимый Grok 4.7/high read-only Review P4 после P4.23; граница фазы (D16) |
| B5 | P5.1 | frontier/high | run | none | recommended: трейт и SubjectAccess |
| B5 | P5.2 | frontier/high | run | none | recommended: ChangePipeline |
| B5 | P5.3 | implementation/medium | run | none | recommended: RoleCatalog/GrantManager/PermissionManager |
| B5 | P5.4 | implementation/medium | run | none | recommended: события и audit-плагин |
| B5 | P5.5 | frontier/high | run | none | recommended: PanelSchema |
| B6 | P6.1 | implementation/medium | run | none | recommended: фасад, PanelAccess |
| B6 | P6.2 | implementation/medium | run | none | recommended: middleware и атрибуты контроллеров |
| B6 | P6.3 | implementation/medium | run | none | recommended: конфиг |
| B6 | P6.4 | implementation/medium | run | none | recommended: doctor |
| B6 | P6.5 | implementation/medium | run | none | recommended: install |
| B6 | P6.6 | implementation/medium | run | none | recommended: команды |
| B6 | P6.7 | implementation/medium | run | none | recommended: тестовый kit и контрактные наборы |
| B6 | P6.8 | implementation/medium | run | none | recommended: генераторы |
| B6 | P6.9 | implementation/medium | run | none | recommended: удаление legacy |
| B6 | P6.10 | implementation/medium | run | none | recommended: механизмы Laravel |
| B7 | P7.1 | implementation/medium | run | none | recommended: Filament-плагин |
| B7 | P7.2 | implementation/medium | run | none | recommended: FilamentGate |
| B7 | P7.3 | frontier/high | run | none | recommended: RoleResource/PermissionResource по схеме |
| B7 | P7.4 | implementation/medium | run | none | recommended: редакторы выдач |
| B7 | P7.5 | frontier/high | run | none | recommended: свои поля в формах |
| B7 | P7.6 | implementation/medium | run | none | recommended: страницы |
| B7 | P7.7 | implementation/medium | run | none | recommended: генерация |
| B8 | P8.1 | frontier/high | run | none | recommended: контракт интеграций |
| B8 | P8.2 | implementation/medium | run | none | recommended: документация |
| B8 | P8.3 | implementation/medium | run | none | recommended: гейты совместимости |
| B8 | P8.4 | implementation/medium | run | none | recommended: consumer-фикстуры и матрица |
| solo | P8.7 | frontier/high | run | none | отдельная frontier-сессия: независимая CRM-квалификация R01–R68 на матрице СУБД |
| solo | P8.5 | implementation/medium | run | none | owner gate: тег 1.0.0-beta.1 и abandoned-пометки — внешнее необратимое действие |
| solo | P8.6 | implementation/medium | run | none | owner gate: задача в чужом репозитории Vaulter |
| solo | P8.8 | implementation/medium | run | none | owner gate: выпуск 1.0.0 |

## Execution map

| Фаза | Результат | Зависимости | Досье |
|:--|:--|:--|:--|
| P0 | Legacy заморожен, каркас 1.0 с зелёными пустыми гейтами, регрессионные спецификации, consumer CI | — | 13 F0, D01, D03 |
| P1 | Ядро понятий `Kernel\` и arch-правила зон | P0 | 13 F1, 04, 05 §5, D07, D18, D59–D83 |
| P2 | Панели, выбор панели, плагины, каталог, фабрика источников, FolderSource | P1 | 13 F2, 05 §4, 06, 09 §1 |
| P3 | Хранилище: mutate, миграции 4 СУБД, модели, strict writes | P2 | 13 F3, 08 |
| P4 | Проверка прав: пайплайн, источники, контексты, кэш, Gate, видимость | P3 | 13 F4, 09, 06 |
| P5 | Трейт, пайплайн изменений, события, PanelSchema | P4 | 13 F5, 05, 06 §5, 08 §6 |
| P6 | Laravel-поверхность, команды, kit, генераторы, удаление legacy | P5 | 13 F6, 07, 12 |
| P7 | Filament по схеме панели | P6 | 13 F7, 11 |
| P8 | Интеграции, документация, гейты, CRM-приёмка, выпуски | P7 | 13 F8, 10, 12, 17 |

Внутрифазные зависимости досье (13 «Зависимости» и «Уточнения пятого прохода») переносятся в Phase
Context при детализации: P1.6 до P2.2/P3.2/P4.1; P2.7/P2.8 до P4.2–P4.5; P4.1 до источников;
P4.12 после P4.4–P4.6; P5.5 после P4.2–P4.5. CRM-кейсы R01–R68 добавляются в owning items начиная с P4
(D5); P8.7 — итоговая квалификация независимым consumer.

## 5. Decision Log

Нормативные решения плана — `decisions/`: D1 граница и источник, D2 стратегия сборки и legacy,
D3 режим исполнения и review, D4 валидация и среды СУБД, D5 рост CRM-приёмки, D6 состав P1
по замыканию зависимостей, D7 исправление находок Review P1, D8 состав P2 по замыканию зависимостей
и владение методами `PanelBuilder`, D9–D11 поправки и исправления P2, D12 состав P3, публичность хранилища и
пробелы досье по DDL, блокировкам и полям, D13 точное сравнение ID на MySQL/MariaDB, D14 состав P4, швы
пунктов, публичные имена и расхождения досье по проверке прав; D15 исправление design-review P4,
типизированные швы и разделение P4.16–P4.23 с полной матрицей приёмки; D16 — рекомендованные
батчи B4a/B4e, solo-продолжения с переиспользованием контекста и исполнение 6.1-sol/high с Grok 4.7/high review.
Решения досье цитируются как `D01–D84` (двузначные) и не копируются.
Собрать виды: `plan-views.py decisions`. Открытые вопросы — `open-questions.md`.
