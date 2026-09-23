# Вход владельца и принятая граница

2026-09-22: владелец указал `audits/2026-09-22-audit.md` и запросил
`task plan design основного слоя`; «детализация каждой фазы будет через модели sol»;
«все перепроверяй, perplexity в помощь, от себя тоже идеи добавляй».

В plans/ACTIVE.md не было активного плана. Запрос интерпретирован как создание
основного contract-first дизайна, с проверкой аудита, собственными дополнениями и
последующей детализацией отдельных фаз на gpt-5.6-sol/high.
Прикладная реализация, релиз и изменения соседнего Chatom не выполняются.

Среда: cwd /home/vostrikov/projects/packages/azguard; danger-full-access, approval=never.
Модель автора — GPT-6 по контексту сессии, точный selector/effort не аттестован.
Нельзя подписывать эту работу как выполненную sol.

Ограничения окружения: generated skills и AGENTS имели существующие изменения;
AGENTS содержит нерелевантные AzGuard umbrella-разделы. Web tool недоступен, direct
HTTPS работает. Подробности и validation evidence — `findings/verification.md`.

## Совместимость установленного Task

При первом lint выявлены расхождения shipped snippets и исполняемой v2 schema:
Version/Update Log живут в generated status, assurance impact называется
security-external, Integration Seams требуют stable IDs. План приведён к schema.
Линтер также требует frontier bundle первого Not started item даже у skeleton phase;
такой bundle содержит только реально существующие code inputs и не разрешает
исполнение скелета. Готовность design P1 проверяется отдельно.
Owning Task source/generator в этой задаче не изменяется.

При finalize-design обнаружено ещё одно ложное срабатывание: readiness проверяет
наличие любого непустого символа после каждого field header и считает канонический
placeholder «—» полной детализацией. Поэтому последнее поле Deliverables оставлено
действительно пустым до sol-design. Смысл skeleton сохранён; lint допускает пустое
поле, а handoff readiness правильно разрешает design. Это локальный format workaround,
не разрешение исполнения и не исправление owning Task bug.

Finalize-design также выдал ready_items=P1.1,P1.2 при наличии skeleton marker и
пустых Files/Validation/Deliverables. Это означает лишь возможность собрать context
bundle в текущей реализации инструмента; execution readiness в этом плане не заявлена.
Семантический Next остаётся design P1. Полагаться на этот ready_items как допуск к
исполнению нельзя; owning defect локализован в Task readiness/finalization.
