# P4.23 — вердикт Grok

Вердикт: **GREEN**. Исполнение остаётся за Codex (`e46362e`, attempt `P4.23-final-01a112c6`). Этот проход — независимый review в сессии `01a112fe-1871-7380-b804-b68e0ce44e37`, grok-4.7/high.

До правки README все 20 путей `review-candidate.json` совпали с деревом. Старые `review-*-result` пустые: это история незавершённого вызова, не вердикт.

## Находки

Блокеров и Major нет. Единственная подтверждённая неточность — Minor в `tests/Acceptance/Crm/README.md`: текст называл host-индекс `crm_clients_tenant_project` для обоих движков. Сохранённый MySQL EXPLAIN выбирает `clients_project_id_organization_id_foreign`. Предложение исправлено. `possible_keys` по-прежнему не считаются доказательством.

## Что проверено по diff `e46362e`

- Общий `PredicateCompiler` живёт только внутри одного `Visibility::build`. Ключ кэша колонок — имя соединения и таблица. `bounded()` и пустой subject создают отдельные компиляторы.
- Отказ tenant membership поднят в общий AND. Before не умеет коротко разрешить доступ, поэтому пустой список совпадает с прежним `1 = 0` внутри каждой ветки.
- Общий context-предикат сужает выборку вместе с фильтрами роли на каждой contribution. Оракул 8000 id сохраняет покрытие analyst для чужого города.
- Scalar/query parity сравнивает полный набор id и count на одном замороженном времени. Литеральные CRM id совпадают с seed: view A `[1,2,3]`, B `[5]`, update A `[1]`, policy-only так же.
- Unsupported-компоненты и external `model=null` прерывают сборку до запроса `clients`. Тесты проверяют журнал запросов и неизменный SQL вызывающего.
- EXPLAIN: authority обоих движков выбирает `azg_rg_subject`. Селективный host-план PostgreSQL выбирает `crm_clients_tenant_project`. Полный список PostgreSQL при ~80% выборке идёт через Seq Scan `clients`; это выбранный план, а не `possible_keys`.
- Бюджет 10k/100: 14 SELECT, 2 assignment, 4 resource, 1113 параметров, 87753 байт SQL. Chunk 500 — `DatabaseSource::chunkById(500)`.

## Проверки

V1–V12 исходной квалификации не перезапускались: код авторизации и тесты не менялись. Повторно выполнен только `git diff --check -- tests/Acceptance/Crm/README.md`, exit 0.

Неизменённые Filament/Redis/replica/fleet по ограничению владельца не запускались и не считаются пройденными. Поэтому статус остаётся `done_with_deviations`. Pending по отсутствующему Grok review снят.
