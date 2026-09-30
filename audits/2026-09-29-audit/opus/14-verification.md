# 14 — Сценарии проверки

Каждый сценарий ниже — **требование к будущему** исполняемому тесту (Pest), если не помечено «стенд». Эти строки не являются отчётом о выполненных тестах. Колонка «Probe» — какой probe из
[evidence](evidence/README.md) сценарий закрывает: probe переписывается на новый API с обратным ожиданием (дефект
невозможен). Пункты — из [13](13-workstreams.md).

## Имена и идентичность

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V01 | Property: для случайных `(type, id)` без `:` в типе `ContextRef::key()` однозначен; `of('w', 7) ≡ of('w', '7')`; `of('w:a', 7)` → `InvalidContextException` | P1.1 | P07 |
| V02 | Property: `PermissionKey::parse($k->full()) ≡ $k`; одно слово (`view`), верхний регистр, пробелы, голые `*`/`**` → `InvalidPermissionKeyException` | P1.1–P1.2 | P14 |
| V03 | Таблица шаблонов: `orders.*` покрывает `orders.view`, но не `orders.a.b`; `orders.**` — всё под `orders.`; `**` не последним сегментом → ошибка | P1.2 | — |
| V04 | Digest ключей кэша для разных `(panel, subject, contexts)` различен (1e5 случайных) | P1.1, P4.8 | P07 |

## Панели

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V05 | Повторная регистрация панели → `DuplicatePanelException`; `replace()` до заморозки работает; после `booted` → `RegistryFrozenException` | P2.1 | Codex C08 |
| V06 | id панели `''`, `a.b`, `*`, `A`, `a b`, `a:b` отвергаются | P2.1 | Codex C08 |
| V07 | Enum прав, не подключённый к панели → ошибка при проверке; enum в двух панелях без явной панели → `AmbiguousPanelException` | P2.1, P2.6 | — |
| V64 | Свойство P9: для матрицы (субъект, имя права, явная панель, текущая панель, панель по умолчанию) трейт, `SubjectAccess`, фасад, Gate, Blade, `azguard.can`, `decideMany`, CLI выбирают одну панель | P2.2 | P01c, P09 |
| V65 | Панель по умолчанию: одна панель у модели → она; `->default()` у двух панелей одной модели → `DefaultPanelConflictException`; `azguardDefaultPanel()` переопределяет; без панели → `PanelNotResolvedException`, а не отказ | P2.2 | P05 |
| V08 | Два источника (или источник и плагин) с одним именем права и разными подписями → `DuplicatePermissionException` с id источников; одинаковые → ок | P2.5 | — |
| V81 | Префикс: по умолчанию — id панели (`admin.orders.view`); `prefixed('backoffice')`; `prefixed(false)`; свойство P11 (смена префикса не меняет решений для enum и полных имён); повтор префикса или совпадение с первым сегментом локального имени → `PrefixConflictException`; плагин `prefixed('blog')` в панели с префиксом → `admin.blog.posts.edit` | P2.2 | — |
| V77 | Фабрика источников: `->permissions(['ldap'])` работает через `#[AsSource('ldap')]` и через `AzGuard::sources()->extend()` с параметрами из `config('azguard.sources.ldap')`; неизвестное имя → `unknown_source`; два писателя → `writer_conflict`; два источника с одним `id()` → ошибка; каждая панель получает свой экземпляр; свойство P12 (перестановка источников не меняет решений) | P2.7 | — |
| V78 | Папка панели: сгенерированная `azguard:make:panel` + `make:permission --policy` панель находит enum в `Permissions/Orders`, политику в `Policies/Orders` по правилу same-root/same-relative-group D56, затем «метод = кейс» без `#[PolicyFor]`, роли в `Roles/`; `->discover()` добавляет папку; конфликтующие model/action bindings → `invalid_policy_structure`; nested permission names с разными сегментами допустимы при явном Decides; `azguard:catalog:cache` даёт тот же каталог, что живой автопоиск; `Shared/` не становится панелью | P2.8, P6.8 | — |
| V47 | Настройки: провайдер побеждает плагин, плагин — `configurePanels()`, тот — конфиг; `panels:list --settings` показывает источник; конфликт двух плагинов → `PluginConflictException`; гарантию D45 не отключить; context predicates из defaults/plugins/provider складываются AND, presentation имеет precedence | P2.3 | — |
| V48 | Плагин без зависимости → `plugin_dependency_missing`; изменение панели в `boot()` → ошибка; смена порядка плагинов меняет отпечаток; один плагин на двух панелях видит каждую свою | P2.4 | — |
| V85 | Плагин как Laravel-класс: `make()` создаёт экземпляр через контейнер (работают привязки и `#[Config]`); один плагин на двух панелях с разными настройками (`retention(days:)`) не делит состояние; плагин добавляет источник, ограничение и pipe в `register()`; миграции плагина — из его `ServiceProvider` | P2.4 | — |
| V55 | Модуль: `configurePanel('admin')` + `prefixed('blog')` → права `blog.*`; два модуля с одинаковым локальным именем не сталкиваются; неизвестная панель → `unknown_panel` | P2.6 | — |

