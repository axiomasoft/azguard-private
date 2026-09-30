> **Пересмотр D80–D83:** текущие roles/filter/plugin/authority контракты находятся в [19](../19-oop-and-permission-authority.md)
> и [20](../20-process-map.md). Предыдущие findings/research ниже — история проработки. Строковые profiles, DB роли
> и policy OR grants больше не входят в target. Старый reference model не является proof новой формулы.

# Пятый проход: CRM и композиция доступа — 2026-09-30

## Граница работы

Это доработка целевого контракта 1.0 в `opus/`, а не реализация пакета. Исходная точка — commit
`0a03050`; `01-review.md`, исходные probes и их результаты сохранены как историческое свидетельство
аудита 0.3. Новые D59–D79 уточняют проект; новые V86–V107 — требования к будущей реализации.

Владелец уточнил обязательные сценарии: роли одного человека в нескольких организациях; назначение
на проекты; ProjectContext с собственным контрактом; совместная работа политик и динамических выдач;
сторонние tenant providers; панели без коллизий; отделение ресурсов от механизмов корня;
название Domain не подходит; предлагается `for` вместо `subjects` в билдере.

## Найденные пробелы и принятые решения

Ниже рассматриваются пробелы **исходной целевой спецификации**, а не новые подтверждённые runtime bugs.
При реализации owning items из 13 и scenarios из 14 проверяют каждое решение.

| # | Пробел исходного проекта | Уточнённый контракт | Где |
|---|---|---|---|
| H01 | Один context не выражает одновременно tenant membership и project assignment; глобальная роль могла пониматься как доступ ко всем организациям | TenantRef + ContextRef независимы; global context означает tenant-wide, global tenant не означает wildcard; ownership обязателен | D59, 08 §1, 09 §3 |
| H02 | Store/Project модель сама по себе не описывает тип области роли и принадлежность tenant | ContextDefinition/BaseContext; stable alias, exists, tenantOf; roles явно ссылаются на descriptors; required context проверяется при записи | D60, 05 §6, 06 §7, 16 §3–4 |
| H03 | Union всех разрешений до условий допускает составной Allow от двух несовместимых выдач | Scope/expiry/условия AND внутри одной выдачи, затем OR выдач; RoleContribution сохраняет даже роль без permissions | D61, 09 §2/6 |
| H04 | Каталог динамических ролей и административные readers не имели достаточного tenant scope | Dynamic definitions scoped panel+tenant; GrantManager scoped panel+tenant+origin; чужие ids не раскрываются; static names reserved | 05 §2, 06 §1, 08 §2 |
| H05 | Обычный Laravel Manager кэширует driver по имени; instance с CurrentUser мог пережить request или попасть в другую панель | Immutable definitions/factories и scoped execution adapters; make не использует общий driver(name) cache; Stable требует dependency revision | D62, 06 §1, 09 §12 |
| H06 | Sync/revoke не различали ручные и импортированные назначения | Origin входит в identity; sync меняет только свой partition; внешние source ids/mappings принадлежат host integration | D62/D67, 08 §2, 16 §10 |
| H07 | Валидация до pipes/transaction допускает удаление роли между проверкой и вставкой назначения; bulk writer может обойти контроль | State row lock первым; final structural validation после pipes в transaction; все записи через один orchestration; actor delegation отдельно от структуры | D63, 08 §4, 09 §13 |
| H08 | Версия панели сама по себе не доказывает согласованный cold read, актуальность membership или срок выдачи | Tbefore/data/Tafter fence; bounded retry; incarnation/build fingerprint; expiry на каждый check; request/check refresh честно различаются | D64, 09 §8 |
| H09 | Одно StateToken на batch или nested commit обещает больше, чем реально обеспечивает БД | DecisionSet.states по storage/panel; validated reads каждой группы; nested changes pending до root commit; внешние зависимости не объявляются snapshot мира | D64, 08 §4, 09 §9 |
| H10 | Token source как обычный grant мог расширить authority пользователя; additive Gate мог вернуть отказавшее право | Token restriction = user authority AND credential cap; tenant panels authoritative; unknown qualified abilities deny; ранний внешний Gate::before требует host discipline | D65, 06 §2, 09 §7 |
| H11 | FiltersQueries источника даёт кандидатов, но не учитывает финальные policy/hook/restriction решения | Exact visibility строит полный decision predicate до count/pagination; paired FiltersAccessQueries; unsupported throws; cross-connection SQL не обещается автоматически | D66, 09 §10, 16 §9 |
| H12 | Частичная внешняя синхронизация могла выглядеть как пустой source; event afterCommit мог считаться гарантированной доставкой | Mapping provider+installation+external ids; source revision; completed snapshot только; scoped origins; outbox при требовании durable integration delivery | D67, 08 §6, 10, 16 §10 |
| H13 | Native controller Middleware attribute отсутствует в части заявленной матрицы; абстрактная DDL width оценка не проверяет реальные engines | Version adapters 11/12/13; только Composer-разрешимые fixtures; реальные DDL/index/concurrency gates до релиза; модель ниже не выдаётся за runtime proof | D69, 12 §3, V105 |
| H14 | Orders/ в корне конфликтует с Sources/ и другими механизмами; Users не отличает получателя прав от объекта действий | Permissions/<Group>, параллельные Policies/Queries/Abilities; Contexts/ProjectContext отдельно; User в for([...]) — subject, Permissions/Users — действия над User; #[Resource], make:permission; pairing по discovery root/relative group/FQCN | D70–D72, 00 §13, 04, V106 |

