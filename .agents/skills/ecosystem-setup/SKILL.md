---
name: ecosystem-setup
description: "Use when changing project skills, plugins, memory scopes, roles, or environment connections in this ecosystem."
---

# Настройка проекта `azguard` (узел academici)

Этот проект — узел экосистемы **academici**, подключённый к хабу **mAInd**. Файл управляется
из maind (`templates/skills/ecosystem-setup/SKILL.md`) — правь там и `maind sync`, не в копии.

**Состояние проекта:** тип `php-package` · scope `project:azguard` · namespaces `axioma`.

## Главные правила (не нарушай)

1. **Оптимально, не максимально.** Подбирай набор скиллов/плагинов под стек и роль проекта.
   Не включай чужие роли (`php`/`python`/`mobile` на фронте, фронт на backend-пакете) — это шум.
2. **Неочевидно → спроси.** Если оптимальный набор неясен (гибридный стек, спорные бакеты) —
   спроси пользователя (2-3 курированных варианта), не угадывай и не ставь «на всякий случай».
3. **Канал скиллов спрашивай всегда** (connect vs vendor) — он меняет, что коммитится в репо.

## Дерево решений (вопрос → варианты → действие)

**Тип проекта** (`php-package`) задаёт базовый профиль скиллов. Если тип `standalone` —
он **не распознан**: не ставь всё, выбери профиль явно (`node-frontend`, `laravel-project`,
`php-package`, `python-backend`, …) или спроси.

| Решение | Варианты | Действие |
|:--|:--|:--|
| **Память** | maind / native / off | `maind onboard azguard --memory <…>` (дефолт maind) |
| **Namespaces** | общий (группа) + личный (изоляция) + `*` (инфра) | `--namespace a,b`; внутри ns память общая, между — изолирована |
| **Канал скиллов** | connect (бакет, не в репо) / vendor (скиллы в репо + lock) | спросить; `skiller connect` или `.swissknifeman/config.json` + `skiller sync` |
| **Бакеты ролей** | по профилю типа; CORE всегда | не «всё подряд»; неочевидно — спросить |
| **Окружение** | permissions / MCP / auto-approve / brain | делегируется в `harness` |
| **Код-граф** | Serena вкл/выкл | `--with-code` |

## Частые задачи

- **Добавить роль/скиллы** → спроси канал; connect: `skiller connect --plugins <текущие>,<новый>`;
  vendor: допиши в `.swissknifeman/config.json` → `skiller sync`.
- **Сменить набор плагинов** → отредактируй `enabledPlugins` в `.claude/settings.local.json`
  (оставь релевантные стеку), либо `skiller connect --plugins …` (перезаписывает).
- **Поменять память/namespaces** → `maind onboard azguard --memory … --namespace …`.
- **Перераскатать артефакты** → `maind sync --project azguard` (или `--only mcp,graph,…`).
- **Проверить связь** → `maind health` (round-trip-гейт), `skiller status`, `maind doctor`.
- **Проверить always-on бюджет** (перед добавлением скилла/агента/CLAUDE.md-блока/MCP-сервера) →
  `skiller budget --first-turn` (per-project, target ≤7K/warn >10K); флот — `maind doctor --context`;
  тренд по сессиям — `analyst sessions`.

Полное дерево всех слоёв — в доке maind «Дерево решений: настройка проекта»
(`packages/maind/docs/guide/onboarding-decision-tree.md`).

## Связи проекта

- namespaces: `axioma` · scope: `project:azguard` · связан с: chatom.
- актуальные связи: `maind graph --project azguard`.
