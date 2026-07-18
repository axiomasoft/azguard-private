# Уточнения — 2026.07.17-AZGUARD-TAILS

## 2026-07-18 — Q1 разрешена владельцем (D10)

Владелец (Dmitry Vostrikov) разрешил Q1 (`open-questions.md`, семантика
`removeScopedRole($role, $entity, panelId=null)` — T2): **Вариант B**.
`panelId=null` (дефолт) отныне удаляет ТОЛЬКО any-panel строку (`panel_id IS NULL`),
симметрично `assignScopedRole`. Снос «везде» (старое поведение) выносится в отдельный
явный метод/флаг. Это `### Breaking` change публичного API-метода трейта модели
пользователя; пакет `core` публичный, релизы по SemVer — владелец готов на breaking
со следующим релизом при явном CHANGELOG-объявлении. См. `plan.md` `## 5. Decision
Log` D10, `open-questions.md` Q1 (Resolved).

## 2026-07-18 — Детализация P1.3 (D11)

При развороте Q1→D10 в исполнимое ТЗ (`/task:plan-design 2026.07.17-AZGUARD-TAILS
P1.3`) принято инженерное решение: «снести везде» — ОТДЕЛЬНЫЙ метод
`removeScopedRoleEverywhere($role, $entity)`, не флаг на `removeScopedRole()` (флаг
допускал бы противоречивую комбинацию `panelId + allPanels`). CHANGELOG заводит новую
секцию `### Breaking`, первую под `## [Unreleased]` (файл не нёс такой секции ранее).
Точный diff проверен прогоном на рабочем дереве (551/551 тестов, pint, phpstan — 0
ошибок) и отменён перед записью ТЗ. См. `plan.md` `## 5. Decision Log` D11,
`phases/P1.md` item P1.3.