### Выбор структуры и for

Perplexity получил описание задачи без предположения о знании AzGuard. Первые два запроса: композиция
tenant/project/roles и структура папок/терминология builder. Во втором предложены Resources и metadata Resource;
для builder он предпочёл subjects. Проект выбрал for по предложению владельца с разными receiver типами.

После предложения владельца Permissions/Orders выполнен третий запрос: сравнение Resources/Orders,
параллельных Permissions/Policies/Queries и всех классов под Permissions. Perplexity предпочёл параллельные
корни; это мнение о naming, не доказательство удобства для команды. Проверен текущий PolicyDiscovery.php: он
учитывает путь при поиске модели, следовательно новую структуру нельзя считать уже поддержанной старым runtime.
Laravel documentation допускает собственное discovery правило/явную регистрацию, не предписывает наш layout.

Владелец после обсуждения **подтвердил** Permissions/Orders с параллельными Policies/Orders и Queries/Orders (D72).
Преимущество выбранного layout — на один уровень меньше, тип класса задаётся корнем, коллизии механизмов остаются
устранёнными. Цена — чтение одного объекта в нескольких корнях. Сравнительный usability benchmark не заявляется.

Relations обозначает связи и используется RelationSource. Business models остаются в host app/Models.
Metadata #[Resource(model:)] описывает объект действий; папки Resources и дополнительного обязательного
descriptor класса для группы нет. make:permission [--policy] [--abilities] создаёт параллельные файлы.
Автопоиск ограничен своим discovery root; несколько кандидатов требуют точной enum FQCN привязки.

## Внешние первичные источники

Открыты 2026-09-30. Perplexity — поиск/синтез, нормативные решения выше принадлежат этому проекту.

