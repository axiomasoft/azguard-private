# 10 — Интеграции: как другие пакеты работают с AzGuard

Решения: [D43](02-decisions.md#d43), [D47](02-decisions.md#d47), [D50](02-decisions.md#d50), [D51](02-decisions.md#d51),
[D54](02-decisions.md#d54).

## 1. Простыми словами

AzGuard отвечает на один вопрос: «может ли этот субъект сделать это действие здесь». Другим пакетам экосистемы
(Vaulter с файлами и документами, в будущем — другие) нужен этот ответ. Ещё они могут принести в AzGuard свои права,
роли, политики и источники.

**Мост к конкретному пакету пишет сам этот пакет.** Vaulter знает свои папки, документы и уровни доступа; как
перевести их на язык AzGuard, решает Vaulter. Задача AzGuard — дать **небольшой и стабильный набор разъёмов** и не
ломать его. Тогда любой пакет встраивается одинаково и дорабатывается под себя, не трогая ядро AzGuard.

```
        пакет-интеграция (Vaulter, другой пакет)
        ┌───────────────────────────────────────────┐
        │  свои сущности → ContextRef / права        │   ← это пишет пакет
        │  свой плагин для панели AzGuard            │
        └──────────────┬──────────────────┬──────────┘
             спрашивает│                  │ подключается к панели,
          decideMany и │                  │ которую выбрало приложение
               трейт   ▼                  ▼
        ┌───────────────────────────────────────────┐
        │  AzGuard: панели, источники, хуки, схема   │   ← стабильный контракт (@api/@spi)
        └───────────────────────────────────────────┘
```

## 2. Три уровня интеграции

Можно начать с первого уровня и переходить к следующим, когда понадобится.

| Уровень | Что делает пакет | Чем пользуется | Пример |
|---|---|---|---|
| **A. Спрашивает** | проверяет права перед своими действиями в конкретном tenant/context; права и роли описывает приложение | трейт, scoped `decideMany`, authoritative Gate, `StateToken` | пакет чата проверяет `chat.moderate` |
| **B. Приносит права** | поставляет плагин: папку с доменами (enum прав + политики), роли по умолчанию, doctor-проверки; приложение подключает плагин к нужной панели | `Plugin`, `PanelBuilder::discover()`, `BaseRole`, `#[Resource]`, `prefixed` | Vaulter приносит `documents.view/edit/share` и роль «Редактор документов» |
| **C. Приносит источники и реакции** | свой источник, ограничения, pipes; слушает события AzGuard | `Source` и его возможности, `#[AsSource]`, `Restriction`, pipes, события Laravel | пакет биллинга даёт права по оплаченному тарифу своим источником `billing` |

## 3. Что AzGuard гарантирует

Всё ниже помечено `@api` (вызывать) или `@spi` (реализовывать), попадает в `api-manifest.json` и защищено проверками
совместимости ([12](12-operations-and-release.md)).

| Потребность пакета | Разъём AzGuard | Где описан |
|---|---|---|
| Спросить «можно ли» | трейт `HasAzGuard`, `AzGuard::panel($id)->decide()/decideMany()/explain()` | [05 §1](05-php-api.md#1-модель-трейт-hasazguard), [05 §2](05-php-api.md#2-панель-azguardpanel) |
| Понять, почему отказ | `Decision::$reason` (`NotGranted`, `Policy`, `Restricted`, …) | [05 §5](05-php-api.md#5-значения-ядра-azguardkernel) |
| Знать, что права изменились | `state(): StateToken` для своих кэшей; события после commit | [08 §6](08-data-model-and-migration.md#6-каталог-событий), [09 §8](09-authorization-semantics.md#8-кэш-и-консистентность) |
| Встроиться в панель | `Plugin` + `AzGuard::configurePanel()`; пакет даёт плагин, **приложение выбирает панель** | [06 §3](06-extension-points.md#3-плагины), [06 §8](06-extension-points.md#8-модули-и-сторонние-пакеты-внутри-приложения) |
| Свои права, роли, политики | папка пакета с доменами (enum + политика, `#[Resource]`, `#[PolicyFor]`), `prefixed`; `BaseRole`; `->discover()` | [05 §6](05-php-api.md#6-роли-в-коде), [05 §7](05-php-api.md#7-ресурсы-и-политики) |
| Свой источник, ограничение, pipe | `Source` + `ProvidesGrants`/`ProvidesPermissions`/…, `AzGuard::sources()->extend()` или `#[AsSource]`, `Restriction`, pipes | [06 §1](06-extension-points.md#1-источники-фабрика), [06 §2](06-extension-points.md#2-свой-источник), [06 §4](06-extension-points.md#4-хуки-проверки), [06 §5](06-extension-points.md#5-хуки-изменений-pipes-и-события) |
| Описать себя для интерфейсов | подписи и группы прав (`#[Describe]`), поля — попадают в `PanelSchema` | [05 §8](05-php-api.md#8-схема-панели) |
| Свои проекты и внешние tenants | ContextDefinition, TenantMembership/Directory и ResourceScopeResolver; AccessScope | [06 §7](06-extension-points.md#7-контексты-и-субъекты) |
| Перевести свою сущность в контекст | `ContextRef::of(type, id)`, `ContextAware` | [09 §3](09-authorization-semantics.md#3-тенант-контекст-и-ресурс) |
| Выдать права от своего имени | `$user->guard($id)->grantRole(...)` внутри `AzGuard::actingAs('vaulter: share', …)` | [05 §3](05-php-api.md#3-фасад) |
| Проверить себя | `IntegrationContractTests`, `PluginContractTests`, `SourceContractTests` против настоящего AzGuard | [06 §10](06-extension-points.md#10-контрактные-наборы-azguardtestingcontracts) |
| Проверить конфигурацию у приложения | `DoctorCheck` в своём плагине или источнике → `azguard:doctor` | [06 §9](06-extension-points.md#9-doctor) |

AzGuard **не** обещает: классы в `Internal\`, модели и таблицы хранилища как способ записи, формат кэша. Опора на них
ломается без предупреждения.

## 4. Шаблон пакета-интеграции

Минимальный пакет уровней A и B. Имена условные.

```php
// 1. Права пакета — enum ресурса с локальными именами, без id панели (структура D56)
#[Resource(label: 'Документы')]
#[RequiresGrant]
enum DocumentPermission: string
{
    #[Describe('Смотреть документы')] case View = 'documents.view';
    #[Describe('Редактировать документы')] case Edit = 'documents.edit';
}

// 2. Плагин: что пакет приносит в панель
final class AcmeAzGuardPlugin extends BasePlugin              // make() и prefixed() — из базы
{
    public function id(): string { return 'acme/azguard'; }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->discover(__DIR__.'/Guards')                  // домены пакета: Permissions/Documents, Policies/Documents; Roles/
            // с prefixed('acme') → acme.documents.view; у панели с префиксом admin → admin.acme.documents.view
            ->doctorChecks([AcmeContextTypeCheck::class]);    // «панель принимает тип сущности acme_folder»
        // роли пакета (Roles/) появятся в редакторах через схему панели
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        app(AcmeAzGuardPanels::class)->attach($panel->id());  // пакет запоминает, к каким панелям его подключили
    }
}

// 3. Приложение решает, куда подключить
$panel->plugins([AcmeAzGuardPlugin::make()->prefixed('acme')]);   // в своём PanelProvider

// 4. Пакет спрашивает — в панели, к которой его подключили
$user->guard($panels->primary())->hasPermission(DocumentPermission::Edit, on: $folder);
```

Если плагин подключён к двум панелям, пакет обязан указать панель явно; иначе `AmbiguousPanelException`
([D05](02-decisions.md#d05)). Молчаливого выбора нет.

## 5. Правила для пакетов-интеграций

Правила войдут в руководство «Интеграция вашего пакета» ([12 §6](12-operations-and-release.md#6-документация)).

| Делать | Не делать | Почему |
|---|---|---|
| Хранить у себя локальные имена прав; id панели брать из подключения плагина или конфига приложения | Зашивать `admin`/`cabinet` в код пакета | приложение само решает, в какой панели живут права пакета |
| Спрашивать окончательное решение каждый раз; кэшировать только contributions с full scope/revision/expiry contract | Копировать права AzGuard в свои таблицы | копия отстаёт от отзыва права — дыра в безопасности |
| Для пачки проверок использовать `decideMany` | Делать N проверок в цикле | validated authority по группе; число queries учитывает chunks/definitions/fence |
| Менять права только через API AzGuard | Писать в модели и таблицы AzGuard напрямую | обход проверок, событий и версии состояния |
| Переводить свои сущности в `ContextRef` через morph alias | Использовать `:` в типе, составные id | ключ сущности однозначен только при этих правилах ([D07](02-decisions.md#d07)) |
| Реагировать на события AzGuard | Опрашивать таблицы AzGuard | таблицы — не контракт |
| Считать любой `Deny` отказом и показывать `reason` в диагностике | Трактовать ошибку как разрешение | ошибка = отказ |
| Указывать `axiomasoft/azguard: ^1.0` | Требовать `dev-main` | стабильный релиз пакета невозможен с `dev-main` |
| Прогонять `IntegrationContractTests` в своём CI | Мокать AzGuard целиком | мок скрывает ошибки вроде N05 |

## 6. Проверка интеграции

```php
use AzGuard\Testing\Contracts\IntegrationContractTests;

uses(IntegrationContractTests::class);

beforeEach(function () {
    $this->azguardPanel('workspace', fn (PanelBuilder $p) => $p->default()->plugins([AcmeAzGuardPlugin::make()->prefixed('acme')]));
    $this->azguardPanel('admin');                             // вторая панель — проверка независимости
});
```

Набор проверяет на **настоящем** AzGuard:

- решение одинаково через трейт, `decideMany` и Gate;
- после выдачи и отзыва меняется `StateToken`, события приходят после commit;
- права пакета не утекают во вторую панель;
- плагин собирается на чистой панели и на двух панелях сразу;
- неизвестное имя права или неопределимая панель дают явную ошибку, а не молчаливый отказ.

## 7. Совместимость версий

| Период | Обещание AzGuard |
|---|---|
| `1.0.0-beta.N` | `@api`/`@spi` ещё могут меняться; каждое изменение — в `CHANGELOG.md` с разделом «для интеграций» |
| с 1.0 | ломающие изменения `@api`/`@spi` только в major; Roave BC Check в CI |
| всегда | новые возможности SPI добавляются **новыми** необязательными интерфейсами (`BatchRestriction` рядом с `Restriction`), а не новыми методами в существующих |
| всегда | в CI AzGuard есть «пример интеграции» (`fixtures/example-integration`): плагин + `decideMany` + `IntegrationContractTests`. Изменение, ломающее контракт, падает в AzGuard раньше, чем у пакетов |

## 8. Общее с экосистемой и своё

Пакеты экосистемы должны быть **устроены** одинаково: разработчик, знающий Vaulter, быстро разбирается в AzGuard. При
этом **предметные слова у каждого свои** ([D43](02-decisions.md#d43)): авторизация и хранение файлов — разные
предметы.

### 8.1 Общие инженерные правила (ADR «Ecosystem conventions», один текст в репозиториях экосистемы)

| Тема | Vaulter | AzGuard |
|---|---|---|
| Установка | `axiomasoft/vaulter` (сейчас `axioma-studio/vaulter`, переход — задача Vaulter) | `axiomasoft/azguard` — единый vendor экосистемы (Q23) |
| Версии пакетов внутри продукта | `self.version` | `self.version` |
| Конфиг | файл на пакет, readonly `*Config`, проверки при загрузке, `config()` только в `Configuration\` | то же |
| Ключи хоста | `vaulter.ids.host_keys` = string\|bigint\|uuid\|ulid | `azguard.ids.host_keys`, те же значения |
| Подключение и префикс таблиц | `vaulter.database.connection`, `table_prefix = 'v_'` | `azguard.storages.<имя>.connection`, `table_prefix = 'azg_'` (хранилищ может быть несколько) |
| «От чьего имени» | `->actingAs($user)`, `->asSystem($reason)`, `ActorRef`, `SYSTEM_TYPE='vaulter:system'` | `AzGuard::actingAs(...)`, `ActorRef`, `SYSTEM_TYPE='azguard:system'`; актор необязателен |
| Конверт события | `eventId` (ULID), `occurredAt`, `actor`, `correlationId`; `EventType` `noun.verb_past`; после commit | то же + `state` (`StateToken`) |
| Ошибки | `<Condition>Exception`, код `snake_case` | то же |
| Команды | `vaulter:<area>:<verb>`, `vaulter:make:*`, `vaulter:doctor --json` | `azguard:<area>:<verb>`, `azguard:make:*`, `azguard:doctor --json` |
| Ключи расширений | `vendor/name` | `vendor/name` |
| Тесты для потребителей | `Vaulter\Testing\` + контрактные наборы | `AzGuard\Testing\` + контрактные наборы |
| Ссылки на сущности хоста | morph alias + id строкой | morph alias + id строкой (`SubjectRef`, `ContextRef`) |

### 8.2 Свои предметные слова

| AzGuard | Что это | Похожее в Vaulter | Почему не одно слово |
|---|---|---|---|
| **Panel** | конструктор прав части приложения: субъекты, источники, настройки | **Profile** — набор политик для drives | панель описывает *кто и что может*, профиль — *как ведёт себя хранилище* |
| **Context** | сущность, в которой действует право (магазин, проект) | **Owner** drive, tenant | контекст выбирает выдачи, владелец/tenant изолирует данные |
| **Permission grant** | выдача права субъекту без роли | **NodeGrant** — доступ к узлу дерева | у AzGuard — право на действие, у Vaulter — уровень доступа к файлу или папке |
| **Policy** (второй уровень AzGuard) | метод политики домена, уточняющий выданное право | профиль выбирает драйвер прав | разные уровни: у AzGuard — одно право, у Vaulter — поведение drive |
| **Role**, **Role grant** | набор прав и его выдача | — | в Vaulter ролей нет, доступ задаётся уровнями |

Совпадение слов не подгоняется. Если понятие в двух пакетах действительно одно (актор, событие, ключ хоста), оно
одинаково называется и кодируется. Если понятия похожи, но разные, у них разные имена.

## 9. Идея моста Vaulter ↔ AzGuard

Это **идея** для отдельной задачи Vaulter (Q13). Реализацию и окончательное устройство выбирает Vaulter.

### 9.1 Что делят между собой пакеты

| Вопрос | Кто отвечает |
|---|---|
| Кто может работать с документами этого workspace и на каком уровне (читатель, комментатор, редактор, организатор, владелец) | **AzGuard** — по ролям и правам панели приложения в контексте владельца drive |
| Кому расшарен конкретный файл или папка, публичные ссылки | **Vaulter** — NodeGrant, share links |
| Как ведёт себя drive (квоты, версии, типы файлов) | **Vaulter** — профиль |
| Суперадмин | **AzGuard** — роль с признаком суперадмина в панели, `isSuperAdmin()` |

### 9.2 Как это может выглядеть

1. **Плагин `vaulter/azguard`** (в репозитории Vaulter) приносит в выбранную приложением панель:
   - enum прав уровней: `documents.read`, `documents.comment`, `documents.write`, `documents.organize`,
     `documents.own`, плюс `documents.create` для создания в drive;
   - роли из кода «Читатель документов», «Редактор документов», «Организатор документов» (выдаются вручную,
     поэтому сразу появляются в редакторах Filament через схему панели);
   - doctor-проверку: панель принимает тип владельца drive как контекст (`ContextPolicy`), права есть в каталоге.
2. **Драйвер прав Vaulter** (`PermissionDriver` — контракт Vaulter) переводит вопросы Vaulter в вопросы AzGuard:

   | Метод Vaulter | Что спрашивает у AzGuard |
   |---|---|
   | `resolve(subject, actor, scope)` | `decideMany` по пяти правам уровней в контексте `ContextRef::of(owner.type, owner.id)`; высший разрешённый уровень → `GrantLevel` |
   | `override(actor, scope)` | `$actor->guard($panel)->isSuperAdmin()` → allow |
   | `subjectsFor(actor)` | `roleNames(on: owner)` → роли AzGuard как субъекты Vaulter. Тогда NodeGrant можно выдать роли: «папка доступна роли Редакторы» |
   | `canCreateIn(actor, scope)` | `hasPermission('documents.create', on: owner)` |

3. **Кэш.** Листинг Vaulter кэширует решения с `$panel->state()` в ключе: любое изменение прав в панели делает кэш
   неактуальным без ручной очистки.
4. **Панель** выбирает приложение: `->plugins([VaulterAzGuardPlugin::make()->prefixed('vaulter')])` в нужном
   `PanelProvider`; драйвер узнаёт id панели из `boot()` плагина. Если панелей с плагином несколько, профиль Vaulter
   указывает, какую использовать.
5. **Проверка.** Мост прогоняет `IntegrationContractTests` AzGuard и контрактные тесты драйверов Vaulter против
   настоящего AzGuard с зарегистрированными панелями (сейчас тест моста проходит только потому, что панелей нет, N05).

### 9.3 Чего мост делать не должен

- Копировать роли или права AzGuard в таблицы Vaulter.
- Передавать имена прав без панели в надежде на угадывание: панель — из подключения плагина.
- Переносить NodeGrant в AzGuard или ролевую модель AzGuard в Vaulter: у пакетов разные предметы, мост складывает
  их ответы, а не заменяет один другим.

## 10. Заметки для Vaulter по текущему мосту

Найдено при аудите (`vaulter/packages/azgard` на `a572121`). Это **заметки**, а не решения. Со стороны AzGuard
исправляется только то, что относится к AzGuard (последний столбец).

| # | Наблюдение | Что делает AzGuard |
|---|---|---|
| V1 | Мост передаёт имя `documents.view` без панели и `panelId: null`. AzGuard оценивает его в панели `app`, каталог молча отбрасывает имя, и в приложении с панелями доступ всегда запрещён (N05, P05). Тест моста зелёный, потому что в нём нет панелей | одно правило выбора панели ([D05](02-decisions.md#d05)); неизвестное имя или неопределимая панель → явная ошибка; `IntegrationContractTests` регистрирует панели |
| V2 | Docblock `AzgardGuardAdapter.php:20-22` обещает вывод панели из первого сегмента ключа — это верно только для одного метода AzGuard | правило одно для всех методов ([D05](02-decisions.md#d05)) |
| V3 | До 5 последовательных `hasPermissionIn()` на уровень доступа, каждый с переключением контекста | `decideMany` с общим снимком ([09 §9](09-authorization-semantics.md#9-пакетная-оценка)) |
| V4 | `require axioma-studio/azguard-core: dev-main` — стабильный релиз моста невозможен | пакет `axiomasoft/azguard`, теги `1.0.0-beta.N` → `1.0.0` |
| V5 | Написание `azgard` расходится с продуктом `AzGuard` | — (имя пакета моста выбирает Vaulter) |
| V6 | Для совпадения morph-типов нужны три настройки (`vaulter.ids.default`, `corex.ids.strategy`, `AZ_GUARD_MORPH_TYPE`) | одна настройка `azguard.ids.host_keys` с теми же значениями, что у Vaulter ([D08](02-decisions.md#d08)) |
| V7 | Vendor пакетов Vaulter — `axioma-studio/*`, у AzGuard — `axiomasoft/*` | владелец выбрал единый vendor `axiomasoft` (Q23); переименование пакетов Vaulter — задача Vaulter |

Код Vaulter в этом аудите **не** менялся (границы задачи и `CLAUDE.md` Vaulter).

## 11. Порядок со стороны AzGuard

| # | Действие | Зачем интеграциям |
|---|---|---|
| I1 | ядро, правило выбора панели, источники и их фабрика, `decideMany`, `StateToken`, схема панели ([13](13-workstreams.md)) | есть то, на что опираться |
| I2 | `IntegrationContractTests`, `PluginContractTests`, пример интеграции в CI | пакеты проверяют себя на настоящем AzGuard |
| I3 | руководство «Интеграция вашего пакета» | правила §5 в одном месте |
| I4 | тег `1.0.0-beta.1` пакета `axiomasoft/azguard` | стабильная версия для `require` |
| I5 | текст ADR «Ecosystem conventions» (§8.1) — предложить репозиториям экосистемы | одинаковое устройство пакетов |
| I6 | передать идею моста (§9) и заметки (§10) в план Vaulter отдельной задачей | Vaulter решает сам |

## 12. Что сознательно не делаем

- **Мосты к чужим пакетам внутри AzGuard.** Каждый пакет сам поставляет свой плагин. AzGuard не знает о Vaulter.
- **Отдельный «реестр интеграций».** Интеграция — обычный плагин панели.
- **Общий Composer-пакет экосистемы** (`ActorRef`, конверт события, кодек). Он связал бы релизы продуктов ради
  нескольких классов. Одинаковость держат ADR и контрактный тест формы событий. Вернуться к вопросу, если пакетов
  станет много.
- **Одно слово для Panel и Profile, перенос ACL Vaulter в AzGuard или наоборот.** Понятия разные.


## 13. Тенантные пакеты и полные scopes

Пакет не обязан наследовать конкретную Organization хоста. Он реализует ContextDefinition/TenantDirectory/
TenantMembership/ResourceScopeResolver и связывает внешние `(provider,installation,external_id)` с TenantRef.
Mapping и credentials принадлежат пакету; [CRM §10](16-crm-and-workflows.md#10-несколько-внешних-систем-одного-тенанта).

Вызов: `panel($configuredId)->inTenant($tenant)->for($subject)->hasPermission($localOrEnum, on: $resource)`.
Prefix plugin применяется также к enum->local translation, Role.permissions и PolicyBinding; сохранённое
reference origin указывает owning plugin. Schema/catalog и проверки не должны видеть разные имена одного enum.
Один integration подключён к двум панелям — два definition/execution instance; actor/tenant не захватывается boot.

DB StateToken недостаточен для кэша final Allow, если policy/restriction/token/host relation могут меняться.
Событие best effort не служит единственным revoke channel. Mandatory external delivery — outbox интеграции.
Одновременная запись application data и grant возможна атомарно только на одном connection и root transaction.
Для SQL visibility на разных connections нужен bounded exact id adapter или отказ; N+1 удалённые checks
и некорректный total не считаются поддержанным exact listing.

Интеграция вправе принести configurable context definition, typed filters и directories и получает host model classes,
owner/membership/filter configuration через named typed plugin parameters. Runtime capability inputs — explicit user/BaseRole/
actor/grant/scope/phase, не plugin boot current user. Два потребителя пакета задают собственные модели без
форка интеграции; role/filter definitions code-owned, assignment fields validated по FieldSchema. Consumer acceptance — R45–R50 из [17](17-crm-acceptance-tests.md).
