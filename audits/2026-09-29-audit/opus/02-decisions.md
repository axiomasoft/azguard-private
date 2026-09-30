# 02 — Журнал решений

Каждое решение — **позиция для плана**, а не вариант. Оспорить решение можно через
[15-owner-questions.md](15-owner-questions.md); без ответа действует то, что записано здесь. У каждого решения есть
строка **«Кратко»** — смысл без технических деталей. Ниже неё — спецификация для исполнителя. Обзор всей картины
простыми словами — [00-overview.md](00-overview.md).

Ссылки `Nxx` — находки о текущем коде из [01-review.md](01-review.md), `Cxx` — находки Codex, `Pxx` — probes из
[evidence](evidence/README.md). Probes остаются тестами: новая архитектура должна делать эти дефекты невозможными.

**Третий проход (по ответам владельца).** Панель — **конструктор**. Он собирает права субъекта из разных источников:
папка панели (enum, роли-классы, политики), Laravel Gate, база данных (как в Spatie), связи сущностей, свои источники.
Источники сочетаются в одной панели и меняются без изменения кода проверок (D52–D55). Трейт модели снова умеет и
проверять, и менять права, с простым синтаксисом как в Spatie. Есть панель по умолчанию, поэтому указывать панель
приходится редко (D05, D10, D11). Суперадмин — субъект, которому в панели разрешено всё (D19). Кто может
редактировать права, решает приложение, а не AzGuard (D23). Совместимость с 0.3 не нужна: пакет строится сразу
правильно (D01).

