# Roadmap исполнения — 2026.07.18-AZGUARD-STABLE

**Обновлён:** 2026-07-22 · **Соответствует plan.md:** v0.3.36.

Все delivery-items P0–P5.3 терминальны. Исторические batch/launch-block'и удалены из
живой карты: они не являются командами для повторного запуска. Провенанс исполнения — в
`plan.md` Update Log, `phases/P0.md`–`phases/P5.md` и git-истории.

## Текущий маршрут

| Шаг | Статус | Route | Условие |
|:--|:--|:--|:--|
| P0–P4 | ✅ закрыты и проверены | — | не перезапускать |
| P5.1 | ✅ закрыт | — | канон флота принят после full-review |
| P5.2 | ✅ закрыт | — | `v0.3.0`, GitHub Release и CHANGELOG подтверждены |
| P5.3 | ✅ закрыт | — | root-material перенесён в docs, parity/build прошли |
| Audit P5 | next | `task:plan-audit` | только GREEN разрешает archive |
| Archive | pending | `task:plan-close archive` | после GREEN audit P5 |

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | xhigh — независимый финальный вердикт |
| Context | NEW SESSION — шаг-не-item |
| Суть | Проверить release, docs, status carriers и готовность архива P5. |

```text
$ task:plan-audit 2026.07.18-AZGUARD-STABLE P5
```

## Гейты владельца

| Где | Статус |
|:--|:--|
| P0.6 — backlog | ✅ пройден |
| P5.2 — approve перед push тега | ✅ пройден |
| Archive | только после GREEN audit P5 |
