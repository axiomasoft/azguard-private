# ADR — Структурный канон core (P2.1, 2026-07-18)

> Статус: принят (D14/D15, план 2026.07.18-AZGUARD-STABLE). Судьба файла — docs
> проекта при архивации плана (D26-паттерн). Источники: research/03-p2-canon.md §1 ·
> findings/P0-axis-d-structure.md §C-D1/§C-D2 · plan.md §5 D15.

## Решение 1 — `AzGuard\Support` упразднён

Catch-all `Support/` (9 файлов, 6 несвязанных ролей — находка D-05) распущен по
доменным неймспейсам; корневые типы `PanelProvider`/`PermissionKey` переехали к своим
доменам. Корень core остаётся каноничным Laravel-пакетом: только `AzGuardManager` +
`AzGuardServiceProvider` (§C-D2).

| Было | Стало |
|:--|:--|
| `AzGuard\Support\Panel` | `AzGuard\Panels\Panel` |
| `AzGuard\Support\PanelResolver` | `AzGuard\Panels\PanelResolver` |
| `AzGuard\PanelProvider` (корень) | `AzGuard\Panels\PanelProvider` |
| `AzGuard\Support\PermissionName` | `AzGuard\Permissions\PermissionName` |
| `AzGuard\PermissionKey` (корень) | `AzGuard\Permissions\PermissionKey` |
| `AzGuard\Support\Config` | `AzGuard\Configuration\Config` |
| `AzGuard\Support\RequestState` | `AzGuard\Runtime\RequestState` |
| `AzGuard\Support\ScopedRoleCache` | `AzGuard\Runtime\ScopedRoleCache` |
| `AzGuard\Support\ResolvesGateAbilities` | `AzGuard\Abilities\ResolvesGateAbilities` |
| `AzGuard\Support\BladeHelper` | `AzGuard\Auth\BladeHelper` |
| `AzGuard\Support\Schema\MorphColumns` | `AzGuard\Database\Schema\MorphColumns` |

Мотив: граница «Support = всё остальное» не несёт смысла и размывает `@api`/`@internal`
по соседству (D-05); домен-приёмник у каждого файла существовал заранее (§C-D1).
`@api`/`@internal`-докблоки при переезде сохранены. SemVer: breaking FQCN-переезд,
легален пре-1.0 (бриф п.7, D14); миграционная заметка — предмет консолидации
P3.3 (docs/introduction/upgrading.md).

## Решение 2 — двух-домовый канон контрактов (НЕ сливать)

- `AzGuard\Contracts` — cross-cutting публичные контракты пакета (16 интерфейсов):
  границы, которые пересекают субдомены (user, manager, resolver, guard).
- `AzGuard\Registry\Contracts` — контракты registry-субдомена (grant-sources,
  catalog-builders, matcher): живут РЯДОМ со своим субдоменом (locality — глубже
  модуль, чем плоский общий `Contracts/`).

Два дома — сознательное решение (D15), не гигиенический недосмотр. Слияние запрещено:
плоский общий каталог обменивает locality субдомена на ложную «одноместность».
Инвариант «contracts are interfaces» закреплён на ОБА неймспейса в
`tests/ArchTest.php` (expectations `contracts are interfaces` и `registry contracts
are interfaces`; исключение — enum `GrantPriority`, задокументировано там же).
Расширение сделано P1 (D-04); P2.1 сверил покрытие.

## Инварианты для будущих правок

1. Новый класс кладётся в СВОЙ домен; каталога-свалки (`Support/`, `Helpers/`,
   `Utils/`) не заводить.
2. Новый контракт: cross-cutting → `AzGuard\Contracts`; субдоменный → `Contracts/`
   внутри субдомена (по образцу Registry).
3. Корень `AzGuard\` — только Manager + ServiceProvider.
