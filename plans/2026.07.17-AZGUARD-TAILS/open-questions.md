# Open Questions — 2026.07.17-AZGUARD-TAILS

## Q1 — Семантика `removeScopedRole($role, $entity, panelId=null)` (T2)

**Статус:** Decision pending (нужен владелец)

`removeScopedRole` с `panelId=null` (дефолт) сейчас сносит строки **ВСЕХ панелей** для
данной пары (role, entity) — асимметрично `assignScopedRole`, где `null` означает
«создать отдельную any-panel строку», не «затронуть все панели».

Два варианта (см. `plan.md` `## Обсуждение` №1 для полного разбора плюсов/минусов):

- **A.** Сохранить как есть, только улучшить докблок/тесты, явно документируя асимметрию
  как осознанный выбор (null = «везде», а не «нигде конкретно»).
- **B.** Сменить семантику: `panelId=null` удаляет ТОЛЬКО null-панель строку (симметрично
  `assignScopedRole`); для «снести везде» ввести явный метод/флаг
  (например, `removeScopedRoleEverywhere()` или `removeScopedRole(..., allPanels: true)`).

Вариант B — breaking behavior change (метод публичный, `HasScopedRoles` — трейт модели
пользователя, консьюмеры могут полагаться на текущее поведение) → потребует записи в
`packages/core/CHANGELOG.md` под `### Breaking`, если принят.

Разрешается записью `D#` в `plan.md` владельцем (или уполномоченным design-заходом) ДО
детализации/исполнения item P1.3.