## Хранилище, свои модели и поля

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V51 | Своя модель `AdminRoleGrant`: `grantRole(fields: ['department_id' => 7])` сохраняет колонку; поле `weekdays` уходит в `meta`; неверное значение отклоняется по `azguardFields()`; неизвестное поле → `InvalidChangeFieldsException`; модель не наследует базовую → ошибка сборки | P3.3 | — |
| V52 | Панель в именованном хранилище (другое подключение и префикс): запись и версия в одной транзакции этого подключения; панели в разных хранилищах не блокируют друг друга | P3.1 | — |

## Источники прав

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V09 | Переименование класса роли из кода (ключ в `#[Role]` тот же) → выдачи работают без каких-либо команд | P4.2 | P02 |
| V10 | Смена ключа роли из кода с `#[FormerKeys]` → выдачи по старому ключу действуют; `roles:rename-key` переписывает их | P4.2, P5.3 | — |
| V11 | Выдача роли, которой больше нет в PHP-каталоге → проверка не бросает, прав не даёт, doctor показывает | P4.2 | P02 |
| V66 | Автоматическая роль: `SellerRole::appliesTo()` → роль есть у всех с магазином и нет у остальных; `SellerRole` можно дополнительно выдать вручную — права складываются; `RootRole` с `#[NotGrantable]`: `grantRole('root')` → `RoleNotGrantableException`; `touch()` сбрасывает кэш после изменения данных | P4.2 | — |
| V79 | Атрибуты роли: `#[Role('manager', label:, level: 10)]` задаёт ключ, подпись (через `__()`), порядок; без `#[Role]` требуется explicit stable key() override, иначе DefinitionException; `#[SuperAdmin]` = `superAdmin(): true`; метод, переопределённый в классе, побеждает атрибут | P2.8, P4.2 | — |
| V67 | Mode-aware exact policy binding/Decides/resource DI; RequiresGrant true/null pass, false veto; PolicyOnly sole policy/null deny; GateSource no owned recursion | P4.3 | — |
| V68 | Enum RequiresGrant assignments через PHP role или direct DB без definition копии; PolicyOnly assignment запрещён; нет ConsultsGrants fallback | P4.3 | — |
| V80 | Таблица 09 §5: refund assignment AND working-hours veto; own order PolicyOnly без grants; mode не определяется наличием policy; declared missing binding error; Gate model checks obey mode | P4.3 | N19 |
| V69 | Связь сущностей: участник `project.members` с pivot-ролью `editor` получает права роли `editor` в `project:7` и не получает в `project:8`; `visibleTo` использует ту же связь | P4.5 | — |
| V50 | API пользователя: token abilities ограничивают grants (AND), service principal capabilities — отдельный trust mapping; token без user grant ничего не разрешает | P4.1, P2.7 | — |
| V74 | Свойство P10: перенос права из `#[GrantedToAll]` в роль `DatabaseSource`, выданную тем же субъектам, не меняет решений | P4.2, P4.4 | — |
| V82 | Динамические права: с `DatabaseSource::make()->dynamicPermissions()` право создаётся (`permissions()->create()`), выдаётся и проверяется; имя, совпадающее с именем из enum → ошибка; удаление забирает выдачи одной транзакцией с событием `PermissionDeleted`; без флага `permissions()->create()` → `PanelNotWritableException`; `rolesOnly()` → `grantPermission()` отклоняется | P4.4, P5.3 | — |

