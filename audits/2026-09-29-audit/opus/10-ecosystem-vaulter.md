# 10 — Экосистема: согласование с Vaulter и контракт моста

Решение: [D43](02-decisions.md#d43). Источник по Vaulter — его финальная проработка
`vaulter/audits/2026-09-29-stable-audit/opus/` (далее «Vaulter Dxx») и код моста `vaulter/packages/azgard` на
`a572121`. AzGuard и Vaulter — разные продукты, но их хост один: одинаковые понятия должны называться и вести себя
одинаково, а мост — быть тонким.

## 1. Единые конвенции (ADR «Ecosystem conventions», одинаковый текст в обоих репозиториях)

| Тема | Vaulter | AzGuard | Статус |
|---|---|---|---|
| Окно канона | 0.3.0 (Vaulter D01) | 0.4.0 (D01) | разные номера, одинаковая политика: жёсткие переименования, конфиг через нормализатор до 1.0 |
| Точка входа Composer | `axioma-studio/vaulter` (metapackage) | `axioma-studio/azguard` | согласовано |
| Версии пакетов внутри продукта | `self.version` | `self.version` | согласовано |
| Субъект операции записи | `Actor::user()/system($reason)`, `ActorRef{type,id,reason}`, `SYSTEM_TYPE='vaulter:system'` | `Actor::subject()/system()`, `ActorRef`, `SYSTEM_TYPE='azguard:system'` | согласовано (разные константы — видно, кто писал) |
| Handle с актором | `->actingAs($user)`, `->asSystem($reason)` | то же | согласовано |
| Соединение БД | `vaulter.database.connection`, `VaulterDatabase::transaction()` | `azguard.database.connection`, `AzGuardDatabase::mutate()` | согласовано; имя метода отличается осознанно: `mutate` всегда двигает ревизию |
| Префикс таблиц | `vaulter.database.table_prefix = 'v_'` | `azguard.database.table_prefix = 'azg_'` | согласовано |
| Ключи хоста | `vaulter.ids.host_keys` = string\|bigint\|uuid\|ulid | `azguard.ids.host_keys` — те же значения | согласовано (D08) |
| Конфиг | файл на пакет, readonly `*Config`, нормализатор, boot-валидация, запрет `config()` вне Configuration | то же | согласовано |
| События | `DomainEvent{eventId ULID, occurredAt, actor, correlationId, …}`, `EventType noun.verb_past`, after-commit | `AccessEvent{eventId, occurredAt, actor, correlationId, stateRevision}`, `EventType`, after-commit | согласовано |
| Durable-след | activity + outbox в транзакции | `azg_audit_log` в транзакции; outbox — T2 | разные потребности, одинаковая гарантия «в той же транзакции» |
| Исключения | `<Condition>Exception`, `problemCode()` snake_case, RFC 9457 для HTTP | `<Condition>Exception`, `code()` snake_case; HTTP-API нет | согласовано по кодам |
| Команды | `vaulter:<area>:<verb>`, `vaulter:make:*`, `vaulter:doctor --json` | `azguard:<area>:<verb>`, `azguard:make:*`, `azguard:doctor --json` | согласовано |
| Ключи реестров | профиль `^[a-z0-9][a-z0-9-]{0,63}$`, расширения `vendor/name` | realm — та же грамматика, расширения `vendor/name` | согласовано |
| Тестовый kit | `Vaulter\Testing\` + контрактные наборы | `AzGuard\Testing\` + контрактные наборы | согласовано |
| Словарь «панели» | Panel → **Profile** (коллизия с Filament panel) | Panel → **Realm** (та же причина) | согласовано по причине; разные слова, т. к. разные понятия |
| Субъект доступа | `Subject` (адресат grant'а), `OwnerRef` (владелец drive) | `SubjectRef`, `ContextRef` | кодек одинаковый: morph alias + строковый id (Vaulter D20 «id — строка без преобразований») |
| Tenancy | `TenantResolver::current()` (opt-in strict) | `ContextResolver` (ambient) + `ContextMembership` (граница) | разные роли: tenant изолирует данные, контекст выбирает назначения; хост может реализовать оба одним классом |

## 2. Что не так с мостом сейчас

1. **Не работает с зарегистрированными панелями** (N05, P05): неквалифицированные ключи (`documents.view`) и
   `panelId = null` → AzGuard оценивает по `'app'` и отбрасывает ключ фильтром каталога. Тест моста зелёный только
   без панелей.
2. **Ошибочный docblock** (`AzgardGuardAdapter.php:20-22`) обещает вывод панели из первого сегмента ключа.
3. Драйвер делает до 5 последовательных `hasPermissionIn()` на уровень доступа (`levelProbes()`), каждый — отдельная
   оценка с переключением контекста (C02-путь), без общего снимка.
4. `vaulter-azgard` требует `axioma-studio/azguard-core: dev-main` — стабильный релиз невозможен (Vaulter N21/D36).
5. Написание `azgard` расходится с продуктом `AzGuard` во всех символах моста.
6. Morph-выравнивание требует трёх ручек у хоста (`vaulter.ids.default`, `corex.ids.strategy`, `AZ_GUARD_MORPH_TYPE`).

## 3. Целевой контракт моста (`vaulter-azguard`)

```php
namespace Vaulter\AzGuard;

final class AzGuardPermissionDriver implements \Vaulter\Contracts\Access\PermissionDriver
{
    public function __construct(private Authorizer $authorizer, private AzGuardPermissionMap $map) {}

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        $context = ContextRef::of($scope->driveOwner->type, $scope->driveOwner->id);   // OwnerRef → ContextRef
        $requests = $this->map->levelRequests(AzGuard::for($actor)->ref(), $context);  // до 5 AccessRequest
        $decisions = $this->authorizer->decideMany($requests);                          // один снимок, одна выборка
        return $this->map->highestAllowedLevel($decisions);                             // null, если ничего
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return AzGuard::for($actor)->isSuperadmin($this->map->realm()) ? Effect::Allow : null;
    }
    // subjectsFor(), canCreateIn() — аналогично, через decide()
}
```

- `config/vaulter-azguard.php`: `realm` (обязателен), `permissions` — карта ability Vaulter → **квалифицированный**
  ключ AzGuard (`'view' => 'app.documents.view'`) или enum case. Boot: каждый ключ проверяется
  `AzGuard::catalog()->owns()` → иначе `InvalidConfigurationException` (не молчаливый отказ).
- Vaulter-doctor получает проверку `azguard.bridge`: realm существует, ключи в каталоге, realm принимает тип
  контекста владельца drive (`ContextPolicy::accepts`).
- Кэш решений Vaulter (листинг, D12 Vaulter) может включать `AzGuard::state()` в свой ключ — единый `StateToken`
  делает это корректным.
- AzGuard поставляет `AzGuard\Testing\Contracts\PermissionSourceContractTests`, а Vaulter —
  `PermissionDriverContractTests`; мост прогоняет второй набор против **реального** AzGuard с зарегистрированным realm
  (исправление N05 доказывается тестом моста, а не только AzGuard).
- Опционально (T2): AzGuard как **источник** для Vaulter-grants на роли (`EnumeratesRoleSubjects`): роли AzGuard в
  контексте drive-владельца как `Subject` Vaulter — только если появится потребность адресовать `v_node_grants` ролям.

## 4. Порядок релизов и действия по репозиториям

| # | Где | Действие | Блокирует |
|---|---|---|---|
| E1 | AzGuard | T0-патч 0.3.x (D07/D14/D16/D19/D22/D23/D31 T0-части) | — |
| E2 | Vaulter | исправить docblock и тест моста: регистрировать панель и квалифицированные ключи в тестах `tests/RealAzgard` (сейчас скрывают N05) | — |
| E3 | AzGuard | 0.4.0 canon + тег `v0.4.0` | E4 |
| E4 | Vaulter | `vaulter-azgard` → `vaulter-azguard`, `require axioma-studio/azguard:^0.4`, драйвер на `decideMany` (§3) | релиз `vaulter-azguard` (Vaulter D36) |
| E5 | оба | ADR «Ecosystem conventions» — одинаковый текст, ссылка из `CLAUDE.md`/`AGENTS.md` | — |
| E6 | оба | общий consumer-стенд: чистый Laravel + `vaulter` + `vaulter-azguard` + `azguard` из собранных архивов; сценарии V50–V52 | 1.0 обоих |

Изменения кода Vaulter в этом проходе **не** выполнялись (границы задачи и `CLAUDE.md` Vaulter); E2/E4/E5 —
рекомендации для плана Vaulter (`plans/2026.09.29-№1-VAULTER-STABLE`), их нужно внести туда отдельной задачей.

## 5. Что сознательно не делаем

- Общий Composer-пакет `axioma-studio/ecosystem-kernel` (ActorRef, EventEnvelope, IdentityCodec): связывает
  релизы двух продуктов ради ~5 классов; сравнение полей держит ADR + контрактный тест формы событий. T2 при
  третьем продукте.
- Единый словарь «Panel/Profile/Realm» одним словом: понятия разные (runtime-политика drives vs пространство прав).
- Перенос Vaulter ACL в AzGuard или наоборот: Vaulter-ACL — права на узлы дерева (ReBAC-подобно), AzGuard —
  RBAC в контекстах; мост складывает их, а не заменяет.
