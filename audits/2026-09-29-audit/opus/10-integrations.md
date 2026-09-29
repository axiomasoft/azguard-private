# 10 — Интеграции: как другие пакеты работают с AzGuard

Решения: [D43](02-decisions.md#d43), [D50](02-decisions.md#d50), [D51](02-decisions.md#d51).

## 1. Простыми словами

AzGuard отвечает на один вопрос: «может ли этот субъект сделать это действие здесь». Другим пакетам экосистемы
(Vaulter с файлами и документами, в будущем — другие) нужен этот ответ. Также они могут принести в AzGuard
собственные права, роли и правила.

**Мост к конкретному пакету пишет сам этот пакет.** Vaulter знает свои папки, документы и уровни доступа; как
перевести их на язык AzGuard, решает Vaulter. Задача AzGuard — дать для этого **небольшой и стабильный набор
разъёмов** и не ломать его. Тогда любой пакет — Vaulter, биллинг, CMS, чат — встраивается одинаково и дорабатывается
под себя, не трогая ядро AzGuard.

```
        пакет-интеграция (Vaulter, другой пакет)
        ┌───────────────────────────────────────────┐
        │  свои сущности → ContextRef / права       │   ← это пишет пакет
        │  свой плагин для панели AzGuard           │
        └──────────────┬──────────────────┬─────────┘
             спрашивает│                  │ подключается к панели,
     decide/decideMany │                  │ которую выбрал хост
                       ▼                  ▼
        ┌───────────────────────────────────────────┐
        │  AzGuard: панели, пайплайны, хранилища     │   ← стабильный контракт (@api/@spi)
        └───────────────────────────────────────────┘
```

## 2. Три уровня интеграции

Можно начать с первого уровня и переходить к следующим, когда понадобится.

| Уровень | Что делает пакет | Чем пользуется из AzGuard | Пример |
|---|---|---|---|
| **A. Спрашивает** | Проверяет права перед своими действиями. Права и роли описывает хост | `decide`, `decideMany`, `check`, Gate, `StateToken` | пакет чата проверяет `admin.chat.moderate` |
| **B. Приносит права** | Поставляет плагин с каталогом прав (enum), ролями по умолчанию, правилами-ограничениями, doctor-проверками. Хост подключает плагин к нужной панели | `Plugin`, `PanelBuilder`, `RoleDefinition`, `Restriction`, `DoctorCheck`, `keyPrefix` | Vaulter приносит `documents.view/edit/share` и роль «редактор документов» |
| **C. Приносит данные и реакции** | Даёт свой источник прав (например, «владелец документа может всё с ним») или шаги пайплайна изменений; слушает события AzGuard | `GrantSource`, шаги `ValidatesChange`/`RecordsChange`/`NotifiesChange`, события `AccessEvent` | пакет биллинга выдаёт права по оплаченному тарифу через свой источник |

## 3. Что AzGuard гарантирует

Всё ниже помечено `@api` (вызывать) или `@spi` (реализовывать), попадает в `api-manifest.json` и защищено проверками
совместимости ([12](12-operations-and-release.md)).

| Потребность пакета | Разъём AzGuard | Где описан |
|---|---|---|
| Спросить «можно ли» | `AzGuard::panel($id)->decide()/decideMany()/explain()/check()`; `AzGuard::check()` — панель из ключа | [05 §1](05-php-api.md#1-фасад), [05 §5](05-php-api.md#5-panelauthorizer-и-глобальный-authorizer) |
| Понять, почему отказ | `Decision::reason()` (`NotGranted`, `ContextRequired`, `RestrictionDenied`, …) | [05 §2](05-php-api.md#2-значения-ядра-azguardkernel) |
| Знать, что права изменились | `PanelAuthorizer::state(): StateToken` для своих кэшей; события `AccessEvent` после commit | [08 §7](08-data-model-and-migration.md#7-каталог-событий), [09 §6](09-authorization-semantics.md#6-кэш-и-консистентность) |
| Встроиться в панель | `Plugin` + `AzGuard::configurePanel()`; пакет поставляет плагин, **хост выбирает панель** | [06 §1](06-extension-points.md#1-плагин), [06 §7](06-extension-points.md#7-модули-и-сторонние-пакеты-внутри-приложения) |
| Свои права и роли | enum с локальными ключами + `keyPrefix`; `PermissionCatalogBuilder`; `RoleDefinition` | [06 §6](06-extension-points.md#6-каталог-и-роли-из-кода) |
| Свой источник прав, своё ограничение | `GrantSource`, `Restriction` | [06 §2](06-extension-points.md#2-пайплайн-доступа--разъёмы-шагов) |
| Свои правила изменений | `ValidatesChange`, `InterceptsChange`, `RecordsChange`, `NotifiesChange` | [06 §3](06-extension-points.md#3-пайплайн-изменений--разъёмы-шагов) |
| Перевести свою сущность в контекст | `ContextRef::of(type, id)`, `ContextAware` на модели; тип контекста принимает панель | [05 §2](05-php-api.md#2-значения-ядра-azguardkernel), [09 §2](09-authorization-semantics.md#2-политика-контекстов-панели) |
| Выдать права от своего имени | `AzGuard::panel($id)->manage()->asSystem('vaulter: share')` | [05 §7](05-php-api.md#7-accessmanager--единственный-вход-для-изменений) |
| Проверить себя | `IntegrationContractTests`, `PluginContractTests` против настоящего AzGuard | [06 §9](06-extension-points.md#9-контрактные-наборы-azguardtestingcontracts) |
| Проверить конфигурацию у хоста | `DoctorCheck` в своём плагине → `azguard:doctor` | [06 §8](06-extension-points.md#8-doctor) |

Чего AzGuard **не** обещает: классы в `Internal\`, модели и таблицы хранилища как способ записи, внутренний порядок
шагов внутри одной стадии, формат кэша. Опора на них ломается без предупреждения.

## 4. Шаблон пакета-интеграции

Минимальный пакет уровня B+A. Имена условные.

```php
// 1. Права пакета — локальные ключи, без id панели
enum AcmePermission: string implements Permission
{
    case View = 'documents.view';
    case Edit = 'documents.edit';
}

// 2. Плагин: что пакет приносит в панель
final class AcmeAzGuardPlugin extends BasePlugin             // make() и keyPrefix() — из базы
{
    public function id(): string { return 'acme/azguard'; }

    public function register(PanelBuilder $panel): void
    {
        $panel->permissions(AcmePermission::class)          // с keyPrefix хоста → admin.acme.documents.view
            ->roles(AcmeEditorRole::class)                   // роль по умолчанию, хост может не подключать
            ->doctorChecks(AcmeContextTypeCheck::class);     // «панель принимает тип контекста acme_folder»
    }

    public function boot(Panel $panel): void
    {
        app(AcmeAzGuardPanels::class)->attach($panel->id()); // пакет запоминает, к каким панелям его подключили
    }
}

// 3. Хост решает, куда подключить
$panel->plugin(AcmeAzGuardPlugin::make()->keyPrefix('acme'));   // в AdminPanelProvider

// 4. Пакет спрашивает — через панель, к которой его подключили
$decision = AzGuard::panel($panels->primary())
    ->for($user)
    ->in(ContextRef::of('acme_folder', $folder->id))
    ->decide(AcmePermission::Edit);                          // enum → полный ключ по каталогу панели
```

Если плагин подключён к двум панелям, пакет обязан указать панель явно (`AzGuard::panel($id)`); иначе enum не
определит панель однозначно → `AmbiguousPanelException` ([D50](02-decisions.md#d50)). Молчаливого выбора нет.

## 5. Правила для пакетов-интеграций

Правила войдут в руководство «Интеграция вашего пакета» ([12](12-operations-and-release.md)).

| Делать | Не делать | Почему |
|---|---|---|
| Хранить у себя только локальные ключи, id панели брать из подключения плагина или конфига хоста | Зашивать `admin`/`app` в код пакета | хост сам решает, в какой панели живут права пакета |
| Спрашивать AzGuard каждый раз (с кэшем по `StateToken`) | Копировать права AzGuard в свои таблицы | копия отстаёт от отзыва права — дыра в безопасности |
| Для пачки проверок использовать `decideMany` | Делать N вызовов `check` в цикле | один снимок состояния, одна выборка из БД |
| Писать права только через `manage()->asSystem('<пакет>: <причина>')` | Писать в модели/таблицы AzGuard напрямую | обход проверки делегирования, аудита и версии состояния |
| Переводить свои сущности в `ContextRef` через morph alias | Использовать `:` в типе, составные id | кодек контекста инъективен только при этих правилах ([D07](02-decisions.md#d07)) |
| Реагировать на события `AccessEvent` | Опрашивать таблицы AzGuard | таблицы — не контракт |
| Считать любой `Deny` отказом и показывать `reason` в диагностике | Трактовать ошибку/исключение как разрешение | fail-closed |
| Указывать `axioma-studio/azguard: ^0.4\|^1.0` | Требовать `dev-main` | стабильный релиз пакета невозможен с `dev-main` |
| Прогонять `IntegrationContractTests` в своём CI | Мокать AzGuard целиком | мок скрывает ошибки вроде N05 |

## 6. Проверка интеграции

```php
use AzGuard\Testing\Contracts\IntegrationContractTests;

uses(IntegrationContractTests::class);

beforeEach(function () {
    $this->azguardPanel('admin', fn (PanelBuilder $p) => $p->plugin(AcmeAzGuardPlugin::make()->keyPrefix('acme')));
    $this->azguardPanel('site');                              // вторая панель — проверка независимости
});
```

Набор проверяет на **настоящем** AzGuard:

- решение одинаково через `decide`, `decideMany` и Gate;
- после выдачи и отзыва меняется `StateToken`, события приходят после commit;
- права пакета не утекают во вторую панель;
- плагин собирается на чистой панели и на двух панелях сразу;
- неизвестный или неквалифицированный ключ даёт явную ошибку конфигурации, а не молчаливый отказ.

## 7. Совместимость версий

| Период | Обещание AzGuard |
|---|---|
| 0.4 – 0.9 | `@api`/`@spi` меняются только в minor, с записью в `UPGRADE.md`; конфиг — через нормализатор с предупреждением |
| с 1.0 | ломающие изменения `@api`/`@spi` только в major; Roave BC Check в CI |
| всегда | новые возможности SPI добавляются **новыми** необязательными интерфейсами (`BatchRestriction` рядом с `Restriction`), а не новыми методами в существующих |
| всегда | в CI AzGuard есть «пример интеграции» (`fixtures/example-integration`): плагин + вызовы `decideMany` + `IntegrationContractTests`. Изменение, ломающее контракт, падает в AzGuard раньше, чем у пакетов |

## 8. Общее с экосистемой и своё

Пакеты экосистемы должны быть **устроены** одинаково: разработчик, знающий Vaulter, быстро разбирается в AzGuard. При
этом **предметные слова у каждого свои** ([D43](02-decisions.md#d43)): авторизация и хранение файлов — разные
предметы.

### 8.1 Общие инженерные правила (ADR «Ecosystem conventions», один текст в репозиториях экосистемы)

| Тема | Vaulter | AzGuard |
|---|---|---|
| Установка | `axioma-studio/vaulter` | `axioma-studio/azguard` |
| Версии пакетов внутри продукта | `self.version` | `self.version` |
| Конфиг | файл на пакет, readonly `*Config`, нормализатор старых ключей, проверки при boot, `config()` только в `Configuration\` | то же |
| Ключи хоста | `vaulter.ids.host_keys` = string\|bigint\|uuid\|ulid | `azguard.ids.host_keys`, те же значения |
| Соединение и префикс таблиц | `vaulter.database.connection`, `table_prefix = 'v_'` | `azguard.storages.<имя>.connection`, `table_prefix = 'azg_'` (хранилищ может быть несколько) |
| «От чьего имени» | `->actingAs($user)`, `->asSystem($reason)`, `ActorRef`, `SYSTEM_TYPE='vaulter:system'` | то же, `SYSTEM_TYPE='azguard:system'` |
| Конверт события | `eventId` (ULID), `occurredAt`, `actor`, `correlationId`; `EventType` `noun.verb_past`; после commit | то же + `state` (`StateToken`) |
| Ошибки | `<Condition>Exception`, код `snake_case` | то же |
| Команды | `vaulter:<area>:<verb>`, `vaulter:make:*`, `vaulter:doctor --json` | `azguard:<area>:<verb>`, `azguard:make:*`, `azguard:doctor --json` |
| Ключи расширений | `vendor/name` | `vendor/name` |
| Тесты для потребителей | `Vaulter\Testing\` + контрактные наборы | `AzGuard\Testing\` + контрактные наборы |
| Ссылки на сущности хоста | morph alias + id строкой | morph alias + id строкой (`SubjectRef`, `ContextRef`) |

### 8.2 Свои предметные слова

| AzGuard | Что это | Похожее в Vaulter | Почему не одно слово |
|---|---|---|---|
| **Panel** | независимое пространство прав: свои субъекты, права, роли, настройки, хранилище, плагины | **Profile** — набор политик для drives | панель описывает *кто и что может*, профиль — *как ведёт себя хранилище* |
| **Context** | «где» действует назначение (магазин, проект, команда) | **Owner** drive, tenant | контекст выбирает назначения, владелец/tenant изолирует данные; хост может реализовать оба одним классом |
| **DirectGrant** | право, выданное субъекту напрямую | **NodeGrant** — доступ к узлу дерева | у AzGuard право на действие в контексте, у Vaulter — уровень доступа к конкретному файлу/папке |
| **Restriction** | правило, которое может только запретить | политики профиля | разный масштаб и место исполнения |
| **Role**, **RoleAssignment** | набор прав и его назначение | — | в Vaulter ролей нет, доступ задаётся уровнями |

Совпадение слов не подгоняется. Если понятие в двух пакетах действительно одно (актор, событие, ключ хоста), оно
одинаково называется и кодируется. Если понятия похожи, но разные, у них разные имена.

## 9. Пример: как это может выглядеть у Vaulter (решает Vaulter)

Раздел — **иллюстрация** того, что контракта AzGuard достаточно. Устройство моста выбирает Vaulter.

```php
// в пакете Vaulter (vaulter-azguard) — эскиз
final class AzGuardPermissionDriver implements PermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        $panel = AzGuard::panel($this->panels->for($scope));                     // панель — из подключения плагина
        $context = ContextRef::of($scope->driveOwner->type, $scope->driveOwner->id); // владелец drive → контекст
        $requests = $this->levels->requests($panel->for($actor)->ref(), $context);   // до 5 уровней доступа
        return $this->levels->highestAllowed($panel->decideMany($requests));         // один снимок, одна выборка
    }
}
```

- Права Vaulter — enum с локальными ключами (`documents.view`) в плагине `vaulter/azguard`; хост подключает плагин к
  своей панели: `->plugin(VaulterAzGuardPlugin::make()->keyPrefix('documents'))`.
- Кэш листинга Vaulter может включать `$panel->state()` в свой ключ.
- Doctor-проверка плагина: панель принимает тип контекста владельца drive, ключи есть в каталоге.

## 10. Заметки для Vaulter по текущему мосту

Найдено при аудите (`vaulter/packages/azgard` на `a572121`). Это **заметки**, а не решения: что с ними делать,
решает Vaulter. Со стороны AzGuard исправляется только то, что относится к AzGuard (последний столбец).

| # | Наблюдение | Что делает AzGuard |
|---|---|---|
| V1 | Мост передаёт неквалифицированный ключ `documents.view` и `panelId: null`. AzGuard оценивает его в панели `app`, каталог молча отбрасывает ключ, и в хосте с панелями доступ всегда запрещён (N05, P05). Тест моста зелёный, потому что в нём нет панелей | панель берётся из ключа ([D05](02-decisions.md#d05)); неизвестный/неквалифицированный ключ → явная ошибка, а не молчаливый отказ; `IntegrationContractTests` регистрирует панели |
| V2 | Docblock `AzgardGuardAdapter.php:20-22` обещает вывод панели из первого сегмента ключа — это верно только для одного метода AzGuard | правило выбора панели одно для всех методов ([D05](02-decisions.md#d05)) |
| V3 | До 5 последовательных `hasPermissionIn()` на уровень доступа, каждый с переключением контекста | `decideMany` с общим снимком ([09 §7](09-authorization-semantics.md#7-пакетная-оценка)) |
| V4 | `require axioma-studio/azguard-core: dev-main` — стабильный релиз моста невозможен | тег `v0.4.0` и пакет `axioma-studio/azguard` ([13](13-workstreams.md)) |
| V5 | Написание `azgard` расходится с продуктом `AzGuard` | — (имя пакета моста выбирает Vaulter) |
| V6 | Для совпадения morph-типов хосту нужны три настройки (`vaulter.ids.default`, `corex.ids.strategy`, `AZ_GUARD_MORPH_TYPE`) | одна настройка `azguard.ids.host_keys` с теми же значениями, что у Vaulter ([D08](02-decisions.md#d08)) |

Изменения в коде Vaulter в этом аудите **не** выполнялись (границы задачи и `CLAUDE.md` Vaulter).

## 11. Порядок со стороны AzGuard

| # | Действие | Зачем интеграциям |
|---|---|---|
| I1 | T0-патч 0.3.x ([13](13-workstreams.md)) | закрывает опасные дефекты до переделки |
| I2 | 0.4.0: панель из ключа, плагины, пайплайны, `decideMany`, `StateToken`; тег `v0.4.0` | есть стабильная версия для `require` |
| I3 | `IntegrationContractTests`, `PluginContractTests`, пример интеграции в CI | пакеты проверяют себя на настоящем AzGuard |
| I4 | руководство «Интеграция вашего пакета» | правила §5 в одном месте |
| I5 | текст ADR «Ecosystem conventions» (§8.1) — предложить репозиториям экосистемы | одинаковое устройство пакетов |
| I6 | передать заметки §10 в план Vaulter отдельной задачей | Vaulter решает сам |

## 12. Что сознательно не делаем

- **Мосты к чужим пакетам внутри AzGuard.** Каждый пакет сам поставляет свой плагин. AzGuard не знает о Vaulter.
- **Отдельный «реестр интеграций».** Интеграция — это обычный плагин панели, особой сущности не нужно.
- **Общий Composer-пакет экосистемы** (`ActorRef`, конверт события, кодек). Он связал бы релизы продуктов ради
  нескольких классов. Одинаковость держат ADR и контрактный тест формы событий. Вернуться к вопросу, если пакетов
  станет много.
- **Одно слово для Panel и Profile, перенос ACL Vaulter в AzGuard или наоборот.** Понятия разные. У Vaulter права на
  узлы дерева, у AzGuard — роли и права в контекстах. Мост складывает их, а не заменяет одно другим.