## Контексты

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V12 | Таблица [09 §3](09-authorization-semantics.md#3-тенант-контекст-и-ресурс) целиком (4 политики × 3 столбца) и таблица «`on:` → контекст и ресурс» | P4.6 | — |
| V13 | `isolated(Store::class)` в `seller` и `none()` в `admin`: ambient `store:1` не применяется к none(); явный on: store в none() отклоняется | P4.6 | P06, P06b |
| V14 | `withinContext()`: исключение внутри callback и исключение резолвера на входе → прежняя сущность восстановлена | P4.6 | Codex C02 |
| V16 | Tenant membership: Anna в A/B и outsider из [16](16-crm-and-workflows.md); исключение в членстве → `Deny(RestrictionError)` | P4.6 | — |

## Пайплайн проверки

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V15 | Property-тесты P1–P12 ([09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм)) на генерируемых источниках и выдачах, в двух панелях | P4.1 | P01a, P08 |
| V53 | Шаги: ограничение не может разрешить; исключение в источнике → `Deny(SourceError)`, в политике → `Deny(PolicyError)`, в ограничении → `Deny(RestrictionError)`, в before-хуке → `Deny(HookError)`; исключение after-хука не меняет решение; before-хук «нет» останавливает проверку | P4.1 | — |
| V54 | `DatabaseSource::make()->decisionFields(...)`: `WeekdaysCondition` видит `weekdays` выдачи, давшей право; необъявленное поле ограничению недоступно и в кэш не попадает | P3.3, P4.1 | — |
| V17 | Scoped code SuperAdmin grants только Grants mode; tenant/context/expiry/conditions/common owner и attached policy veto обязательны; PolicyOnly не использует роль | P4.7 | P01a, P14 |
| V18 | Кэш: после commit отзыва новая проверка (новый запрос) → Deny; при `state_refresh = check` → Deny в том же запросе; внутри незакоммиченного изменения — видно своё изменение | P4.8 | P10b |
| V19 | Бюджеты D44: повторный grants read = 0 DB authority queries; live policy/membership cost отдельно; 10 проверок при тёплом кэше и `state_refresh = request` = 1 запрос версии | P4.8 | P10 |
| V49 | Свойство P3: у `admin` и `seller` разные хранилища и кэш; изменение в `seller` не меняет `StateToken` панели `admin`; выдачи `seller` не влияют на решения `admin` | P4.8, P2.1 | P01c |
| V27 | Owned permission NotGranted/Policy deny final, no additive native fallback; foreign ability untouched; early Gate.before integration documented/tested | P4.11 | — |

## Gate и видимость

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V28 | Authoritative: своё право без выдачи → `false`, даже если есть Laravel-политика с тем же именем; ability из одного слова (`update`) → `null`, политика модели работает | P4.11 | N19 |
| V29 | `Gate::allows('admin:users.ban')` на маршруте панели `seller` → решение явно указанной панели `admin`; явный guard('seller') вместе с admin: -> конфликт; `Gate::allows('users.ban')` на том же маршруте → панель `seller` | P4.11 | P09 |
| V30 | Подменённые хуки и источники видны одинаково через трейт, фасад, Gate, `@can`, `azguard.can`, `decideMany`, `azguard:explain` | P4.11, P5.1 | P03 |
| V31 | `visibleTo`: без субъекта → пусто; пользователь без выдач и без глобального права → пусто | P4.12 | P04a, P04b |
| V32 | `visibleTo`: роль в двух проектах → видны оба; tenant-wide право → все внутри выбранного tenant при exact restriction/policy predicates | P4.12 | P04c |
| V33 | `visibleTo` на 100 000 строк использует индекс по сущности выдач (EXPLAIN на PG/MySQL) | P4.12 | — |

## Модель и изменения

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V70 | Рецепты «Если вы пришли из Spatie»: `grantRole`, `revokeRole`, `syncRoles`, `grantPermission`, `revokePermission`, `hasRole`, `hasAnyRole`, `hasPermission`, `roleNames` работают в панели по умолчанию и через `guard()` | P5.1 | — |
| V20 | Изменение без актора проходит, в событии `actor = null`; `AzGuard::actingAs($user, …)` и текущий пользователь попадают в событие; в консоли актор — `system` с именем команды | P5.2 | — |
| V21 | Проверка данных: опечатка в роли → `UnknownRoleException`, в праве → `UnknownPermissionException`; тип сущности не принят → `ContextNotAcceptedException`; панель без источника-писателя → `PanelNotWritableException` | P5.2 | P13 |
| V22 | Голые `*`/`**` не принимаются ни в роли БД, ни в прямом праве; суперадмин появляется только через роль с признаком суперадмина | P5.2, P4.7 | P14 |
| V56 | Pipe `changing` (`handle($change, $next)`, создаётся контейнером) отменяет изменение → нет строки, нет события, версия не меняется; pipe ставит срок по умолчанию → сохраняется срок; слушатель `RoleGranted` получает событие только после commit | P5.2 | — |
| V57 | DelegationPolicy приложения (scope/pattern/role/superadmin), а не literal hasPermission(pattern), (pipe из [06 §5](06-extension-points.md#5-хуки-изменений-pipes-и-события)) отклоняет выдачу; суперадмин проходит | P5.2 | — |
| V71 | RoleCatalog immutable code roles all/find; definitions CRUD отсутствует; grant fingerprint code build+row state; FormerKeys explicit scoped migration | P5.3 | — |
| V34 | Каждое изменение: событие после commit, не при откате; повтор без изменения — без события; `eventId` уникален; payload без моделей | P5.4 | P11 |
| V35 | Плагин `azguard/audit`: строка журнала в той же транзакции (откат → нет строки) | P5.4 | — |
| V72 | PanelSchema: permission authority/owner/policy/sources/dynamic; PHP role class/readonly/grantable/automatic/contexts; field schema/plugin metadata; no Runtime Models/Closures | P5.5 | — |

## Laravel-поверхность

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V73 | `azguard.panel:seller`: пускает субъекта панели с правом входа и суперадмина; не пускает без права и субъекта другой модели; внутри короткие имена относятся к `seller` | P6.2 | — |
| V83 | `#[CheckPermission]` на методе и на классе (`only:`) — роутер Laravel вешает `azguard.can` без своего сканера; `on: 'order'` передаёт модель маршрута; строгий режим: действие без проверки → `MissingPermissionCheckException` в testing и 403 в production; Laravel `#[Authorize]` и `can` засчитываются; `#[SkipPermissionCheck]` пропускает; doctor `routes.checks` показывает то же заранее | P6.2 | — |
| V84 | Механизмы Laravel: `php artisan optimize` строит кэш каталога, `optimize:clear` чистит; `about` показывает раздел AzGuard; job, поставленный из запроса `azguard.panel:admin`, относит короткие имена к `admin` (через `Context`), job из консоли — к панели по умолчанию | P6.10 | — |
| V36 | Проверки при загрузке из [07 §5](07-configuration.md#5-проверки-при-запуске) — каждая строка таблицы | P6.3 | — |
| V37 | `azguard:install`: без `--migrate` не запускает `migrate`; ошибка `migrate` → код выхода ≠ 0 | P6.5 | Codex C10 |
| V38 | Octane (стенд): два запроса разных субъектов, панелей и сущностей на одном воркере — нет утечки текущей панели, сущности и кэша | P4.6, P4.8 | — |
| V39 | Queue: job без явной сущности не видит сущность прошлого job; действует панель запроса, поставившего job, иначе панель по умолчанию; `withinContext` в job | P4.6, P6.10 | — |

## Filament

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V23 | Livewire-payload с `class_name`/`definition` в `RoleResource` отвергается; роль из кода нельзя изменить из UI | P7.3 | N02 |
| V24 | Две Filament-панели с разными `guardPanel`: конфиг не перезаписывается, решения независимы | P7.1 | N18 |
| V25 | Страница с `AuthorizesPage` при `enforce`: пользователь без права и без `AzGuardSubject` → 403 | P7.2 | N18 |
| V26 | Поиск субъекта среди 10 000 пользователей: 1 запрос с `LIMIT 50`; подпись субъекта при morph map корректна | P7.4 | N18 |
| V61 | RoleResource read-only PHP catalogue; SubjectGrants editor grants only RequiresGrant; PolicyOnly badge/no checkbox/raw reject, dynamic actions opt-in | P7.3 | — |
| V75 | FilamentDefinitions Enums/Resources отделены от authority; assignments этих PHP/build definitions без dynamicPermissions; generators modes explicit/stubs safe; owner collisions reject | P7.1, P7.7 | — |
| V76 | Динамическое право создаётся в `PermissionResource`, выдаётся и проверяется; удаление забирает выдачи | P7.3 | — |
| V62 | Форма выдачи показывает поля своей модели и плагина; при смене панели набор полей меняется; неизвестное поле отклоняется на сервере | P7.5 | — |
| V63 | Панель без `DatabaseSource` не показывается в редакторах, но видна на странице «Панели»; действие «Почему?» показывает `explain()` | P7.4, P7.6 | — |

## Интеграции

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V58 | `IntegrationContractTests` зелёные на примере интеграции: решение одинаково через трейт, `decideMany` и Gate; `StateToken` меняется после выдачи и отзыва; события после commit | P8.1 | — |
| V59 | Пример интеграции подключён к двум панелям: права не утекают; вызов без явной панели → `AmbiguousPanelException` | P8.1 | — |
| V60 | Имя права без панели в приложении с несколькими панелями и без панели по умолчанию → `PanelNotResolvedException`, а не молчаливый отказ (сценарий моста Vaulter) | P8.1, P2.2 | P05 |

Сценарии самого моста Vaulter — в плане Vaulter. AzGuard со своей стороны гарантирует V58–V60 и бюджет V45.

## Производительность и консистентность (стенд)

| # | Сценарий | Пункт |
|---|---|---|
| V43 | p95/p99 проверки: холодный/тёплый кэш, 1/10/100 выдач на субъекта; с политикой и без | P4.8 |
| V44 | 50 параллельных `grantRole` — нет deadlock без повтора сверх 3, версия монотонна | P3.1 |
| V45 | `decideMany` на 1000 сущностей (сценарий «листинг» интеграции) — в пределах бюджета D44 | P4.9 |
| V46 | Primary + реплика с задержкой: после commit отзыва новая проверка → Deny при `reads = primary`; при `default` — задокументированное окно (F32) | P4.8 |

Номера V40–V42 сняты: обновления данных с 0.3 нет ([D01](02-decisions.md#d01)).


## CRM и устойчивость композиции: пятый проход

V01–V85 остаются; новые случаи **V86–V116**. `Пункт` ниже — owning item из 13, а не объявление реализации.
Стендовые concurrency/query cases обязательны на целевых engines до stable release; reference model в evidence
даёт ограниченное доказательство formulas и SQLite constraints, не заменяет PHP/Filament/runtime tests.

| # | Проверяемый сценарий | Пункт |
|---|---|---|
| V86 | Один user в A/B, разные роли и повторяющиеся local project ids: View/Update не смешивают panel/tenant/context; явные conflicting panel hints -> error | P1.6, P2.2, P4.6 |
| V87 | Две вкладки и два параллельных requests с A/B, immutable inTenant wrappers: выбор одного tenant не меняет другой request/модель | P4.6, P5.1 |
| V88 | Required tenant/resource scope отсутствует или resource/project owner mismatch; even policy=true/hook=true/superadmin -> deny; explicit ContextRef в none не игнорируется | P4.1, P4.6 |
| V89 | ProjectContext class и несколько code BaseRole bindings/typed filters; unknown/foreign/required context reject | P1.6, P4.2, P5.3 |
| V90 | Empty-permissions SuperAdmin RoleContribution действует только scope/expiry; source error после первого разрешающего source даёт deny при любой перестановке; relation pivot role разворачивается через core | P4.1, P4.5, P4.7 |
| V91 | Один BaseRole catalogue в tenant A/B, разные scoped назначения; состав не DB; same key/class уникален; class/key normalization и stale removed role cleanup | P4.4, P5.3 |
| V92 | Opt-in dynamic permission catalogue T fence, static shadow/prefix conflicts reject; exact action delete removes exact direct grants; patterns только Grants известных actions | P2.5, P4.4, P5.3 |
| V93 | Response allow/deny status preserved; Grants policy true не authorizes; PolicyOnly null denies и assignment sources не вызываются; token cap AND, early native Gate hook ограничение | P4.3, P4.11 |
| V94 | Department и weekday должны совпасть в одной grant; добавление двух несовместимых grants не создаёт составного Allow; conditions/error/expiry применимы также к superadmin RoleContribution | P4.1, P4.8 |
| V95 | Exact visibility/scalar same-mode predicate parity до count/page/export; PolicyOnly без assignment reads; Grants authority AND business policy; invalid/cross-connection unsupported | P4.12, P7.2 |
| V96 | Filament/Livewire actor target tenant checks: чужой id grant/role/project/department reject, including bulk/attach/autocomplete/search/widgets/export jobs; tenant switch clears dependent form fields; manages не delegation | P7.3, P7.4, P7.5 |
| V97 | Retry-safe pipes меняют срок/поля -> final validation reject invalid value/forbidden scope; scoped sync/revoke manual не затрагивает другой tenant/context/origin; no-op vs update event, mixed bulk all-or-nothing | P3.1, P5.2, P5.4 |
| V98 | Два provider installations с external id=7 не коллидируют; importer origin не перезаписывает manual; old webhook/retry/partial pagination/timeout не восстанавливает или лишне отзывает доступ; explicit mapping link required | P8.1 |
| V99 | Two-process barriers между T_before, grants chunks, T_after: concurrent grant/revoke/action edit/catalog delete -> целый validated DB набор или ConsistencyError; warm cache primary свежий; stale application snapshot не выдаётся за fresh | P3.1, P4.8, P4.9 |
| V100 | Frozen clock на expiresAt в request memo (не только durable cache): expiresAt=now deny; restore version ниже прежней с новой incarnation не resurrects cache; policy/locked user/token changed при том же T пересчитываются | P4.8 |
| V101 | Actual engine races grant/revoke/action delete/build revision/expectedFingerprint; host owner/active lock protocol; deadlock retry/nested rollback/root commit; no orphan grant resurrection | P3.1, P5.2, P5.3 |
| V102 | Prefix plugin преобразует enum lookup, Role.permissions, Decides и policy bindings одинаково; SourceManager make не shares driver object/scoped CurrentUser между panels/requests; два Filament resources одной модели не угадываются | P2.4, P2.7, P4.3, P7.2 |
| V103 | Octane/queue/sync/CLI/fiber execution: explicit tenant+context rehydrate, deleted/reparented resource denied; scope stack finally; boot-time source не захватывает request; no implicit tenant leakage | P4.6, P6.2, P6.10 |
| V104 | explain и capabilities показывают actual tenant/context/origin/state/conditions; schema scoped dynamic overlay; trace endpoint/search protected, tokens/credentials не сериализуются; unsupported component не рисуется pass | P4.10, P5.5, P6.4 |
| V105 | Actual DDL/full identity uniqueness/origins/pair null/canonical ids; только grant/action/state storage, no role definition tables; archived consumers и CheckPermission matrix | P3.2, P6.8, P8.4 |
| V106 | D72: Permissions/{Orders,Sources,Users,Models,Projects}, параллельные Policies/Queries/Abilities; root Sources не сканируется как группа прав; Resources не автосканируется; generators panel/module/plugin/Filament используют один layout; Sales/Orders и Support/Orders, Orders двух plugins не склеиваются; неоднозначный enum/policy pairing требует FQCN binding; User/Project subject/resource/context различаются; live/cache discovery совпадают | P2.8, P6.8, P8.4 |
| V107 | Все agreed renames в public manifest/examples/generated consumers: PanelBuilder for, permissions mixed input; одна permissions signature без sources alias; enums + Source/name нормализуются, repeated enum дедуплицируется, bad input/duplicate source/writers reject; FolderSource остаётся первым; PanelAccess PermissionManager и source factory различаются по receiver; metadata Resource/resourceGroup и D72 пути/CLI едины | P2.1, P2.7, P2.8, P6.8, P8.4 |

Неизменяемые invariants P13–P16 из 09 проверяются вместе с V15. Для списка N rows генеративный тест
вычисляет scalar allow ids и exact SQL ids **до** pagination и сравнивает оба направления, включая PolicyOnly allow
без grants, explicit SQL NULL и allow/deny/abstain partitions и restrictive policies с grants. Для known deny trace проверяется независимо от UI message.

## Конфигурируемые контексты, operation inputs и готовность CRM

| # | Сценарий | Пункт |
|---|---|---|
| V108 | guard('crm') и SubjectPanels::guard immutable, не меняют Auth; guard(array|string $guarded) сохраняет Eloquent guard(array), named argument, mergeGuarded/fill; custom override конфликт найден consumer; class load/IDE return на всей matrix | P2.1, P5.1, P8.7 |
| V109 | Common is_active + configured Caller/Seller city/region filters: несколько ролей, independent Analyst branch, same witness, policy/hook/scoped superadmin/direct grants | P1.6, P4.1, P4.6, P8.7 |
| V110 | Native Eloquent whereHas/scopes/grouped OR, scalar EXISTS и full exact list/count/export; outside owner/key/common filters immutable; invalid from/connection/root join/builder replacement unsupported | P4.6, P4.12, P8.7 |
| V111 | Actual BaseRole/user/actor/contribution inputs, nullable common/direct/policy-only; no roleModel/global binding/empty User resolution | P1.6, P4.6, P5.3, P8.7 |
| V112 | Active/city/role-field change freshness, phase Access equality scalar/list/job; Assignment revalidation после pipes; inactive/expired/orphan Revocation разрешён delegated actor | P3.1, P4.6, P4.8, P5.3, P8.7 |
| V113 | Typed ContextQueryFilter constructor/composition/class DI, PHP deployment fingerprint; UI cannot edit filters/roles; unknown removed class diagnostic/cleanup | P3.2, P4.2, P5.3, P8.7 |
| V114 | LookupContext directories/autocomplete/description используют target user/role/proposed fields+actor before LIMIT; Inspection/revoke list виден authority actor независимо от runtime eligibility | P5.5, P7.4, P8.7 |
| V115 | Concrete named typed plugin factories, no inherited make/options bag; DTO subtype/model mismatch, two panels/shared recipe, DI/requires/listener/cache lifecycle | P2.4, P6.8, P8.4, P8.7 |
| V116 | R01–R68 actual CRM/consumer suite на real SQL/UI/workers/qualified matrix; positive controls/expected ids/protected writes/query budgets/trace; statuses distinguish future/blocked/unsupported/passed | P8.7 |

V108–V116 — будущие runtime requirements. [17](17-crm-acceptance-tests.md) подробно задаёт бизнес-сценарии и
readiness evidence, [18](18-contexts-and-runtime-inputs.md) — canonical operation/query/plugin contract.


## Строгая ООП-схема и разделение authority

| # | Сценарий | Пункт |
|---|---|---|
| V117 | PHP-only role definitions; code/relation/DB assignments; enum definitions без DB копии; RoleCatalog read-only; no roles/role_permissions/role_contexts storage | P1.6, P4.2, P4.4, P5.3, P8.7 |
| V118 | Actual PHP named typed plugin factories/LSP, ContextQueryFilter objects/class DI, source model override subtype checks, no profile/options registries | P2.4, P4.6, P6.8, P8.7 |
| V119 | Explicit PolicyOnly/RequiresGrant modes: true не bypass grants, null semantics, policy-only irrelevant DB outage, hooks BeforeResult, mode switch/assignment reject/exact visibility | P2.5, P4.1, P4.3, P4.12, P8.7 |
| V120 | CodeStateToken vs StateToken, consumed-dependency mixed batch; process-map build/write/revoke/deploy/worker/UI/external sync gates; R61–R68 | P1.6, P4.8, P5.2, P6.8, P8.7 |

V117–V120 — future runtime requirements; [19](19-oop-and-permission-authority.md), [20](20-process-map.md).