**Основа — сегодняшний AzGuard.** Панели как папки с провайдером, домены с enum и политиками, статичные и
классы ролей, прямые выдачи, роли в сущностях, `#[CheckPermission]`, режимы Filament, explain и doctor — идеи
владельца, и они сохраняются. Досье развивает их и исправляет найденные дефекты; что именно остаётся и как
развивается — таблица в [00 §1](00-overview.md#что-остаётся-из-сегодняшнего-azguard).

**Четвёртый проход (по ответам на Q23–Q29 и отзыву владельца).** Панель — конструктор из **источников**: каждый
источник — отдельный класс (папка панели, база данных, связи, Gate, свои), свои источники регистрируются фабрикой в
стиле Laravel (D52). Панель — папка: провайдер, роли, домены (enum прав + политика), свои источники и плагины лежат
внутри, панель сама находит enum, политики и роли (D56). Выдача — первый уровень проверки, политика того же права
уточняет (D53). Плагины, хуки, проверки на маршрутах и фабрика построены на механизмах Laravel (D47, D55, D58). Своя
система имён для методов, атрибутов, классов и папок: «выдать / забрать» (`grant` / `revoke`) для ролей и прав
одинаково (D57). Префикс имён прав панели (`->prefixed()`) сам указывает на панель (D05). Суперадмин — свойство роли;
он получает все права, но общие ограничения действуют и на него (D19, D20). БД не проверяет, решает ли право политика
(D53). Единый vendor `axiomasoft` (D03).

---

<a id="d01"></a>
### D01 — Новая версия с чистого листа, без совместимости с 0.3

**Кратко:** пакет ещё не в работе, поэтому делаем сразу правильно: без переходных слоёв, без старых ключей конфига и
без переноса данных.

**Решение.**

- Код переписывается по этому досье. Старые классы, ключи конфига, таблицы и команды удаляются, а не
  переименовываются через алиасы.
- Нет патча 0.3.x, нет команды обновления данных, нет нормализатора старого конфига.
- Находки N01–N24 не патчатся в старом коде. Каждая превращается в требование к новому коду и в регрессионный тест
  (probe с обратным ожиданием, [13](13-workstreams.md)).
- Выпуски: `1.0.0-beta.N`, пока идёт сборка и dogfooding, затем `1.0.0`, когда зелёные гейты совместимости
  ([12 §5](12-operations-and-release.md#5-гейты-совместимости)). После 1.0 — SemVer.

**Отвергнуто.** Выпуск 0.4 с upgrade-миграцией и нормализатором: он нужен только при живых пользователях 0.3.

---

<a id="d02"></a>
### D02 — Архитектура: ядро понятий, панели-конструкторы, источники

**Кратко:** в центре — маленькое ядро понятий. Вокруг него — панели, которые собирают права из подключаемых источников.
Laravel и Filament — внешние слои, которые только переводят.

**Решение.** Зоны кода (подробно — [04](04-packages-and-layout.md)):

| Зона | Простыми словами | Правило |
|---|---|---|
| **Kernel** | словарь и арифметика прав: ключ, шаблон, субъект, контекст, решение | чистый PHP, без Laravel |
| **Panels** | описание панели, настройки, реестр, выбор панели | панель неизменна после загрузки |
| **Sources** | источники, из которых панель собирает права, роли, выдачи и политики: папка панели, БД, связи, Gate, свои; фабрика источников | каждый источник — отдельный класс на открытых контрактах |
| **Policies** | explicit PolicyOnly authority / RequiresGrant veto, включая Gate mappings | только вызывает, не регистрирует политики в Gate |
| **Authorization** | пайплайн проверки, кэш, пакетная проверка, объяснение, видимость | только читает |
| **Changes** | пайплайн изменения прав (роли и выдачи в БД) | единственный путь записи |
| **Schema** | описание панели для интерфейсов: какие права, роли, поля и как их заполнять | только читает |
| **Storage** | таблицы, модели, хранилища панелей, версия состояния — внутренности `DatabaseSource` | единственное место работы с БД |
| **Discovery** | автопоиск enum, политик и ролей в папке панели по атрибутам | только читает код; результат кэшируется |
| **Plugins** | контракт плагина: готовые наборы источников, хуков, полей | ядро не знает конкретных плагинов |
| **Laravel** | фасад, трейт, Gate, middleware, атрибуты контроллеров, команды | только переводит Laravel ↔ ядро (D58) |
| **Filament** (отдельный пакет) | редакторы ролей и прав по схеме панели | только публичный API |

Хранилище в 1.0 — Eloquent. Внешние системы прав подключаются как **источник** (D52), а не как замена хранилища.

**Отвергнуто.** Внешний policy engine (Cedar/OpenFGA): нет потребителя графов отношений. Отдельный пакет
`azguard-kernel`: границу держит arch-тест.

---

<a id="d03"></a>
### D03 — Пакеты: `axiomasoft/azguard` и `axiomasoft/azguard-filament`

**Кратко:** два пакета. Контексты (доступ «внутри сущности») — часть ядра. Интеграции с другими пакетами живут в
этих пакетах. У всех пакетов экосистемы один vendor — `axiomasoft`.

**Решение.**

1. `axiomasoft/azguard` (namespace `AzGuard\`) — ядро, все встроенные источники, Laravel-слой, тестовый набор.
   Бывший `azguard-context` вливается в ядро: доступ к конкретной сущности — базовая возможность, а не дополнение
   (сейчас context-пакет использует пять внутренних классов ядра, то есть отдельным он не является).
2. `axiomasoft/azguard-filament` (`AzGuard\Filament\`) — требует `axiomasoft/azguard: self.version`.
3. Публичный репозиторий — `github.com/axiomasoft/azguard`; `homepage`/`support` в `composer.json` указывают туда.
4. Релизы lockstep: один тег монорепо → одинаковая версия split-пакетов.
5. Пакеты-интеграции (мост Vaulter и будущие) — вне монорепо AzGuard, опираются только на `@api`/`@spi` (D51).
6. Единый vendor экосистемы — `axiomasoft` (Q23). Переход Vaulter с `axioma-studio/*` на `axiomasoft/*` — задача
   Vaulter; из этого досье передаётся заметкой ([10 §10](10-integrations.md#10-заметки-для-vaulter-по-текущему-мосту)).

---

<a id="d04"></a>
### D04 — Словарь: панель остаётся панелью

**Кратко:** слово «панель» сохраняется. «Guard» не подходит: в Laravel guard — это способ входа пользователя, а у
одного guard'а бывает несколько панелей.

**Решение.** Полный словарь — [03](03-glossary-and-renames.md).

Почему не Guard. Панель ссылается на auth guard Laravel (`for([User::class], guard: 'web')`). При этом на одном
guard'е `web` живут сразу «личный кабинет» и «кабинет продавца». Если назвать панель Guard, получится
`->guard('web')` внутри guard'а `cabinet` и путаница с `config/auth.php`. У Spatie роли разделены именно по auth
guard'ам (`guard_name`), поэтому разделить кабинет и кабинет продавца там нельзя. Если владелец всё же выберет
Guard, это механическая замена имён ([Q1](15-owner-questions.md)).

| Понятие | Термин | Почему |
|---|---|---|
| Пространство прав со своими настройками и источниками | **Panel** | язык продукта; как панели Filament |
| Что можно разрешить | **Permission** | без изменений |
| Набор прав | **Role** | из кода или из БД (D14) |
| Выдача (роли или права) субъекту | **Grant**: `RoleGrant`, `PermissionGrant` | одно слово для «выдать» и для записи о выдаче (D57); вместо `model_has_roles`/`ModelHasScope`/`ContextRole` |
| У кого права | **Subject** | пользователь или любая сущность (D11) |
| Где действует право | **Context** | конкретная сущность: проект, магазин, страница |
| Класс, из которого панель берёт права, роли, выдачи или политики | **Source** | папка панели, БД, связи, Gate, свои (D52) |
| Готовый набор дополнений панели | **Plugin** | D47 |
| Правило-метод, решающее право | **Policy** | Laravel Policy с привязкой к праву (D53) |
| Описание панели для интерфейсов | **Schema** | D54 |
| Правило «только запретить» | **Restriction** | D20 |
| Точки вмешательства | **Hooks** | D55 |

---

<a id="d05"></a>
### D05 — Имена прав, префикс панели и одно правило выбора панели

**Кратко:** внутри панели право называется коротко (`orders.view`). Как и сейчас, панель добавляет к именам своих
прав префикс — свой id (`admin.orders.view`), и такое имя само указывает на панель. Префикс можно заменить своим или
выключить. Полное имя с двоеточием
(`admin:orders.view`) работает всегда. Панель выбирается одним правилом для всех способов проверки: указанная явно →
панель по умолчанию (для запроса, затем для модели). Если панель определить нельзя, это ошибка, а не тихий отказ.

**Решение.**

- **Локальное имя** права — сегменты через точку, **минимум два** (`orders.view`, `pages.billing`). Одиночные слова
  (`view`, `update`) остаются Laravel-политикам моделей, AzGuard их не перехватывает (Q25).
- **Префикс панели** (Q25) — развитие сегодняшнего `scopedByPanelId()`. По умолчанию, как сейчас, префикс — id
  панели (`admin.orders.view`); `->prefixed('backoffice')` — свой префикс (`backoffice.orders.view`);
  `->prefixed(false)` — без префикса (удобно, когда панель одна). Общее значение по умолчанию — `defaults.prefixed`
  в конфиге.
  - Префикс — один сегмент, уникален среди панелей и не совпадает с первым сегментом ни одного локального имени в
    приложении (иначе `PrefixConflictException` при загрузке). Поэтому имя с префиксом однозначно указывает на панель.
  - В БД и в enum хранится имя без префикса: префикс можно включить, выключить или сменить без миграции данных.
  - Внутри своей панели работают обе формы: `orders.view` и `admin.orders.view`.
  - Префикс плагина (`->prefixed('blog')` у плагина, D47) вкладывается внутрь: `admin.blog.posts.edit`.
- **Полное имя** — `panel:local` (`admin:orders.view`): работает всегда, не зависит от префиксов. Тот же вид у ролей:
  `admin:manager`.
- **Enum-права** хранят локальное имя. Enum, подключённый к одной панели, знает её сам. Подключённый к нескольким —
  требует явной панели, иначе `AmbiguousPanelException`.
- **Правило выбора панели** (одно, в `Panels\PanelResolver`, для всех входов: трейт, фасад, Gate, middleware, Blade,
  CLI):
  1. **явно**: полное имя `admin:…`; имя с префиксом панели; enum с одной панелью; `->guard('admin')`; аргумент
     `panel:`;
  2. **по умолчанию для запроса**: панель, которую middleware маршрута (`azguard.panel:seller`) или Filament сделали
     панелью по умолчанию на время запроса, если субъект ей принадлежит;
  3. **по умолчанию для модели** (D11);
  4. иначе `PanelNotResolvedException` с подсказкой.
- `explain()` показывает, по какому шагу выбрана панель.

**Почему шаг 2 раньше шага 3 (Q26).** Это тот же приём, что `Auth::shouldUse()` в middleware `auth:guard` Laravel и
«текущая панель» Filament: группа маршрутов меняет значение по умолчанию на время запроса. Внутри кабинета продавца
короткие имена естественно относятся к нему, без префиксов в каждой строке. Риск — общий код (сервисы, слушатели),
который ведёт себя по-разному на разных маршрутах. Его закрывают явные формы (enum, префикс, полное имя), которые не
зависят от маршрута. Задача, поставленная в очередь из запроса панели, получает эту панель через скрытое значение
`Context` Laravel (D58); в остальных задачах и в консоли шага 2 нет. Рекомендация в документации: в общем коде — enum или имена с
префиксом.

**Почему.** Сейчас в коде четыре разных правила выбора панели, и часть проверок отвечает не про ту панель (N01, N05,
N09, P01c, P09). Одно правило с понятным порядком убирает этот класс ошибок. Префикс даёт короткие и однозначные
имена в Gate, Blade и на фронтенде.

---

<a id="d06"></a>
### D06 — Панель: папка, провайдер, реестр

**Кратко:** панель — это папка в приложении. Внутри лежит всё, что к ней относится: провайдер с настройками, роли,
домены с правами и политиками, плагины. Провайдер описывает панель, как в Filament. После загрузки приложения
панель нельзя незаметно поменять; две панели с одним id — ошибка.

**Решение.**

- Папка панели — каталог класса провайдера (`app/Guards/Admin/AdminGuardPanelProvider.php` → `app/Guards/Admin/`),
  namespace — namespace провайдера. Так устроено и сейчас; это основа автопоиска (D56).
- `PanelProvider::panel(PanelBuilder $panel): PanelBuilder`; результат сборки — `final readonly Panel`. Суффикс
  `GuardPanelProvider` сохраняется: он не путается с `AdmguardProvider` Filament.
- id панели: `^[a-z0-9][a-z0-9-]{0,63}$`.
- `PanelRegistry`: повторный id → `DuplicatePanelException`; `replace()` — явная замена до заморозки;
  `configurePanel(id, fn)` — дополнение чужой панели (модули, D50); `configurePanels(fn)` — одна настройка для всех
  панелей (например, общая роль суперадмина). После `booted` реестр заморожен (`RegistryFrozenException`).
- Что задаётся на панели — D45; источники — D52; политики — D53; хуки — D55; плагины — D47; структура папки — D56;
  механизмы Laravel — D58.

---

<a id="d07"></a>
### D07 — Единый кодек идентичности: субъект, тенант, контекст, роль


**Кратко:** идентичность одна в SQL, кэше и событиях; разные организации, источники и проекты не смешиваются.

`SubjectRef`, `TenantRef`, `ContextRef` — readonly значения. Type — зарегистрированный стабильный alias
`^[a-z0-9][a-z0-9_.-]{0,127}$`, обозначающий identity domain, не произвольный FQCN.
Id — непустая ASCII строка <=64 bytes без whitespace/control bytes; int 7 и string '7' равны.
В string HK '007' сохраняется как другая identity; при bigint такое неканоническое значение отклоняется,
uuid/ulid канонизируются codec до SQL/cache. HK проверяется одинаково на всех adapters.
Unicode/длинные внешние ключи преобразует explicit mapping интеграции, не обрезка/hash без mapping.

Reference key = `type:id`; global ref = `global`. Двоеточие в type запрещено, составные cache keys строятся
JSON массивом, не конкатенацией без границ. AccessScope = `(tenant, context)`; global context внутри tenant
не равен global tenant. TenantRef, ContextRef и SubjectRef одного alias/id различаются видом ref в сериализации.
IdentityCodec version включена в schema_state/cache. Источник с совпадающим внешним id=7 другого installation
не становится тем же субъектом/tenant: namespace/mapping обязателен. P07 и V86/V98/V105.


---

<a id="d08"></a>
### D08 — Типы ключей хоста: ids.host_keys


**Кратко:** string подходит смешанным моделям; специализированный HK разрешён лишь без потери идентичности.

`string` (ASCII varchar64), bigint, uuid, ulid. Один HK хранилища применяется к subject_id, tenant_id,
context_id, actor_id; несовместимые модели требуют string/отдельного storage. System actor имеет alias
azguard.system, id=null и reason; не записывает строку system в bigint. Identity codec и DDL/collation
сверяются на реальных СУБД, не только через сравнение PHP строк. [08](08-data-model-and-migration.md).


---

<a id="d09"></a>
### D09 — Публичный API: модель, панель, фасад

**Кратко:** чаще всего работают с моделью (`$user->hasPermission('orders.view')`), как в Spatie. Для другой
панели — `$user->guard('admin')`. Для всего про панель целиком — `AzGuard::panel('admin')`.

**Решение.** Нормативно — [05-php-api.md](05-php-api.md).

```php
// 1. Модель (трейт HasAzGuard) — панель по умолчанию или текущая
$user->hasPermission('orders.view');
$user->hasPermission(CabinetPermission::OrdersView, on: $order);
$user->grantRole('editor', on: $project);
$user->guard('admin')->hasRole('manager');
$user->isSuperAdmin();

// 2. Панель целиком
$admin = AzGuard::panel('admin');
$admin->roles()->all(); // definitions read-only; SupportRole объявлена PHP классом
$admin->schema();                               // описание для интерфейсов (D54)
$admin->decideMany($requests);                  // пакетно, validated state каждой DB группы
$admin->explain($request);

// 3. Laravel как обычно
Gate::allows('orders.view');                    // правило выбора панели D05
@can('admin:orders.refund')
```

- `AzGuardManager` — корень фасада без состояния.
- `PanelAccess` (`AzGuard::panel()`, `@api`): `for($subject)`, `roles()`, `schema()`, `decide()`, `decideMany()`,
  `explain()`, `catalog()`, `visibility()`, `state()`, `definition()`.
- Удаляются `AzGuardManagerInterface`, `GrantBuilder`, `ContextGrantBuilder`(+factory),
  `AzGuard::forUser()->on()->grant()`.

---

<a id="d10"></a>
### D10 — Трейт `HasAzGuard` на любой модели: проверки и изменения

**Кратко:** модель умеет всё, что нужно в повседневном коде: проверить право и роль, выдать и забрать. Имена
методов — своя система (D57): короткие вопросы для проверок и пара «выдать / забрать» для изменений.

**Решение.** `AzGuard\Concerns\HasAzGuard` + контракт `AzGuardSubject`.

| Группа | Методы |
|---|---|
| Права | `hasPermission()`, `hasAnyPermission()`, `hasAllPermissions()`, `permissionNames()`, `permissionSet()` |
| Роли | `hasRole()`, `hasAnyRole()`, `hasAllRoles()`, `roleNames()` |
| Суперадмин | `isSuperAdmin()` |
| Изменения | `grantRole()`, `revokeRole()`, `syncRoles()`, `grantPermission()`, `revokePermission()`, `syncPermissions()` |
| Панели | `guard(array\|string $guarded): static|SubjectAccess` (D74), `azguard(): SubjectPanels` (все панели субъекта сразу) |

- У всех методов есть необязательный `on:` — сущность (контекст или ресурс, D16). У выдачи — `until:` (срок) и
  `fields:` (свои поля выдачи, D46).
- Изменения идут через пайплайн изменений (D49): проверка, запись одной транзакцией, новая версия состояния, события.
- Изменения принимает источник-писатель панели (`DatabaseSource` или свой с `StoresGrants`). Для прав из папки и
  политик менять нечего.
- Трейт подходит любой модели, не только пользователю (D11).

**Отвергнуто.** Трейт «только для чтения» (второй проход): владелец хочет управлять правами из сущности. Копия имён
Spatie (третий проход): владелец попросил свою систему имён (Q24).

---

<a id="d11"></a>
### D11 — Субъекты: любая модель; панель по умолчанию

**Кратко:** права могут быть у пользователя, у проекта, у команды — у любой модели с трейтом. У одной модели
бывает несколько панелей; одна из них — «по умолчанию», чтобы не указывать её в каждой проверке.

**Решение.**

- `PanelBuilder::for(User::class, guard: 'web')` — какие модели бывают субъектами панели и из какого auth
  guard'а брать текущего субъекта (для middleware и UI). Моделей может быть несколько.
- `->default()` — панель по умолчанию для своих моделей. Если модель входит в одну панель, эта панель и есть панель
  по умолчанию. Две панели с `default()` для одной модели → ошибка при загрузке.
- Модель может переопределить выбор: `azguardDefaultPanel(): ?string` (например, продавцу — кабинет продавца).
- Все панели субъекта собираются в один набор, разделённый по панелям: `$user->azguard()->permissions()` →
  `['cabinet' => …, 'seller' => …]`. Каждая панель собирается лениво при первом обращении и кэшируется.
- `SubjectDirectory` (`@spi`) — поиск субъектов для UI/CLI; по умолчанию — через модели панели.
- Зашитая модель `User` и ключ `'id'` удаляются (N15).

---

<a id="d12"></a>
### D12 — Граница API: namespace + машинный манифест

**Кратко:** по расположению класса видно, можно ли от него зависеть; тест не даст незаметно поменять публичный
контракт.

**Решение.** `Contracts\*` — `@api` (вызывать) или `@spi` (реализовывать); `Kernel\*`, `Panels\PanelBuilder`/`Panel`/
`PanelProvider`, `Schema\*`, `Events\*`, `Exceptions\*`, `Facades\AzGuard`, `Concerns\HasAzGuard`, `Roles\BaseRole`,
`Testing\*`, базовые модели (чтение и наследование) — `@api`; остальное — internal по расположению.
`api-manifest.json` в каждом пакете сравнивается в CI.

---

<a id="d13"></a>
### D13 — БД хранит назначения; definitions ролей принадлежат коду

**Кратко:** role_grants/permission_grants — назначения; roles/role_permissions/role_contexts таблиц нет.
Роли — зарегистрированные PHP-классы с code-owned permissions/context filters. Enum права назначаются из кода,
relations или БД без копирования definitions. Opt-in permissions table хранит только дополнительные Grants actions.
Scope panel+tenant+context+origin, actors/expiry/conditions/state сохраняются (08). Изменение класса — deploy/build,
назначение — Change pipeline/version/events. Роль, отсутствующая в code catalogue, не даёт доступа; cleanup доступен.

<a id="d14"></a>
### D14 — Роли: где определены × как выдаются

**Кратко:** роль определяется PHP-классом; состав прав и контексты меняются в коде. Назначение
может храниться в БД или вычисляться правилом/связью. Автоматическую роль можно дополнительно выдать вручную,
если её класс допускает это. Каждое назначение проходит собственные scope/expiry/conditions проверки.

**Решение.**

| | Выдаётся вручную (БД) | Выдаётся автоматически (правило) |
|---|---|---|
| **Роль в коде** (`BaseRole`, папка `Roles/`) | «Менеджер»: права в коде, кому — решает админ | «Покупатель»: всем с моделью `Customer`; «Продавец»: всем с `is_seller` |

- Статичная роль — класс `BaseRole`, как сейчас; `FolderSource` находит её в `Roles/`. Права — метод `permissions()`
  (массив кейсов enum). Остальное — атрибутами на классе, у каждого есть метод с тем же смыслом:

  ```php
  #[Role('manager', label: 'Менеджер', level: 10)]    // key(), label(), level(); без атрибута требуется explicit key()
  #[FormerKeys('shop-manager')]                        // formerKeys(): прежние ключи после переименования
  final class ManagerRole extends BaseRole
  {
      public function permissions(): array { return [OrderPermission::View, OrderPermission::Refund]; }
  }

  #[Role('root')]
  #[SuperAdmin]                                        // superAdmin(): держатель — суперадмин (D19)
  #[NotGrantable]                                      // grantable(): только автоматически
  final class RootRole extends BaseRole implements GrantedAutomatically
  {
      public function permissions(): array { return []; }
      public function appliesTo(Model $subject, AccessScope $scope): bool { return (bool) $subject->is_root; }
  }
  ```

- Генератор `azguard:make:role` пишет `#[Role('<key>')]` сам: ключ зафиксирован в коде, переименование класса его не
  меняет (N12). Без атрибута требуется explicit stable key() override; inference из имени класса запрещён.
- Автоматическая роль реализует `GrantedAutomatically::appliesTo(Model $subject, AccessScope $scope): bool`.
- Ручная выдача возможна для любой роли без `#[NotGrantable]` (иначе `RoleNotGrantableException`). Лишних проверок
  нет: если автоматическая роль выдана ещё и вручную, права просто складываются.
- Роль объявляет `contexts(): array` конфигурируемых ContextDefinition (class-string — shorthand) и `contextRequired(): bool`; назначение на project проверяет связь с классом и tenant (D60).
- Новые роли описываются PHP-классами; UI/RoleCatalog не создают и не меняют definitions.
  Различные назначения одной роли в A/B не меняют её code-owned состав (D80).
- Ключ роли — `^[a-z0-9][a-z0-9-]{0,63}$`, полное имя `panel:key`. Смена ключа — `#[FormerKeys]` + команда
  `azguard:roles:rename-key`.
- `level` — необязательная подпись порядка: по ней сортируют роли в интерфейсе и на неё опираются свои pipes
  (например, «нельзя выдавать роль выше своей»). Движок проверки её не использует.

---

<a id="d15"></a>
### D15 — Классы контекстов и configurable bindings ролей

**Кратко:** ContextDefinition описывает identity/owner, ContextQueryFilter ограничивает подходящие rows.
ProjectContext в Contexts/ панели задаёт стабильный type и host model/owner; BaseRole.contexts возвращает
configured objects или descriptor classes. Common и role filters конфигурируются в PHP, без string profiles/JSON DSL.
Назначения на проект могут храниться в БД или вычисляться code/relation source. contextRequired запрещает tenant-wide
assignment этой роли. query(new SellerProjects(...)) получает ContextRuntime user/actual BaseRole/actor/scope/grant.

<a id="d16"></a>
### D16 — Тенант, контекст и ресурс в проверке


**Кратко:** scope ресурса подтверждает tenant/project; текущая организация запроса не приписывается чужому объекту.

`on:` принимает контекст либо ресурс. ResourceScopeResolver/ProvidesAccessScope возвращает AccessScope;
явный/current tenant и project сравниваются с ним, а ContextDefinition подтверждает owner tenant/existence.
Неподтверждённый ресурс tenant-панели -> отказ; explicit ContextRef non-accepted не игнорируется.
Current scope хранится scoped с panel identity и восстанавливается finally. Jobs передают scope явно,
на исполнении references загружаются и авторизуются повторно. [09 §3](09-authorization-semantics.md#3-тенант-контекст-и-ресурс).


---

<a id="d17"></a>
### D17 — Смысл решения

**Кратко:** ответ «можно ли» получается одним и тем же путём при любом способе проверки. Ошибка на любом шаге
означает «нельзя».

**Решение.** Порядок шагов — D48, нормативный алгоритм — [09](09-authorization-semantics.md). Итог — `Decision`:
`Allow`/`Deny`/`NotApplicable` + причина + версия состояния. Явных запрещающих выдач нет (Q12): запрет делается
ограничением (D20) или хуком (D55).

---

<a id="d18"></a>
### D18 — Грамматика имён и шаблонов

**Кратко:** права пишутся маленькими буквами через точку. Звёздочки — только при выдаче, не в каталоге. «Всё в
панели» — это суперадмин, а не звёздочка.

**Решение.** Сегмент `^[a-z0-9][a-z0-9_-]*$`; локальное имя — ≥ 2 сегментов, ≤ 255 символов; полное — `panel:local`.
Шаблоны — в PHP BaseRole.permissions() или permission grant; DB role definition отсутствует: `orders.*` — один сегмент, `orders.**` — всё глубже. Голые `*` и
`**` запрещены: «всё» даёт суперадмин (D19). Сменяемый `PermissionMatcher` удаляется: грамматика — часть данных.

---

<a id="d19"></a>
### D19 — Суперадмин — признак класса роли

**Кратко:** #[SuperAdmin]/BaseRole.superAdmin задаются в коде; БД только назначает известную роль.
Scoped/expired/conditioned RoleContribution квалифицируется до применения superadmin. В Grants mode superadmin
является authority candidate, но обязательные owner/eligibility/restrictions и attached policy deny остаются.
PolicyOnly проверяет собственную политику; superadmin grant не заменяет её. Tenant boundary неизменяем.
Для всех панелей один класс подключают явно/configurePanels; отдельного global superadmin engine нет.

<a id="d20"></a>
### D20 — Ограничения: правила, которые умеют только запрещать

**Кратко:** ограничение — это «нельзя, даже если право есть»: пользователь заблокирован, не сотрудник этого
магазина, нерабочее время, режим «только чтение». Разрешить ограничение не может, поэтому его безопасно подключать
откуда угодно. Ограничения действуют и на суперадмина, если ограничение само его не освободило.

**Решение.** `Restriction` (`@spi`): `key()`, `appliesTo(AccessRequest, EvaluationContext): bool`,
`check(AccessRequest, EvaluationContext): RestrictionResult` (`pass` | `deny(reason)`), `exemptsSuperAdmin(): bool`
(по умолчанию `false`). Регистрация — `$panel->restrictions([...])` и плагины; порядок = порядок регистрации. Исключение
внутри ограничения → отказ. Ограничения проверяют любое разрешение: от выдач, политики, before-хука или
суперадмина. `PermissionLayer` удаляется (C06).

---

<a id="d21"></a>
### D21 — Сознательно отложено (после 1.0, без поломок)

Наследование прав между панелями; произвольная рекурсивная иерархия контекстов (tenant + project уже входят в 1.0, D59–D60); явные запрещающие выдачи; граф
отношений; порт записи для чужого хранилища; outbox событий; готовый плагин подтверждения изменений вторым
человеком (в 1.0 — рецепт на pipe изменений, D55).

---

<a id="d22"></a>
### D22 — Единственный путь записи с окончательной валидацией


**Кратко:** любое изменение сериализуется, проверяется и публикует committed state одинаково.

Все adapters -> ChangePipeline -> Storage::mutate. State lock **первый**, pipes и final validation
внутри transaction, effective rows + version + audit -> root commit -> events. Предварительная проверка не
заменяет финальную после изменения pipes. Прямые Eloquent save/delete/mass writes базовых моделей запрещены
во всех environments; raw SQL вне контракта и требует reset/doctor. Это уточняет прежний Q14 warning в production:
warning не мог обеспечить отзыв и теперь предложен strict invariant. Подробнее [08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы), D63.


---

<a id="d23"></a>
### D23 — Кто может менять права — решает приложение

**Кратко:** AzGuard не знает, кто в приложении «администратор». Для него есть суперадмин и обычные субъекты.
Страницу «Роли» в админке защищает обычное право этой админки, как любую другую страницу.

**Решение.**

- В ядре нет политики делегирования, мета-прав, рангов и «кто кем управляет». Пайплайн изменений проверяет только
  корректность данных (D49).
- Интерфейсы редактирования защищают себя обычными правами своей панели. Например, Filament-ресурс ролей требует
  право `admin:azguard-roles.update`, как любой другой ресурс.
- Если приложению нужно правило «нельзя выдать то, чего нет у тебя», это хук `changing` (рецепт в
  [06 §5](06-extension-points.md#5-хуки-изменений-pipes-и-события)).
- «Кто выдал» записывается, если известен: текущий пользователь, явно `AzGuard::actingAs($user)` или system в
  консоли. Без актора изменение тоже проходит.

**Почему.** Во втором проходе я смоделировал администрирование внутри AzGuard. Это лишнее: у разных приложений
разное понимание «админа», а у панели может вообще не быть редактирования (например, у панели с правами только из
политик).

---

<a id="d24"></a>
### D24 — Версия панели и проверенное чтение authority


**Кратко:** счётчик не позволяет кэшировать смесь версий; guarantee относится к DB authority и указанной свежести.

StateToken = storageId/panel/incarnation/version/generation/fingerprint. Version меняется в transaction;
incarnation исключает cache resurrection после restore/reset. Primary/fresh state для reads; cold data читает
Grants mode: T_before -> scoped DB action catalogue/grants -> T_after, retry mismatch до 3. Повтор с validated warm grants
может не читать DB; live policies/restrictions всё равно работают. Refresh request/check и исключения старого
application snapshot — [09 §8](09-authorization-semantics.md#8-кэш-и-консистентность). Внешний LDAP/membership
не получает strict revoke от одного DB token; adapter объявляет revision/freshness (D62/D64).


---

<a id="d25"></a>
### D25 — Кэш contributions, не окончательного Allow


**Кратко:** сохраняется только то, что source разрешил кэшировать, со scope и абсолютным сроком.

Digest включает panel/storage/state/codec/subject/tenant/context/source. Stable требует revision contract;
Request ограничен lifecycle, Volatile выполняется каждый check. На каждом check проверяются deadlines даже
request memo. Grants/roles source partitions не смешиваются с Volatile. Policy/conditions/hooks/restrictions
и final Decision не кэшируются по одному token. Code fingerprint включает deployment build id;
worker restart и catalog rebuild обязательны после изменений кода. [09 §8](09-authorization-semantics.md#8-кэш-и-консистентность).


---

<a id="d26"></a>
### D26 — Gate: owned permission имеет окончательный explicit-mode результат

**Кратко:** PolicyOnly/RequiresGrant решает AzGuard; для чужих abilities Laravel продолжается нативно.
Owned action result direct pipeline/Response возвращается окончательно; NotGranted не null/fallback.
Additive mode исключён, потому что позволял external policy компенсировать отсутствие RequiresGrant assignment.
Early host Gate.before всё ещё может сработать раньше adapter; authoritative direct API — protected-write boundary.
Unknown owned action deny; truly foreign ability null. Adapter Laravel versions qualified consumers, не private API
предположение. [09 §7](09-authorization-semantics.md#7-gate-laravel).

<a id="d27"></a>
### D27 — Пакетная проверка с validated authority каждой группы


**Кратко:** пачка согласованно читает DB grants каждой panel/tenant группы, а не притворяется единым snapshot мира.

Группы (storage,panel,subject,tenant), contexts пачками по 100, общий now. Все chunks группы между T_before/T_after;
retry перечитывает все. Policy/restriction/conditions на каждый request, без memo по одному model id.
DecisionSet::states() отражает отдельный token каждой panel; внешние sources/host resource data имеют собственную
consistency. [09 §9](09-authorization-semantics.md#9-пакетная-оценка).


---

<a id="d28"></a>
### D28 — События

**Кратко:** события отправляются только после успешного сохранения, несут «кто, что, где, когда» и понятны без
загрузки моделей. На них можно подписаться, чтобы реагировать на изменения прав.

**Решение.** База `AccessEvent`: `eventId` (ULID), `occurredAt`, `panel`, `actor` (может быть `null`),
`correlationId`, `state`, `type(): EventType`. Каталог — [08 §6](08-data-model-and-migration.md#6-каталог-событий).
Отправка — после commit; журнал (плагин `azguard/audit`) — в той же транзакции. Нет изменения → нет события.
`AccessDecided` — только при включённой трассировке (диагностика).

---

<a id="d29"></a>
### D29 — Объяснение из той же проверки

**Кратко:** «почему нет?» показывает весь путь: какая панель, какие источники что дали, какая политика или ограничение
отказали.

**Решение.** `explain(AccessRequest): Explanation` = решение + трасса той же оценки: выбор панели, контекст, хуки,
суперадмин, выдачи источников с происхождением, политика, ограничения, итог Gate. Повторного опроса источников нет (C05,
N24).

---

<a id="d30"></a>
### D30 — Filament: редакторы по схеме панели и три режима прав ресурсов

**Кратко:** Filament показывает PHP definitions read-only и редактирует scoped assignments.
Definitions typed enum FilamentDefinitions::Enums/Resources отдельно от authority PolicyOnly/RequiresGrant;
состав роли, policy class/method и filters из UI не меняются. Generated resource metadata — code build source,
не dynamic DB definitions. Enum assignments работают с DatabaseSource без dynamicPermissions.
AzGuardPlugin.definitions(...) + guardPanel/manages/enforce настраивают integration; mode права explicit,
generator --authority/--with-policy не смешивают definition source/authority. RoleResource read-only,
GrantResources use central writer; PolicyOnly no checkbox/raw reject; [11](11-filament.md), D83.

---

<a id="d31"></a>
### D31 — Видимость записей: exact фильтр окончательного доступа


**Кратко:** visibleTo возвращает безопасные строки до пагинации; arbitrary policy требует явного query adapter.

Source FiltersQueries описывает grants. Итоговые policies/restrictions/hooks/conditions реализуют
FiltersAccessQueries либо дают детерминированный pass/deny на запрос. Неподдержанный компонент ->
VisibilityNotSupportedException. Tenant AND ownership AND qualified source OR AND restrictions;
superadmin не снимает boundary. ViewAny отдельно от View; query scope resource может идти через project relation.
Отдельный candidates() — internal prefilter, не безопасный response. Cross-connection SQL не обещается.
[09 §10](09-authorization-semantics.md#10-видимость-visibleto), D66.


---

<a id="d32"></a>
### D32 — HTTP: панель на маршрутах и проверки атрибутами

**Кратко:** группу маршрутов привязывают к панели одной middleware. Она пускает только субъектов этой панели, делает
панель панелью по умолчанию на время запроса и определяет сущность из маршрута. Права на действия контроллера, как и
сейчас, пишутся атрибутом `#[CheckPermission]` прямо над методом; теперь это наследник атрибута `#[Middleware]`
Laravel, поэтому его применяет сам роутер. Строгий режим требует, чтобы у каждого действия была проверка или явный
пропуск.

**Решение.**

- `azguard.panel:{id}` — вход в панель: субъект из auth guard'а панели; субъект принадлежит панели; если задано
  право входа (`->entry('panel.access')`), оно есть (у суперадмина есть); делает панель панелью по умолчанию для
  запроса (D05, как `Auth::shouldUse()`) и кладёт её в `Context` для очередей; определяет текущую сущность
  (резолверы панели). Иначе 403 или редирект, как задано на панели.
- `#[CheckPermission(OrderPermission::Refund, on: 'order', status: 403, message: …)]` — сегодняшний атрибут, теперь
  `extends Illuminate\Routing\Attributes\Controllers\Middleware`: роутер Laravel сам вешает
  `azguard.can:{permission},{on}`. Можно на методе и на классе (с `only:`/`except:`, как у Laravel), можно несколько.
  `on:` — имя параметра маршрута с сущностью или ресурсом. На Laravel 11–12, где атрибутов контроллеров нет, тот же
  атрибут читает `azguard.panel`, как сегодня (D58).
- `#[SkipPermissionCheck]` (сегодня `#[SkipGuardCheck]`) — явно без проверки.
- Строгий режим панели — `->requireRouteChecks()` (сегодня `require_permission_attributes`): действие без
  `azguard.can` (из атрибута или маршрута), без Laravel `can`/`#[Authorize]` и без `#[SkipPermissionCheck]` →
  `MissingPermissionCheckException` в local/testing и 403 в production. Doctor показывает такие действия заранее.
- `azguard.can:{permission}[,{routeParam}]` — та же проверка для маршрута без контроллера.
- Filament-плагин делает свою панель текущей сам.
- Blade — обычные `@can`/`@cannot`. Удаляются дублирующие middleware (`azguard.grant`, `azguard.panel_check`,
  `azguard.roles`, `check.access`) и директивы `@az*`.

---

<a id="d33"></a>
### D33 — Конфигурация

**Кратко:** в `config/azguard.php` — то, что общее для всех панелей, и значения по умолчанию. Всё особенное — в
провайдере панели.

**Решение.** Детали — [07-configuration.md](07-configuration.md). Файлы `config/azguard.php`, `config/azguard-filament.php`;
readonly `AzGuardConfig`; эффективные настройки панели: провайдер → плагины → значения по умолчанию (D45); ошибки
безопасности — исключение при загрузке.

---

<a id="d34"></a>
### D34 — База данных и хранилища

**Кратко:** есть общее хранилище по умолчанию, где панели различаются колонкой `panel`. Панель может получить своё
хранилище: другое подключение к БД или свои таблицы. Хранилище — настройка `DatabaseSource`: у панели без него таблиц
нет.

**Решение.** Хранилище = подключение + префикс таблиц + тип ключей хоста + классы моделей (D46). По умолчанию —
`azguard.storages.default` (`connection: null`, `table_prefix: 'azg_'`) для всех панелей (Q19); другое —
`DatabaseSource::make()->storage('backoffice')`. Модели, `DatabaseSource` и команды работают через `Storage` панели; arch-тест запрещает фасад `DB` и статические запросы к моделям AzGuard вне
`Storage\`.

---

<a id="d35"></a>
### D35 — Миграции

**Кратко:** таблицы общего хранилища создаёт пакет. Для своего хранилища команда генерирует миграцию в проект, и туда
можно дописать свои колонки.

**Решение.** Ядро загружает миграции общего хранилища. `azguard:storage:migration {name}` пишет миграцию в
`database/migrations` хоста. Параметры, влияющие на схему, фиксируются в таблице состояния хранилища; doctor сверяет.

---

<a id="d36"></a>
### D36 — Каталог: статичная схема и динамический scoped overlay


**Кратко:** code definitions кэшируются на deploy, динамические actions загружаются по tenant и DB state.

Folder/plugin/Filament static catalog immutable; dynamic catalog scoped (panel,tenant), входит в version fence.
Static names зарезервированы во всех tenants; dynamic names проверяют prefix conflicts при mutation.
Compiled cache не содержит DB rows разных tenants. Pattern grants открывают будущие имена namespace,
удаление action очищает точные grants/role permissions и меняет version. Deploy revalidates collisions,
FormerKeys/contexts и wildcard expansion до выдачи новых actions. D68; [08](08-data-model-and-migration.md).


---

<a id="d37"></a>
### D37 — Исключения

**Кратко:** у каждой ошибки стабильный машинный код. Отказ в доступе — стандартное исключение Laravel.

**Решение.** База `AzGuardException` (`code(): string`, `snake_case`). Ветки: `ConfigurationException`,
`DefinitionException`, `InvalidIdentityException`, `ChangeException`, `AuthorizationEngineException`,
`StorageException`, `PluginException`. Отказ — `Illuminate\Auth\Access\AuthorizationException`. Таблица —
[05 §10](05-php-api.md#10-исключения).

---

<a id="d38"></a>
### D38 — Команды

**Кратко:** все команды начинаются с `azguard:`; doctor проверяет каждую панель и каждое хранилище; плагины добавляют
свои проверки.

**Решение.** Детали — [12](12-operations-and-release.md). Формат `azguard:<area>:<verb>`; `--panel`, где нужно;
`--json` у читающих; `azguard:doctor` с проверками ядра и плагинов; планировщик — очистка истёкших выдач.

---

<a id="d39"></a>
### D39 — Тестовый набор

**Кратко:** приложение тестирует права простыми хелперами; авторы плагинов, источников и интеграций — готовыми
наборами проверок против настоящего AzGuard.

**Решение.** `InteractsWithAzGuard` (`actingAsWithPermissions()`, `actingAsSuperAdmin()`), `AzGuardFake` с
ассертами (`assertRoleGranted`, `assertPermissionGranted`, `assertPermissionRevoked`, `assertChecked`,
`assertDecided`); контрактные наборы: `SourceContractTests`, `RestrictionContractTests`, `HookContractTests`,
`PluginContractTests`, `SubjectResolverContractTests`, `ContextResolverContractTests`, `IntegrationContractTests`.

---

<a id="d40"></a>
### D40 — Установка

**Кратко:** установка не запускает миграции без спроса и честно сообщает об ошибке.

**Решение.** `azguard:install`: публикует конфиг, спрашивает подключение и `host_keys`, предлагает создать первую
панель, показывает миграции AzGuard, `migrate` — только с `--migrate`, код выхода передаётся (C10), в конце —
`azguard:doctor`.

---

<a id="d41"></a>
### D41 — Совместимость и релиз

**Кратко:** случайно сломать публичный контракт нельзя — это ловит CI.

**Решение.** Lockstep-теги; `api-manifest.json` и семантические снимки (конфиг, события, команды, схема БД,
грамматика, коды ошибок, методы `PanelBuilder`, форма `PanelSchema`) — с первого beta; Roave BC Check — с 1.0.0;
consumer-фикстуры на собранных архивах; матрица PHP × Laravel × СУБД × Filament.
[12 §4–§5](12-operations-and-release.md#4-релиз-и-артефакты).

---

<a id="d42"></a>
### D42 — Документация следует за кодом

**Кратко:** документация начинается с понятий простыми словами; каждый пример кода проверяется тестом.

**Решение.** Разделы и правила — [12 §6](12-operations-and-release.md#6-документация). Сниппеты в `docs/` совпадают с
тестами-рецептами.

---

<a id="d43"></a>
### D43 — Экосистема: общие инженерные правила, свои предметные слова

**Кратко:** AzGuard и Vaulter одинаково устроены снаружи (конфиги, команды, события, ошибки, тесты), но говорят на
своих языках: авторизация и хранение файлов — разные предметы.

**Решение.** Детали — [10-integrations.md](10-integrations.md). Общие правила — в ADR «Ecosystem conventions».
Предметные слова свои: у AzGuard — Panel, Permission, Role, Grant, Context, Subject, Restriction; у
Vaulter — Drive, Node, Profile, Owner, NodeGrant. Мост к Vaulter делает Vaulter; AzGuard держит контракт интеграции
(D51).

---

<a id="d44"></a>
### D44 — Бюджет производительности с границами измерения


**Кратко:** считаем запросы самого DB authority отдельно от live business checks; безопасность не снимает fence.

| Сценарий | Бюджет ядра без политики/внешних adapters |
|---|---|
| Cold scoped load, static catalog/roles | 2 state reads + 1 role grants + 1 direct grants; role permissions DB ещё <=1 батч |
| Warm persistent contributions | 1 fresh state read при первом request/check + cache lookup |
| Повторный grants read одного scope в request без expiry/change | 0 DB authority запросов |
| Opt-in dynamic permission catalogue | Явно учтённые batched queries внутри того же fence; замер fixture, не скрытый N+1 |
| decideMany, c batches по 100 scopes | 2 state reads + <=2c grants queries + batched role/catalog definitions |
| Чужая неквалифицированная Gate ability | 0 DB authority queries; статичный O(1) ownership index |

Live membership, token validity, resource tenantOf, policy, query predicate и retries имеют собственную стоимость
и включаются в end-to-end p95/p99/SQL report. Deadline инвалидация и refresh=check увеличивают budget честно.
Цель не утверждает «вся повторная авторизация = 0 запросов», если adapter обязан читать свежие данные.


---

<a id="d45"></a>
### D45 — Настройки панели

**Кратко:** провайдер панели — короткий список «из чего собрана панель»: субъекты, источники, плагины, хуки. Всё,
что относится к конкретному источнику (таблицы, модели, поля), настраивается у самого источника. Если значение не
задано, берётся общий конфиг. Несколько гарантий безопасности не отключаются никакой настройкой.

**Решение.** Эффективная настройка = **провайдер панели** → иначе **плагин** (в порядке подключения; конфликт двух
плагинов → ошибка) → иначе `configurePanels()` → иначе **значение по умолчанию** из `config/azguard.php`.
Context query predicates — additive AND D75, не заменяемые scalar settings: precedence не удаляет common filter.

| Группа | Что на панели | Методы `PanelBuilder` |
|---|---|---|
| Идентичность | id, название, описание, по умолчанию ли, префикс имён | `id()`, `label()`, `description()`, `default()`, `prefixed()` |
| Субъекты | модели, auth guard, директория | `for([...], guard:)` |
| Маршруты | middleware входа, право входа, ответ при отказе, строгий режим | `middleware([...])`, `entry()`, `onDenied()`, `requireRouteChecks()` |
| **Описание прав** | enum definitions и источники прав/ролей/выдач/политик (D52/D73) | `permissions([...])` |
| Роли, политики вне папки | дополнительно к найденным в папке; enum входят в permissions выше | `roles([...])`, `policies([...])` |
| Тенант и контексты | независимые политики, descriptors, ресурсные resolvers, членство | `tenants()`, `tenantResolvers([...])`, `contexts()`, `contextResolvers([...])`, `resourceScopes([...])` |
| Хуки | before, ограничения, after, pipes изменений | `before()`, `restrictions([...])`, `after()`, `changing([...])` |
| Gate | режим | `gate()` |
| Кэш и консистентность | store, ttl, generation, `reads`, `state_refresh` | `cache()`, `consistency()` |
| Плагины | подключить | `plugins([...])` |
| Интерфейс | группы, иконки, подписи для Filament и своих UI | `presentation([...])` |

Настройки источника — у источника: `DatabaseSource::make()->storage('backoffice')->models(roleGrant: …)->dynamicPermissions()`
(D46, D52). Панель не знает о таблицах, если у неё нет источника с таблицами.

**Не настраивается:** tenant/resource owner integrity, exact scope/origin записей, final validation и fail-closed visibility; грамматика имён и кодек идентичности; атомарность «запись + версия»; учёт сроков выдач;
«ошибка = отказ»; ограничения умеют только запрещать; проверка каталога при выдаче.

---

<a id="d46"></a>
### D46 — Хранилище, свои модели и свои поля — настройки `DatabaseSource`

**Кратко:** всё про базу данных — в одном классе-источнике `DatabaseSource`: где лежат таблицы, какие модели, какие
свои поля, нужны ли динамические права. Панель без этого источника таблиц не имеет.

**Решение.**

```php
DatabaseSource::make()
    ->storage('backoffice')                                    // именованное хранилище из конфига; по умолчанию 'default'
    ->models(roleGrant: AdminRoleGrant::class)                  // свои модели (наследники базовых)
    ->decisionFields(roleGrant: ['weekdays'])                   // поля, участвующие в решении
    ->dynamicPermissions()                                      // права можно создавать во время работы
    ->without(permissionGrants: true);                          // только роли, без выдачи отдельных прав
```

- **Хранилище:** `storage('default')` (по умолчанию), именованное из конфига или
  `Storage::own(prefix: 'admin_', connection: 'backoffice')`. Панели в одном хранилище различаются колонкой `panel`;
  у каждой — своя строка версии. Модели панели могут использовать атрибуты Laravel `#[Table]` и `#[Connection]` —
  хранилище сверяет их при загрузке.
- **Модели:** наследуют базовые модели AzGuard; идентификационные колонки и методы — `final`.
- **Свои поля:**
  - настоящие колонки — миграцией приложения (nullable в общем хранилище);
  - лёгкие поля — колонка `meta` (JSON, nullable, Q22) с типизированным кастом в модели;
  - запись — `$user->grantRole('editor', on: $project, fields: ['department_id' => 7])`;
  - описание — `azguardFields(): array` в модели (тип, подпись, правила): из него берутся проверка, схема панели и
    формы Filament. Неизвестное поле → ошибка валидации;
  - участие в решении — `decisionFields(...)`: поля загружаются вместе с выдачами и доступны ограничениям и хукам.
- Правильность `meta`: поле в `meta` нельзя индексировать и искать эффективно. Если по полю фильтруют или его
  проверяют ограничения на больших объёмах, это колонка. Doctor предупреждает, если поле из `decisionFields` лежит в
  `meta`.

---

<a id="d47"></a>
### D47 — Плагины с именованными типизированными параметрами

**Кратко:** у каждого plugin свой понятный constructor/make; общая база определяет lifecycle, не options bag.
Plugin SPI: id(), register(PanelBuilder, PluginContext), boot(Panel, PluginContext). BasePlugin не объявляет make()
или options()/withOptions(), поэтому concrete make(models: CrmModels, projects: ProjectContext, ...) не конфликтует
с LSP. CrmModels — DTO plugin с named subject/organization/project/client и class-string validation; generic model
role-name registry отсутствует. AuditTrailPlugin::make(retentionDays: 90) — отдельный точный параметр.
Конфигурация immutable, runtime inputs передаются capabilities; source/runtime services resolve per operation.
PHP/Laravel DI используется по типу/explicit parameters; no build CurrentUser/singleton runtime state (18/19).

<a id="d48"></a>
### D48 — Пайплайн проверки с явным authority dispatcher

**Кратко:** owner/common boundary -> typed preliminary checks -> selected Policy/Grants authority -> restrictions.
Permission mode известен до разрешения assignment services. PolicyOnly обращается к policy без grant store;
RequiresGrant квалифицирует contributions одной ветки AND, объединяет OR, затем policy only veto/pass.
BeforeResult Continue не даёт authority, Deny/error отвергает; after наблюдает. Source error в relevant Grants
path не скрывается первым Allow, а irrelevant DB source не вызывается Policy path.
Decision evidence CodeStateToken либо consumed StateToken; code token не версионирует host data.
Канонический алгоритм — [09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм), D83.

<a id="d49"></a>
### D49 — Пайплайн изменений под блокировкой


**Кратко:** после pipes повторно проверяется финальное изменение; сохранение и события связаны с root commit.

Early validation даёт удобную ошибку; authority validation выполняется после state lock, pipes и чтения definitions.
Pipe может менять разрешённые поля/срок, не panel/tenant/subject/actor/origin. Journal в той же transaction;
after-commit best effort. Nested ChangeResult до root commit tentative; rollback не публикует token.
Retry-safe pipes не делают HTTP/email. UI/CLI/server используют один pipeline (D63), [08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы).


---

<a id="d50"></a>
### D50 — Модули и сторонние пакеты внутри приложения

**Кратко:** Laravel-модуль может принести свою панель или дополнить существующую. Какую именно — решает приложение.
Конфликты видны сразу при загрузке.

**Решение.**

- Своя панель модуля — обычный `PanelProvider`: `AzGuard::registerPanel(BlogGuardPanelProvider::class)`.
- Дополнение чужой панели — `AzGuard::configurePanel('admin', fn (PanelBuilder $p) => $p->plugins([BlogAccessPlugin::make()->prefixed('blog')]))`.
  Несуществующая панель → ошибка при загрузке. id панели модуль берёт из своего конфига, а не зашивает.
- Коллизия имён прав или ролей → ошибка с именами плагинов. `azguard:panels:list --sources` показывает, кто что
  принёс.

---

<a id="d51"></a>
### D51 — Контракт для пакетов-интеграций (Vaulter и другие)

**Кратко:** любой пакет может опираться на AzGuard через небольшой стабильный набор возможностей. Как он переводит
свои понятия в понятия AzGuard — его дело.

**Решение.** Детали — [10-integrations.md](10-integrations.md). AzGuard гарантирует (всё `@api`/`@spi`):

| Потребность интеграции | Что даёт AzGuard |
|---|---|
| Спросить «можно ли» | `AzGuard::panel($id)->decide()/decideMany()/explain()`, трейт, Gate |
| Понять, что изменилось | `StateToken` панели + события после commit |
| Встроиться в панель | `Plugin` + `configurePanel()`; пакет даёт плагин, хост выбирает панель |
| Свои права, роли, политики | enum, `BaseRole`, привязка политик в плагине |
| Свой источник или ограничение | `Source` и его возможности (`ProvidesGrants`, …), `Restriction`, pipes изменений |
| Описать себя для UI | вклад в `PanelSchema` (группы, подписи, поля) |
| Перевести свою сущность в контекст | `ContextRef::of(type, id)`, `ContextAware` |
| Проверить себя | `IntegrationContractTests` против настоящего AzGuard |

Правила: не импортировать `Internal\`/`Storage\`; не зашивать id панели; не копировать права AzGuard к себе;
кэшировать contributions с `StateToken`; final Allow требует всех dependency revisions; `require axiomasoft/azguard: ^1.0`.

---

<a id="d52"></a>
### D52 — Источники: панель собирается из классов-источников

**Кратко:** панель — конструктор, а её детали — **источники**. Каждый источник — самостоятельный класс, который
целиком отвечает за свой способ получить права: один читает папку панели (enum, политики, роли), другой отвечает за
всю работу с базой данных, третий берёт права из связей моделей, четвёртый — из вашей системы. Источники сочетаются в
любом наборе; свои источники регистрируются так же, как встроенные, через фабрику в стиле Laravel.

**Решение.**

```php
->permissions([
    DatabaseSource::make()->dynamicPermissions(),             // вся работа с БД: роли, выдачи, динамические права
    RelationSource::make(Project::class, via: 'members', role: 'pivot.role'),
    GateSource::make()->map(BetaPermission::Access, 'beta-access'),
    'ldap',                                                     // свой источник по имени (фабрика, см. ниже)
])
// FolderSource — папка панели — подключён всегда; передать его явно нужно, только чтобы настроить
```

| Источник | За что отвечает | Меняется во время работы |
|---|---|---|
| `FolderSource` (всегда) | папка панели: enum прав (статичные права), explicit policy bindings, статичные роли, автоматические роли, права «всем» (`#[GrantedToAll]`) — по атрибутам | нет (код) |
| `DatabaseSource` | таблицы и модели назначений классов ролей и прав, дополнительные динамические права, свои поля выдач, миграции, запись | да |
| `RelationSource` | права из связей моделей приложения | через данные приложения |
| `GateSource` | существующие Laravel Gate-abilities как второй уровень | нет |
| свои | всё, что можно написать классом: токены, LDAP, конфиг-файл, внешний API (рецепты — [06 §2](06-extension-points.md#2-свой-источник)) | как решит автор |

**Возможности источника** — интерфейсы; источник реализует только нужные (`AzGuard\Contracts\Sources\…`):

| Интерфейс | Что даёт панели | Кто из встроенных |
|---|---|---|
| `Source` | `id()`; базовый контракт | все |
| `ProvidesPermissions` | права в каталог (статичные и динамические) | Folder (статичные), Database (динамические) |
| `ProvidesRoles` | роли | Folder (статичные), Database (динамические) |
| `ProvidesRoleGrants` | назначенные роли со scope, включая пустого SuperAdmin | Folder, Database, Relation |
| `ProvidesGrants` | выдачи субъекту — первый уровень проверки | Folder (`#[GrantedToAll]`), Database (direct grants), свои |
| `ProvidesPolicies` | PolicyOnly authority / RequiresGrant veto | Folder (политики доменов), Gate |
| `StoresGrants` | запись: выдать, забрать, роли, динамические права | Database |
| `FiltersQueries` | условие для `visibleTo` | Database, Relation |
| `DescribesSchema` | подписи, поля, группы для схемы панели | все встроенные |
| `ChecksHealth` | проверки doctor | Folder, Database |

- **Фабрика — `Manager` Laravel.** Источники можно указывать объектом или именем. Имя разрешает `SourceManager`
  (`Illuminate\Support\Manager`), как драйверы кэша или файловых систем:
  `AzGuard::sources()->extend('ldap', fn (Application $app, array $config) => new LdapSource($config))` или атрибут
  `#[AsSource('ldap')]` на классе. Параметры — `config('azguard.sources.ldap')`. Каждая панель получает свой экземпляр.
- **`FolderSource`** есть на каждой панели. Передать его в `->permissions([...])` явно нужно, только чтобы настроить
  (`FolderSource::make()->folders(...)`); тогда он заменяет неявный.
- **Порядок** источников не влияет на решение: выдачи объединяются (свойство P12); порядок виден в объяснении и входит
  в отпечаток панели.
- **Изменения** принимает источник с `StoresGrants`; такой источник на панели один (два → ошибка при загрузке). Нет
  такого источника — панель «только чтение» (`PanelNotWritableException` при попытке записи).
- **RequiresGrant** объединяет qualified выдачи источников, затем explicit policy может наложить veto.
  **PolicyOnly** вызывает свою policy без assignment sources (D53/D81). Код проверок не зависит от источника: перенос права из `#[GrantedToAll]` в БД не меняет ни одной проверки
  (Q18).
- **Статичные и динамические права.** Права из enum — статичная схема: видны в коде, на них опираются политики.
  `DatabaseSource::make()->dynamicPermissions()` разрешает добавлять права во время работы (таблица `{p}permissions`);
  динамическое право выдаётся и проверяется по имени в scoped tenant catalog; authority всегда Grants; code-defined business policy может только ограничить, с именем static enum совпасть не может;
  добавление или удаление меняет версию панели.
- Встроенные источники написаны на тех же контрактах, что и свои (arch-тест): всё, что умеет AzGuard, может и автор
  стороннего источника.
- **Источник и плагин — разное.** Источник отвечает на вопрос «откуда права» и делает одно дело. Плагин — готовый набор
  для панели (D47): может принести источники, ограничения, pipes, поля и проверки doctor одним вызовом.

**Отвергнуто.** Отдельные методы панели на каждую механику (`->database()`, `->relation()`, `->gates()`,
`->grantToAll()`, третий проход): каждый новый способ получать права требовал бы нового метода ядра, а свои способы
оставались бы второсортными. `->database(false)`: отрицательные флаги путают; источника либо нет, либо он настроен.

---

<a id="d53"></a>
### D53 — Два явных authority modes права

**Кратко:** PolicyOnly решает policy; RequiresGrant требует назначения, policy может только сузить.
Атрибут на enum задаёт режим группы, на case — явное override. Compiler требует один итоговый mode.
Policy mode не читает role/permission grants AzGuard и отвергает попытку их назначить. Policy может читать host
business data через Laravel, это не dependency на storage назначения. RequiresGrant работает с class permissions и
code/relation/DB assignments; attached policy true/null — pass, false — deny, без grant true не разрешает.
Динамические actions — только Grants, opt-in. Два режима сочетаются в панели с общей scope/restriction защитой,
но одно право не имеет union policy OR DB. Before hooks — deny/abstain/pass, не alternative authority.
Grants policy может отсутствовать; declared missing binding/method — compile error. Конкретный пример — 19 §2.

<a id="d54"></a>
### D54 — Схема панели для интерфейсов

**Кратко:** панель умеет рассказать о себе: какие права есть и как они сгруппированы по доменам, какие решаются
кодом или политикой, какие роли редактируются, какие поля заполнять. По этой схеме Filament (или любой
свой интерфейс) строит редакторы сам.

**Решение.** `AzGuard::panel($id)->schema(): PanelSchema` (`@api`, неизменяемое значение; `toArray()` для фронтенда):

| Часть схемы | Что в ней |
|---|---|
| `permissions()` | имя (локальное, с префиксом, полное), подпись, домен, описание, статичное или динамическое; **как получается**: источники, которые могут дать право (`folder`, `database`, `relation`, …); решает ли его политика или Gate (подсказка для UI); в каких типах сущностей |
| `roles()` | ключ, подпись; PHP class; definitions read-only; stable build fingerprint; выдаётся вручную, автоматически или обоими способами; права |
| `fields()` | свои поля выдач ролей и прав: тип, подпись, правила, откуда (модель, плагин) |
| `tenants()` | типы tenants и текущий scoped overlay, способ поиска |
| `contexts()` | зарегистрированные ContextDefinition, role context bindings; типы сущностей: подпись, как искать (директория) |
| `subjects()` | модели субъектов, подпись, как искать |
| `writable()` | есть ли на панели источник, принимающий изменения (если нет — редакторы не показываются) |

Панель без источника-писателя (`DatabaseSource`) — «только чтение»: схема есть (для документации и отладки), редактирования нет (Q20).

---

<a id="d55"></a>
### D55 — Хуки с ограниченным типизированным результатом

**Кратко:** before проверяет предварительные запреты, after наблюдает; authority определяет режим права.
BeforeResult enum: Continue | Deny. Callable `(AccessRequest, EvaluationContext): BeforeResult`; failure -> deny.
Нет Allow/true shortcut и bool-null ambiguity. Все relevant checks должны пройти; error не masked.
After `(request, context, Decision): void`, exception логируется без изменения решения.
Это package hooks на native DI/callable mechanisms, но не копия Gate::before return semantics. Laravel global
Gate::before может перехватить ability до AzGuard; protected writes используют authoritative package path.

<a id="d56"></a>
### D56 — Папка панели: тип классов, затем группа действий

**Кратко:** Permissions/Orders, Policies/Orders, Queries/Orders; группы не лежат в корне панели.
Это подтверждённая владельцем структура D72. Контейнер Resources отсутствует.

```text
app/Guards/Admin/
├── AdminGuardPanelProvider.php
├── Permissions/
│   ├── Orders/OrderPermission.php
│   ├── Users/UserPermission.php
│   └── Sources/SourcePermission.php
├── Policies/Orders/OrderPolicy.php
├── Abilities/Orders/OrderAbilities.php
├── Queries/Orders/OrderVisibility.php
├── Roles/
├── Contexts/
├── Resolvers/
├── Sources/
├── Restrictions/
├── Changes/
├── Models/
└── Plugins/
```

FolderSource ищет enums только под Permissions/, policies только под Policies/, DTO под Abilities/;
role/context definitions — под Roles/Contexts. Подкаталоги групп могут быть вложенными:
Permissions/Sales/Orders соответствует Policies/Sales/Orders. Группа Sources под Permissions не мешает
механизму Sources в корне. Корень панели не сканируется как каталог предметных групп.

Правило привязки enum и политики:

1. Discovery root — конкретная папка провайдера или отдельный discover(path, namespace) модуля/плагина.
   Roots сохраняют происхождение; совпавший путь группы двух разных roots не склеивается.
2. Явные PolicyFor(enum FQCN)/Decides имеют точную цель. Они используются также для нескольких enums,
   нескольких policies группы, политики вне соответствующей папки или групп со сложной раскладкой.
3. Без явной привязки один enum группы Permissions/<relative path> и одна не привязанная явно policy в
   Policies/<тот же relative path> **этого root** образуют пару. Несколько кандидатов без точной привязки —
   InvalidPolicyStructureException; отсутствующий обязательный case method — тоже ошибка, кроме RequiresGrant.
4. Pairing method=case регистрирует обязательные PolicyOnly actions. Optional RequiresGrant veto требует
   explicit PolicyBinding; compiler проверяет class/method даже после удаления метода. Окончательный registry использует enum FQCN и PermissionKey,
   а не basename или имя папки. Повтор binding одного права — DuplicatePolicyBindingException с origin roots.
5. Queries/<Group> — место paired visibility adapters, не автоматическая регистрация произвольных Query классов.
   Адаптер подключается явно через существующий FiltersAccessQueries/ResourceScopeResolver contract.

Permissions/Users — действия над User; User из for([...]) — субъект. Models/ панели — storage extensions.
Contexts/ProjectContext — descriptor области; Permissions/Projects — действия над проектами. Эти позиции
одной модели не подразумеваются друг из друга.

Генераторы панели/permission/policy/Filament и module stubs используют один layout. Discovery roots и настройки
имён каталогов входят в fingerprint каталога; live и cached discovery дают одинаковые bindings. Legacy
Resources и прямые Orders/ в корне не автосканируются. Metadata Resource(model:) не создаёт CRUD без enum.
[04](04-packages-and-layout.md), [16](16-crm-and-workflows.md), V78/V106.

---

<a id="d57"></a>

### D57 — Система имён: методы, атрибуты, классы, папки

**Кратко:** имена строятся по нескольким правилам, взятым из лучшего в Laravel и в библиотеках прав (Spatie,
Laratrust, Bouncer, Google Cloud IAM) и из сегодняшних атрибутов AzGuard. Атрибут — удобство, а не отдельный
механизм: у большинства атрибутов есть запись без атрибута (метод, соглашение об именах, middleware) — последняя
колонка таблицы (Q24).

**Методы.**

1. **Проверка — вопрос:** `can` (Laravel), `hasPermission`, `hasRole`, `hasAnyRole`, `hasAllPermissions`,
   `isSuperAdmin`. Без суффиксов вроде `To`.
2. **Изменение — одна пара глаголов для всего:** `grant` / `revoke` («выдать / забрать»), плюс `sync`:
   `grantRole`, `revokeRole`, `grantPermission`, `revokePermission`, `syncRoles`, `syncPermissions`.
3. **Запись о выдаче называется так же, как действие:** `RoleGrant`, `PermissionGrant`; таблицы `role_grants`,
   `permission_grants`; источники выдач — `ProvidesGrants`.
4. **События — действие в прошедшем времени:** `RoleGranted`, `RoleRevoked`, `PermissionGranted`,
   `PermissionRevoked`, `GrantExpired`.
5. **Где и до когда — предлоги:** `on:` (сущность), `until:` (срок), `fields:` (свои поля).
6. **Списки — существительные без `get`:** `roleNames()`, `permissionNames()`, `permissionSet()`.
7. **Списки в описании панели — массивы:** `->permissions([...])`, `->plugins([...])`, `->restrictions([...])` — как
   `->resources([...])` в Filament.
8. **Команды повторяют методы:** `azguard:roles:grant|revoke`, `azguard:permissions:grant|revoke`,
   `azguard:grants:list|prune`.

**Атрибуты** (правила как у Laravel: `#[UsePolicy]`, `#[ObservedBy]`, `#[Authorize]`; у Symfony/Laravel `#[As…]`):
существительное — «что это», глагол — «что делает», флаг — прилагательное или причастие, `As…` — регистрация по
имени.

| Где | Атрибут | Что значит | Сегодня | Вызов в коде |
|---|---|---|---|---|
| enum прав | `#[Resource(label:, model:)]` | домен: подпись, модель | папка, `#[GuardPolicy(model:)]` | `FolderSource` по папке |
| кейс enum | `#[Describe(label, group:, description:)]` | подпись и группа права | — | без атрибута — из имени кейса |
| кейс enum | `#[RequiresGrant]` | проверяется только выдачами, без политики | `#[RoleOnly]` | — |
| кейс enum | `#[GrantedToAll]` | право есть у каждого субъекта панели | — | автоматическая роль, `appliesTo()` → `true` |
| класс политики | `#[PolicyFor(Enum::class)]` | точная привязка enum вне однозначного pairing D56 | `#[GuardPolicy]` | `->policies([...])` |
| метод политики | `#[Decides(Perm::X)]` | метод решает это право | `#[GateAbility]` | имя метода = кейс |
| класс роли | `#[Role(key:, label:, level:)]` | ключ и подписи роли | `getName()`, `getLevel()` | методы `BaseRole` |
| класс роли | `#[SuperAdmin]` | держатель — суперадмин | роль `SuperAdminRole` | `superAdmin(): bool` |
| класс роли | `#[FormerKeys('old')]` | прежние ключи после переименования | — | `formerKeys()` |
| класс роли | `#[NotGrantable]` | только автоматически, вручную не выдаётся | — | `grantable(): bool` |
| метод контроллера | `#[CheckPermission(Perm::X, on: 'order')]` | проверка права (наследник Laravel `#[Authorize]`) | `#[CheckPermission]` | `azguard.can` |
| метод контроллера | `#[SkipPermissionCheck]` | явно без проверки в строгом режиме | `#[SkipGuardCheck]` | — |
| модель | `#[ContextFrom('store')]` | из какой связи брать сущность ресурса | — | `azguardContext()` |
| класс источника | `#[AsSource('ldap')]` | регистрация источника по имени | — | `AzGuard::extend()` |

**Классы и папки.** Провайдер — `{Panel}GuardPanelProvider` (не путается с `AdmguardProvider` Filament); enum —
`{Resource}Permission`; политика — `{Resource}Policy`; DTO — `{Resource}Abilities`; роль — `{Name}Role`; источник —
`{Name}Source`; плагин — `{Name}Plugin`; ограничение — `{Name}Restriction`. Папки — во множественном числе по роду
классов (`Roles/`, `Permissions/`, `Policies/`, `Sources/`), домены — во множественном числе по сущности (`Orders/`).

**Откуда взято.**

| Где смотрели | Как там | Что взяли | Что не взяли и почему |
|---|---|---|---|
| Laravel | `can`, `Gate::before/after`, `#[Authorize]`, `#[UsePolicy]`, `Manager::extend`, `Pipeline` | `can`; хуки `before`/`after`; `#[CheckPermission]` как наследник `#[Authorize]`; фабрика источников; pipes | — |
| Сегодняшний AzGuard | `#[GateAbility]`, `#[GuardPolicy]`, `#[RoleOnly]`, `#[CheckPermission]`, `#[SkipGuardCheck]`, папки панелей | атрибуты по смыслу, папки, провайдеры | названия с `Guard`/`Gate` там, где речь не о Laravel Gate |
| Spatie Permission | `assignRole`/`removeRole`, `givePermissionTo`/`revokePermissionTo`, `hasPermissionTo` | `hasRole`, `hasAnyRole`, `hasAllRoles`, `syncRoles`, `syncPermissions` | три разных пары глаголов и суффикс `To` |
| Laratrust | `addRole`/`removeRole`, `givePermission`/`removePermission`, параметр `team` | короткое `hasPermission`; сущность — необязательный параметр | «команда» узка: у нас любая сущность |
| Bouncer | `allow`/`disallow`/`forbid`, `assign`/`retract`, `scope()->to()` | идею явной области (у нас — сущность и панель) | `forbid` (явных запретов нет, Q12); грамматические `a`/`an` |
| Google Cloud IAM, SQL | «grant a role» / «revoke», `GRANT`/`REVOKE` | пару `grant`/`revoke` для ролей и прав | — |

Соответствие для тех, кто приходит со Spatie, — в [03 §7](03-glossary-and-renames.md#7-методы) и в разделе
документации «Если вы пришли из Spatie Permission».

---

<a id="d58"></a>
### D58 — Механизмы Laravel вместо своих

**Кратко:** AzGuard не изобретает своё там, где у Laravel уже есть механизм. Кто знает Laravel, узнаёт AzGuard с
первого взгляда: источники подключаются как драйверы кэша, хуки — как у Gate, изменения идут через Pipeline, проверки
на маршрутах — атрибутами контроллеров Laravel. AzGuard — конструктор, который собирает лучшее из Laravel в одну
панель.

**Решение.**

| Задача AzGuard | Механизм Laravel | Как выглядит |
|---|---|---|
| Фабрика источников, свои источники по имени | `Illuminate\Support\Manager` (как `Cache::extend`, `Storage::extend`, `Auth::provider`) | `AzGuard::sources()->extend('ldap', fn ($app, array $config) => new LdapSource($config))`, параметры — `config('azguard.sources.ldap')` |
| Хуки проверки | `Gate::before` / `Gate::after` | `->before(fn (AccessRequest $r, EvaluationContext $c): BeforeResult => …)` |
| Pipes изменений | `Illuminate\Pipeline\Pipeline` | `handle(Change $change, Closure $next)` |
| Реакции на изменения | события, слушатели, `ShouldDispatchAfterCommit` | `Event::listen(RoleGranted::class, …)` |
| Проверка на маршруте | атрибут контроллера `Illuminate\Routing\Attributes\Controllers\Middleware` | `#[CheckPermission]` — его наследник: middleware `azguard.can` вешает сам роутер Laravel; `#[Authorize]` Laravel тоже засчитывается в строгом режиме |
| Панель запроса | как `Auth::shouldUse()` | middleware `azguard.panel:admin` |
| Панель в очередях | `Context` (скрытое значение) | задача, поставленная из запроса админки, проверяет права в панели `admin` |
| Проверки по модели | `Gate::before` + домен с моделью | `$user->can('update', $order)` → `orders.update` (D53) |
| Зависимости классов | контейнер и его атрибуты | источники, ограничения, pipes, плагины создаются контейнером; `#[Config(...)]`, `#[CurrentUser]` работают |
| Свои модели | Eloquent и его атрибуты | `#[Table]`, `#[Connection]`, `#[ObservedBy]` на `AdminRoleGrant` |
| Плагин с миграциями и конфигом | обычный `ServiceProvider` пакета | `publishes()`, `loadMigrationsFrom()`; к панели плагин подключается в провайдере панели |
| Генераторы | `GeneratorCommand`, публикуемые стабы | `azguard:make:*`, `azguard:stubs` |
| Кэш каталога | `ServiceProvider::optimizes()` | `php artisan optimize` строит `azguard:catalog:cache`, `optimize:clear` чистит |
| Сведения о пакете | `AboutCommand::add()` | `php artisan about` показывает панели, источники, кэш |

- Свои контракты AzGuard появляются только там, где у Laravel аналога нет: `Source` и его возможности, `Restriction`,
  `Plugin`, `PanelSchema`.
- **Версии Laravel.** Атрибуты контроллеров (`#[Middleware]`, `#[Authorize]`) и атрибуты моделей (`#[Table]`,
  `#[Connection]`) есть только в Laravel 13 (атрибуты родительских контроллеров учитываются с 13.5). На Laravel 11–12
  `#[CheckPermission]` читает middleware `azguard.panel`, как сегодня, а модели задают `$table`/`$connection`
  свойствами; поведение одинаковое, сценарии V83 и V84 гоняются на всей матрице версий. `Manager`, `Pipeline`,
  `Context`, `optimizes()`, `AboutCommand::add()` есть во всех поддерживаемых версиях.
- Всё, что создаёт AzGuard по имени класса (источники, ограничения, pipes, хуки-классы, плагины), создаёт контейнер:
  привязки и декораторы приложения работают.

**Отвергнуто.** Своя система событий или хуков-наблюдателей (`changed()`): Laravel-события уже дают это, включая
очереди и отправку после commit. Свой сканер атрибутов контроллеров: роутер Laravel уже читает наследников
`#[Middleware]`.


---

<a id="d59"></a>
### D59 — Тенант отделён от контекста

**Кратко:** Organization — граница данных; Project — область роли внутри неё.
TenantPolicy required/none и AccessScope `(TenantRef, ContextRef)` обязательны для reads/writes/events/schema/cache.
Global context означает tenant-wide, global tenant не наследуется в организации. Только explicit allowGlobalRoles
подключает platform RootRole. Resource owner boundary не освобождает суперадмина.
Dynamic definitions принадлежат tenant, static definitions панели доступны для scoped назначения.
[08](08-data-model-and-migration.md), [09 §3](09-authorization-semantics.md#3-тенант-контекст-и-ресурс).

<a id="d60"></a>
### D60 — Классы контекстов и связь с кодовыми ролями

**Кратко:** несколько BaseRole классов ссылаются на один ProjectContext descriptor и свои typed filters.
ContextDefinition SPI: stable type/model/exists/tenantOf. RoleGrant хранит stable role/context aliases, не FQCN,
не роль/filters в JSON. contexts default [] = tenant-wide only; contextRequired=true требует конкретный context.
Common active AND role-specific city applies before OR contributions. BaseRole actual instance — runtime role input.
[CRM](16-crm-and-workflows.md), [18](18-contexts-and-runtime-inputs.md).

<a id="d61"></a>
### D61 — Условия одной выдачи и явные ролевые contributions

**Кратко:** права разных строк можно объединять, условия разных строк — нельзя.
ProvidesRoleGrants возвращает RoleContribution, даже если permissions роли пусты. Core проверяет role scope,
expiry и GrantCondition прежде superadmin/expansion. Direct grants проходят ту же qualification.
AND условий/context filters одной выдачи, OR выдач; common eligibility и общие Restriction проверяют любое Allow.
Фильтр seller не ограничивает independent analyst contribution (D75–D76).
Before Deny/error окончателен; Continue не является authority; source errors не скрываются shortcut Allow. [09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм).

<a id="d62"></a>
### D62 — Источники: lifecycle, authority и origin

**Кратко:** definition не хранит текущего пользователя; каждый сохранённый вклад имеет владельца.
SourceManager использует Manager registry custom creators, но make не переиспользует глобальный driver cache.
Runtime source с scoped зависимостями создаётся на request/job. Stable требует dependency revision, Request/Volatile
объявляют window; panel token не версионирует LDAP/host relations. Origin входит в key grants; sync/revoke
одного origin не удаляет соседний. Plugin prefix преобразует catalog, Role.permissions, policy bindings,
Decides/enum references и schema согласованно, не только presentation strings.

<a id="d63"></a>
### D63 — Сериализованные изменения и scope административных операций

**Кратко:** state lock первый; final validation внутри retry-safe transaction.
Deletes/grants/sync/fingerprint checks сериализуются по panel_state до чтения dynamic definitions.
Pipes не меняют security identity и не выполняют внешние side effects. Eloquent прямые writes запрещены
во всех окружениях. Actor delegation — ответственность приложения; structural tenant integrity — ядра.
Root commit публикует state, nested rollback не публикует. UI record lookup/bulk/search всегда scope+origin.
[08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы).

<a id="d64"></a>
### D64 — Честные снимки, сроки и restore

**Кратко:** version fence проверяет целый набор DB, expiry проверяется каждый раз.
T_before/T_after окружает все DB chunks и dynamic overlay; bounded retry или ConsistencyError.
Token несёт storageId/incarnation/build fingerprint. DecisionSet.states — несколько tokens, не один глобальный.
Final Allow нельзя кэшировать по DB token. Membership/resource/external data имеют собственную authority.
Authorize не атомарен с защищаемым действием; TOCTOU protocol хоста описан в [09 §14](09-authorization-semantics.md#14-проверка-и-защищаемое-действие).

<a id="d65"></a>
### D65 — Gate и credentials не ослабляют boundary

**Кратко:** user token ограничивает, definitive Deny не превращается в Laravel fallback.
Sanctum abilities — Restriction, не independent permission source пользователя. Service principal capabilities
отдельно с trust mapping. Response deny сохраняется. Qualified unknown owned key -> отказ; ambiguities -> error.
Owned result окончателен; null только для чужой ability. Third-party earlier before hooks требуют host проверки
порядка; sensitive actions используют direct pipeline. [09 §7](09-authorization-semantics.md#7-gate-laravel).

<a id="d66"></a>
### D66 — Exact видимость и парные query adapters

**Кратко:** scalar decide и SQL list совпадают по поддержанным компонентам; неполный фильтр не выпускает данные.
Source grant predicate плюс FiltersAccessQueries остальных компонентов составляют exact plan.
Tenant/ownership/restrictions обязательны даже для superadmin. Фильтр до count/page/export;
unsupported/cross-connection -> exception. Internal candidates не публичная безопасная коллекция.
[09 §10](09-authorization-semantics.md#10-видимость-visibleto).

<a id="d67"></a>
### D67 — Внешние tenant providers и синхронизация

**Кратко:** источники переводят свои identities в stable host refs; внешнее id не глобально уникально.
Tenant/context directories и descriptors — SPI пакета. Host/integration owns
(provider,installation,external_tenant_id) mappings, credentials отдельно. Origin/revision защищают imports;
partial sync не отзывает отсутствующие страницы, старый webhook не возвращает право. У каждой связи declared
authority и suspension rule. Нет обязательной универсальной таблицы organizations в AzGuard.
[16 §10](16-crm-and-workflows.md#10-несколько-внешних-систем-одного-тенанта).

<a id="d68"></a>
### D68 — Scoped dynamic каталог и будущее шаблонных прав

**Кратко:** dynamic definitions одного tenant не видны другому, wildcard расширяется при новом action сознательно.
Static names/role keys зарезервированы во всех tenants. Dynamic policies допускают явную string binding;
автогенерация PHP из DB запрещена. Delete exact action удаляет exact role/direct grants; patterns остаются.
Deploy проверяет prefix/key/alias collisions и обновлённое wildcard покрытие. Шаблон означает нынешние и будущие
actions namespace; UI это показывает, delegation policy отдельно разрешает такое расширение.

<a id="d69"></a>
### D69 — Границы adapters и доказательства качества

**Кратко:** обещания API должны быть выполнимы на версии Laravel и конкретном SQL connection.
Contracts разделяются на чистые protocol/value interfaces и Laravel-facing adapters; последние могут ссылаться
на Model/Request и не притворяются pure Kernel. DatabaseSource reading/writing отделены: Changes owns orchestration,
StoresGrants пишет validated Change под transaction, не запускает ChangePipeline рекурсивно.
Laravel 11/12 атрибут CheckPermission не наследует отсутствующий класс Laravel13; используется version adapter
с одинаковым public shape. Published constraints/fixtures проверяются по реально разрешимым сочетаниям версий,
не декартовому произведению несовместимых releases. Evidence distinguishes static findings, model tests,
старые 0.3 probes и будущие runtime 1.0 gates. [evidence](evidence/design-review.md).


<a id="d70"></a>
### D70 — Разделение механизмов и предметных групп; Users/Projects

**Кратко:** группы действий всегда внутри корней типов классов; роли сущности определяются контрактом.
Владелец указал на коллизии Orders/ с Sources/ и неоднозначность Users/. Первоначальное предложение
Resources уточнено его последующим выбором D72: Permissions/<Group>, Policies/<Group>, Queries/<Group>.
Subject models задаются for(), context types — ContextDefinition. Project может быть объектом действий,
областью назначения роли или субъектом тарифных прав в разных вызовах.
Discovery/generators/modules/cache используют одну раскладку; прямые root группы не поддерживаются.

<a id="d71"></a>

### D71 — Metadata Resource вместо Domain и PanelBuilder::for

**Кратко:** #[Resource(label:, model:)] описывает объект действий; for([...]) задаёт получателей прав.
По уточнению владельца Domain не используется в целевых публичных именах. Resource — metadata на enum,
а не имя папки или дополнительный обязательный descriptor класс. Имена OrderPermission и подобных enums
сохраняются. Окончательная раскладка и команды — D72; первоначальный make:resource заменён make:permission.
Relations означает связи объектов/субъектов, которые читает RelationSource.

PanelBuilder::for(array<class-string<Model>>, guard:, directory:) задаёт принимаемые типы.
PanelAccess::for(Model|Authenticatable|SubjectRef) создаёт wrapper конкретного субъекта; это разные receivers.
SubjectRef/SubjectDirectory/schema subjects сохраняют точное обозначение позиции в запросе.
Это выбор проекта; внешние источники не предписывают название for() для нашего API.

<a id="d72"></a>
### D72 — Утверждённая структура Permissions/<Group> и параллельные корни

**Кратко:** владелец подтвердил Permissions/Orders/ с параллельными Policies/Orders/ и Queries/Orders/.
Подтверждение 2026-09-30 следует после обсуждения альтернатив; Resources как контейнер убран.
Это улучшение структуры проекта: на один уровень меньше при сохранении изоляции механизмов и групп.
Все классы политики/query не складываются в Permissions; каждый находится в корне своего типа.
Abilities/<Group>, Roles/, Contexts/, Sources/, Resolvers/, Restrictions/, Changes/, Models/, Plugins/
сохраняют отдельные обязанности. Модели приложения находятся в host app/Models.

FolderSource pairing — D56: конкретный discovery root + относительный путь группы, затем exact enum FQCN;
несколько кандидатов требуют PolicyFor/Decides, последний найденный класс не побеждает молча.
Одинаковые Orders у двух плагинов или Sales/Orders и Support/Orders не смешиваются по короткому имени.
Генератор azguard:make:permission {Panel} {Group} создаёт enum; --policy/--abilities добавляют параллельные файлы.
Отдельного генератора make:resource и discovery.resources нет; фильтры подключаются явно.
P2.8/P6.8/P8.4 и V78/V106 проверяют panel/module/plugin stubs, pairing, коллизии и cache equivalence.

<a id="d73"></a>
### D73 — PanelBuilder::permissions для enum definitions и источников

**Кратко:** по запросу владельца метод подключения источников называется permissions([...]).
Раньше permissions регистрировал только enums; теперь один метод принимает смешанный list:
string-backed enum class-strings, Source objects и зарегистрированные имена SourceManager.
Enum добавляется в FolderSource; источники подключаются к панели после FolderSource в порядке регистрации.
Повтор enum FQCN из discovery/manual registration идемпотентен. Повтор source id или два писателя — ошибка.
Повторные permissions calls добавляют элементы; plugins/configure используют ту же нормализацию.

Не-enum class-string не становится источником только из-за имени класса: Source создаётся явно либо через
зарегистрированную фабрику. Некорректный тип элемента — DefinitionException; неизвестная строка — UnknownSourceException.
Bare action string здесь не создаёт право, dynamic actions создаёт scoped PermissionManager. Явный FolderSource
может один раз настроить встроенный экземпляр; несколько явно заданных FolderSource — конфликт, не last-wins.

PanelBuilder не имеет отдельного sources метода или второй permissions сигнатуры; старого alias нет (D01).
Классы Source/FolderSource/DatabaseSource/RelationSource, папка Sources, config sources.*, фабрика AzGuard::sources()
и getter Panel::sources() сохраняют значение механизма источников. Runtime PanelAccess::permissions() возвращает
PermissionManager; BaseRole::permissions() возвращает permissions роли — это другие receivers.

D73 уточняет D45/D52/D57. API/extension points/examples/config/rename table/workstreams используют единое имя.
V77/V107 и P2.1/P2.7/P2.8/P6.8/P8.4 проверяют mixed input, manifests и generated consumers.


<a id="d74"></a>
### D74 — guard selector с сохранением native Eloquent guard

**Кратко:** строка выбирает панель; массив сохраняет существующее mass-assignment поведение Eloquent.
Владелец заменил inPanel на guard. Model::guard(array $guarded) уже существует, поэтому trait использует
совместимую сигнатуру guard(array|string $guarded): static|SubjectAccess, array delegates parent; parameter name
сохранён для named arguments. SubjectPanels::guard(string) — чистый selector без перегрузки.
String-only override запрещён; custom override требует явного адаптера. Auth::guard/for(..., guard:) — auth guard,
не панель. [18 §1](18-contexts-and-runtime-inputs.md#1-селектор-guard), V108/R03, P2.1/P5.1/P8.7.

<a id="d75"></a>
### D75 — Общие context recipes и конфигурируемые ролевые bindings

**Кратко:** ProjectContext::make()->query(...) работает и для панели, и для отдельной роли.
BaseContext fluent settings immutable; class-string остаётся shorthand. Global defaults/plugin/provider predicates
складываются AND, роль добавляет свои filters в собственную contribution; independent roles объединяются OR.
Role binding не меняет alias/model/owner и не удаляет common filters. Presentation precedence отделён от authority.
Схема публикует definition/filter class metadata, не runtime closures/models. [18 §2–4](18-contexts-and-runtime-inputs.md), V109.

<a id="d76"></a>
### D76 — Native Eloquent query и один exact eligibility plan

**Кратко:** callback получает настоящий Builder, а одинаковые predicates обслуживают scalar/batch/query/editor.
Common is_active применяется до любого Allow; seller city/role region только в его ветке. Builder WHERE/whereHas/
local scopes/grouped OR поддержаны; внешний tenant/key/common boundary добавляет core с grouping.
Замена builder/model/connection/from, terminal calls/root joins/union/limit требуют отдельного exact adapter или
отклоняются. PHP callbacks — trusted code, не sandbox. Predicate ограничивает, не выдаёт право.
Unsupported exact query не возвращает широкий список. [18 §4](18-contexts-and-runtime-inputs.md), V110.

<a id="d77"></a>
### D77 — Явные operation inputs и фазы

**Кратко:** target user, actual BaseRole, actor, grant, scope, now и phase передаются при каждой операции.
Container::call получает reserved inputs; service DI native, нет global CurrentUser/Role binding/empty ORM model.
Access/Assignment/Revocation/Inspection различаются: inactive/expired/orphan grant можно отозвать authorised actor;
Assignment повторно проверяется после pipes. Конфигурация filters/roles — PHP typed objects, UI меняет только
назначения/expiry/declared fields и opt-in dynamic actions. [18](18-contexts-and-runtime-inputs.md), V111–V114.

<a id="d78"></a>
### D78 — Плагины: собственная typed factory и runtime capabilities

**Кратко:** CrmAccessPlugin::make(models: new CrmModels(...), projects: ...) явно получает зависимости.
BasePlugin не задаёт универсальную factory. PluginContext содержит panel/plugin/build/dependencies/namespace,
конфигурация принадлежит typed полям plugin. Если нужны DI сервисы — собственная factory makeWith с явными named
parameters, не пустой app(static::class) singleton. Build configuration не удерживает request/user/role/Builder.
Runtime capability получает user/actual BaseRole/grant/actor/scope при операции. Cache только metadata, provider
восстанавливает PHP definitions; fingerprint включает deployed build id. [19](19-oop-and-permission-authority.md).

<a id="d79"></a>
### D79 — Готовность к проектам подтверждает реальная CRM-приёмка

**Кратко:** 68 сценариев R01–R68 выполняются через реальный AzGuard на real consumer/SQL/HTTP/UI/workers.
[Отдельное ТЗ](17-crm-acceptance-tests.md) задаёт fixture, expected ids, positive controls, concurrency barriers,
query budgets, supported DB/Laravel matrix и отчёт passed/failed/blocked/unsupported.
Mock core/model-only probes не заменяют эти тесты. До реализации случаев статус future, не green.
P8.7 и V116 — обязательные ворота релиза; design model проверяет только формулу спецификации.


<a id="d80"></a>
### D80 — Роли только PHP-классы; назначения независимо от definitions

**Кратко:** в 1.0 нет DB ролей, CRUD role definitions и role composition editing.
BaseRole задаёт permissions/context filters/superAdmin/required; Folder/automatic/relation/DB могут назначать
одну definition. DB хранит scoped role_grants/permission_grants, не roles/role_permissions/role_contexts.
Enum права тоже назначаются в БД без копирования definitions. RoleCatalog read-only; неизвестный removed key
zero authority и authorised cleanup. Уточняет D13/D14/D19/D60/D77, [19 §1/3/6](19-oop-and-permission-authority.md).

<a id="d81"></a>
### D81 — Фильтры как конкретные классы и типизированные настройки

**Кратко:** query(new SellerProjects(...)) показывает класс/constructor inputs; string profiles/JSON operators нет.
ContextQueryFilter.apply(Builder, ContextRuntime), actual BaseRole/user/actor передаются отдельно. Exact FQCN filter
принимается только если implements SPI, container resolve на operation. Common AND role branch AND/OR сохраняются.
Concrete context factory собственная, base не диктует make/options; PHP config change требует new build.
Нативные homogeneous lists/config/rules/declared fields arrays сохраняются, generic behavior bags не вводятся.
Уточняет D75–D77, [18](18-contexts-and-runtime-inputs.md).

<a id="d82"></a>
### D82 — Собственные named typed factories плагинов

**Кратко:** CrmAccessPlugin::make(models: CrmModels, projects: definition, ...) — видимая PHP-сигнатура.
BasePlugin/Plugin SPI не объявляют универсальный make/options, чтобы concrete factory не нарушала PHP LSP.
Plugin-specific DTO validates class-string subtype/instantiability/contracts, shared config не mutable.
PluginContext только build panel/plugin/dependencies/namespace; settings в typed полях plugin, runtime capabilities
получают fresh inputs. Уточняет D47/D78, [19 §4](19-oop-and-permission-authority.md#4-плагин-параметры-видны-в-php).

<a id="d83"></a>
### D83 — Explicit authority mode и типизированные свидетельства состояния

**Кратко:** PolicyOnly — policy без assignment DB; RequiresGrant — assignment с optional policy veto.
Mode explicit на enum/case, custom definition возвращает PermissionAuthority. PolicyOnly grant запрещён, null deny;
Grants policy true/null pass только при qualifying grant. Dynamic actions only Grants, opt-in; assignments enum
работают без этого flag. BeforeResult Continue/Deny не выдаёт authority, superadmin только Grants, boundaries всегда.
CodeStateToken отделён от DB StateToken; Policy-only dispatch не читает DB ради token. Code token не версионирует
host business data. Exact adapters/schema/editors/mutations/deployment отражают тот же mode.
Уточняет D48/D53/D55/D79; [19](19-oop-and-permission-authority.md), [20](20-process-map.md), R61–R68/V117–V120.


Optional RequiresGrant business veto подключается явным PolicyBinding(action, class, method), не догадкой
по присутствию метода. Missing declared method/class — compile error. PolicyOnly binding всегда обязателен;
folder/PolicyFor/Decides pairing допустим при однозначной цели. Метод не переименовывается в silent no-policy pass.
