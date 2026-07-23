# Roadmap исполнения — <PLAN-ID>

<!-- Собирается ПОСЛЕ полной детализации всех фаз (SKILL §5 дефолт, §8 канон roadmap).
     Отвечает на «какую команду дальше?» без анализа всего плана. Живой документ:
     закрытие фазы / re-design обновляет таблицу (+строка в Update Log плана).
     Правила: Batch = последовательные мелкие items одной модели со сцепленным scope —
     одна сессия, одна команда; solo — большой/сложный/рисковый (обоснование в Примечании
     ОБЯЗАТЕЛЬНО); workflow — независимые по scope items (скрипт в plans/<ID>/workflows/).
     Roadmap не переопределяет Routing §3 (модели/effort) — только группирует запуски. -->

**Обновлён:** <YYYY-MM-DD> · **Соответствует plan.md:** v<X.Y.Z>

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|---|---|---|---|---|---|
| P0.1 | B1 | plan-exec серия | implementation/medium | — | сцеплен с P0.2 по файлам |
| P0.2 | B1 | ↑ | implementation/medium | — | |
| P1.1 | solo | plan-run (manual) | frontier/high | ✅ утверждение матрицы | design-item, объединять нельзя: вердикт владельца посреди; provider selector выбирает renderer |
| P2.1–P2.3 | W1 | orchestration | implementation/medium | — | независимый scope → semantic orchestration carrier |
| … | | | | | |

## Готовые launch-block'и групп

### B1 — <суть батча>

| Параметр | Значение |
|:--|:--|
| Model class | <economy / implementation / frontier> |
| Effort | <low / medium / high / xhigh> — <причина> |
| Capabilities | <semantic capability IDs> |
| Context | cold-start: plan.md → roadmap.md → phases/<Pn>.md |
| Суть | Серия <Pn.a>–<Pn.b> одной сессией, гейты между items — внутри сессии |

```
exec-items: task:plan-exec <PLAN-ID> <Pn.a> <Pn.b> <Pn.c>
```

<!-- Батч-серии — ТОЛЬКО через plan-exec (список items): её минимум
     implementation/medium — жёсткий гейт тира. task:plan-run — inherited-plan,
     для manual design-items под проверенным route сессии. -->

### W1 — <суть workflow-группы>

```
kind: orchestration
orchestration_id: <PLAN-ID>:W1
inputs: [plan.md, roadmap.md, phases/<Pn>.md]
required_capabilities: [command.subagents, command.workflow-carrier]
provider_projection: <verified mapping reference>
```

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| <Pn.m> | <артефакт> | <какие batch'и ждут> |