| Источник | Поддерживаемый факт / граница применения |
|---|---|
| [OpenFGA: organization context](https://openfga.dev/docs/modeling/organization-context-authorization) | Organization context следует явно включать в модель доступа; не доказательство корректности нашего scope codec |
| [OpenFGA: search with permissions](https://openfga.dev/docs/interacting/search-with-permissions) | Проверка известных объектов и поиск доступных объектов — отдельные способы работы; фильтрация/поиск имеют собственные ограничения и стоимость |
| [PostgreSQL 13: isolation](https://www.postgresql.org/docs/13/transaction-iso.html) | Read Committed использует snapshot statement; несколько SELECT не превращаются в единый snapshot только от наличия version row |
| [SpiceDB: consistency](https://authzed.com/docs/spicedb/concepts/consistency) | Consistency token и выбранная freshness имеют конкретные границы; внешняя consistency модель не переносится автоматически на наш StateToken |
| [Laravel 13: authorization](https://laravel.com/docs/13.x/authorization) | Gate before может завершить проверку ранним non-null; hooks и policy responses требуют явной adapter semantics |
| [Laravel 13: Sanctum](https://laravel.com/docs/13.x/sanctum) | Token abilities не заменяют проверку полномочий пользователя; tokenCan для first-party SPA возвращает true |
| [Filament 5: resources](https://filamentphp.com/docs/5.x/resources/overview) | Resource описывает работу с моделью; название подходит для групп действий, но не навязывает наш namespace/layout |

Локально сверены `Illuminate/Support/Manager.php` и Laravel13
`Routing/Attributes/Controllers/Middleware.php`: driver caching и custom creator signature объясняют factory
contract; наличие Middleware в текущем vendor не доказывает его наличие в 11/12. Существующие источники пакета
использовались для понимания старой структуры; полного нового аудита всего PHP runtime здесь не заявляется.

## Выполненная ограниченная проверка

Команда: `python3 audits/2026-09-29-audit/opus/evidence/design-model.py`.
Наблюдавшийся результат: **8 tests, OK**, 0.042 s. Standard library unittest + SQLite in-memory.

- 4096 сравнения множеств scalar allowed ids и exact SQL ids, 16 384 scalar record evaluations.
- Scope panel/tenant/project, dynamic/static materialized roles, policy deny, empty-permissions superadmin,
  account/token caps, expiry boundary.
- Несовместимые conditions двух выдач не создают составного Allow.
- Origin partitions/full-key unique; composite owner FK в host fixture; rollback/version; incarnation fence.

Это самостоятельно реализованные две формы ограниченной модели одного сценария. Модель не вызывает пакет,
не создаёт целевую DDL из 08, не проверяет arbitrary PHP policies или реальную concurrency PG/MySQL/MariaDB,
не подтверждает Composer/Filament/Octane/queue runtime. V86–V107 остаются будущими gates.
Исторические 871 baseline tests и 19 probes в старом evidence в этом проходе не перезапускались.
`python` отсутствует в окружении; использован доступный `python3`.

### Целостность документов

Команда: `python3 audits/2026-09-29-audit/opus/evidence/validate-dossier.py`.
Проверены локальные ссылки/anchors в целевых top-level документах (исторический 01 исключён),
уникальность и полнота D01–D79, V01–V116 со снятыми V40–V42, C01–C22 и ссылки на owning items Pn.m.
Это проверка ссылок/нумерации, не доказательство смысловой полноты; новые сценарии не помечаются passed.
`git diff --check` проверяет whitespace итоговой правки.

### Проверка утверждённой раскладки D72

При изменении структуры дополнительно проверены отсутствие старых Resources/<Group>/<Type> paths и
Resources trees в целевых документах, discovery.resources setting и актуальный enum/policy pairing.
Исторический 01 и настоящие namespaces Filament Resources не переименованы. V78/V106 расширены как
будущие runtime требования; проверка документации не объявляет их реализованными.
Математическая CRM модель не менялась и повторно для перестановки папок не запускалась.

### Сверка переименований и builder permissions (D73)

После нового запроса владельца источники и дополнительные enum definitions подключаются одним
PanelBuilder::permissions([...]); прежняя двойственность метода устранена. Найденные остатки и canonical
receivers перечислены в [rename-consistency](rename-consistency.md). V107 добавлен как будущий runtime gate.
Source factory/config и Filament Resources не являются прежними названиями builder/layout.
