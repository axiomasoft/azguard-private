# Шаблон plan.md — скопировать в plans/<PLAN-ID>/plan.md

Скелет мастер-плана по протоколу `plan-protocol`. PLAN-ID — датовый
`<ГГГГ>.<ММ>.<ДД>-<КОДОВОЕ-ИМЯ>` (предпочтительный, напр. `2026.07.10-SETTINGS-DOCTOR`)
ИЛИ старый `[A-Z][A-Z0-9-]+` (back-compat), см. SKILL раздел 2.
Хранимый формат — только Markdown с жёсткими маркерами; JSON — производный
артефакт парсера, не источник истины.

Развилка дизайна живёт в секции `## Обсуждение` (провенанс: варианты + обязательный
статус — `Exploration` / `Decision pending` / `Resolved → D#`); всё, что НЕ `Resolved`,
дополнительно ведётся в `plans/<PLAN-ID>/open-questions.md` (трекер незакрытого);
протокол — SKILL раздел 3.
Неоднозначность в тексте — маркер `[NEEDS CLARIFICATION: <вопрос>]` (раздел 7).

```markdown
# <PLAN-ID> — <Title>

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | <PLAN-ID> |
| Title | <краткое имя плана> |
| Version | 0.1.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | frontier |
| Last Updated | <YYYY-MM-DD> |
| Repository | <repo> |
| Related Packages | <pkg1, pkg2> |
| Execution Mode | phase-first |
| Target Operator Classes | implementation (exec) · economy (LOW) · frontier (design/audit) |
| Approval Owner | <человек, утверждающий план> |
| Home | <необязательное: brain ИЛИ repo:путь — где живёт исполняемая копия, по умолчанию репо проекта, SKILL раздел 12> |
| visibility | <необязательное: private ИЛИ public, дефолт private; public → в git только публичная выжимка, SKILL раздел 4> |
| supersedes | <необязательное: старый PLAN-ID, чью волну трека сменяет план; предшественник в plans/archive/> |
| Paused By | <необязательное: PLAN-ID перехватившего ACTIVE + дата; заполняет plan-design new; нет — «—»> |

## 1. Context

<5–15 строк: что делаем и почему. Самодостаточно — исполнитель НЕ видит
обсуждение, в котором план родился.>

## 2. Execution Rules

<Выжимка ≤20 строк + план-специфичные отклонения. Полный протокол — скилл
plan-protocol; не копировать его целиком. Минимум:>
- Один item за раз; статус обновляется В ПЛАНЕ до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (см. plan-protocol §5).
- План разошёлся с кодом → не импровизировать, эскалировать (§8).
- После закрытия item/фазы — перезаписать plans/<PLAN-ID>/handoff.md.

## 3. Routing

<ТОЛЬКО отклонения от SSOT-матрицы plan-protocol §9. Пусто = дефолты SSOT-матрицы §9.>

| Items | Model class/effort | Exec | Почему |
|:--|:--|:--|:--|
| <Pn.m–Pn.k> | <frontier/high> | manual | <расфриз public-контракта: effort high+ MANDATORY> |
| <Pn.m–Pn.k> | <implementation/low> | plan-exec | <doc-cleanup без новых решений> |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P1 | <title> | 0/3 | ⬜ Not started |
| P2 | <title> | 0/2 | ⬜ Not started |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | <YYYY-MM-DD> | <решение> | <обоснование> |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| <YYYY-MM-DD> | issue-planner/frontier | План создан |

## 7. Contracts

<ОПЦИОНАЛЬНАЯ: только у планов, экспортирующих/потребляющих замороженные контракты.
Не экспортируешь и не потребляешь — секцию УДАЛИ (её отсутствие не дефект).>

### Exported

| Контракт | Версия | Статус | Потребители | Уведомлены |
|:--|:--|:--|:--|:--|
| <root/contracts/<name>.md> | v1.0.0 | frozen | <PLAN-ID (Pn.m)> | <YYYY-MM-DD> |

### Consumed

| Контракт | Донор-план | Pinned | Замечено | Реакция |
|:--|:--|:--|:--|:--|
| <DONOR/root/contracts/<name>.md> | <DONOR-ID> | v1.0.0 | — | — |

## Обсуждение

<ОПЦИОНАЛЬНАЯ, БЕЗ номера, последней секцией. Развилок нет — секцию УДАЛИ.>

### 1 — <развилка одной строкой>

- **(рекоменд.) Вариант A.** Плюсы … Минусы …
- **Вариант B.** Плюсы … Минусы …

**Статус:** Resolved → D# | Decision pending (нужен владелец) | Exploration
```
