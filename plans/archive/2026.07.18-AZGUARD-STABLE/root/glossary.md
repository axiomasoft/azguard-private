# Глоссарий — словарь терминов поверхности (P2.7, 2026-07-18)

> Статус: принят (research/03-p2-canon.md §9, findings/P0-axis-a-integration.md §C-A10/A-07/A-08).
> Судьба файла — `docs/05_AI/` при архивации плана (D26-паттерн: внутренний AI-guideline
> документ, parity-exempt). Источники: research/03-p2-canon.md §9 · findings/P0-axis-a-integration.md
> §C-A10 (таблица «термин→сущность→видимость») · plan.md §5 D14.

## Термин → сущность → видимость

| Термин | Сущность-носитель | Где виден потребителю | Вердикт |
|:--|:--|:--|:--|
| **panel** | VO `AzGuard\Panels\Panel` + `PanelProvider` | config `panels`/`default_panel`; `$panelId`-аргумент почти каждого метода; middleware `azguard.panel:`; первый сегмент ключа `app.documents.view` | Единица изоляции прав — центральный термин пакета, носитель реален |
| **guard** | Собственной сущности НЕТ — бренд-префикс (config `az-guard.php`, artisan `guard:*`, неймспейс `Guard/`) + контракт `ContextGuard` | имена команд, имя конфига, `docs/basic-usage/multiple-guards.md` (= Laravel auth guard) | `guard=бренд`. Коллизия с Laravel auth guard названа честно, не устраняется — она историческая (имя пакета). Привязки panel↔auth-guard в коде НЕТ и не вводится (A-07) |
| **context** | `AuthorizationContext(Manager)` (пакет `azguard/context`, opt-in) + core-контракт `ContextGuard` | `hasPermissionIn($type,$id,$perm)`; middleware `azguard.context`; `docs/advanced/context.md` | `context=runtime`. «Где я сейчас» (workspace/tenant) — состояние текущего запроса, не персистится |
| **scope** | `HasScopedRoles` + модель `ModelHasScope` + таблица `model_has_scopes` + `ScopeInterface` | `hasScopedPermission($perm,$entity)`; `docs/advanced/entity-scopes.md` | `scope=persist`. Роль на конкретной записи — хранится в БД, не зависит от текущего запроса |

## Вердикты (A-07, A-08)

- **guard=бренд, изоляция=panel (A-07).** У «guard» нет собственной сущности-носителя в
  пакете; это бренд-префикс поверх коллизии с Laravel auth guard. Единица изоляции прав —
  `panel`, не guard. `docs/basic-usage/multiple-guards.md` переформулирован через панели:
  ложная декларация «a panel is bound to one or more guards» убрана (носителя в коде нет —
  `Panel.php`: 0 вхождений `guard`); guard остаётся Laravel-концепцией аутентификации,
  panel — концепцией AzGuard-изоляции; на практике панель обычно привязывается к guard'у на
  уровне Filament `->authGuard()`, но это конфигурация потребителя, не контракт пакета.
- **context (runtime) vs scope (persist), НЕ сливать (A-08).** Оба механизма отвечают на
  похожий вопрос «может ли пользователь ЗДЕСЬ», но это разные механизмы by design: context —
  временное состояние запроса из opt-in пакета `azguard/context`; scope — персистентная роль
  на модели из core. Маршрутизирующий раздел «context или scope?» (`docs/advanced/context.md`)
  объясняет выбор, слияния словаря/кода нет и не планируется.

## Что НЕ делать (Scope Excluded P2.7)

- Не вводить привязку panel↔auth-guard в код — носителя нет, устранение расхождения чисто
  doc-уровня.
- Не сливать context и scope в один механизм — разные by design (runtime vs persist).
- Не переименовывать неймспейс `Guard/` — бренд остаётся.
