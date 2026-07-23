# Laravel Domain Structure

## Принцип

**Сначала домен — потом слой.** Бизнес-код организуется по бизнес-сущности (домену), а не по техническому типу. Слой задаёт корневую папку (`Models/`, `Policies/`, `Actions/`), домен — первую подпапку внутри неё.

```
✅ app/Models/Order/Order.php
✅ app/Policies/Order/CommonPolicy.php
✅ app/Actions/Order/Common/StoreAction.php

❌ app/Models/Order.php          — плоский список без домена
❌ app/Policies/OrderPolicy.php  — доменная логика без папки
```

Домен — это устойчивая бизнес-сущность с собственным жизненным циклом (Document, Order, User, Notification), а не экран, не контроллер и не таблица БД.

## Каноническая структура `app/` (доменная ось)

```
app/
├── Actions/<Domain>/<Subprocess>/   ← use-case точки входа
├── Dto/
│   ├── <Domain>/                    ← Form, View, Abilities, Mapper, Repository
│   └── Actions/<Domain>/            ← Command DTO для Actions (правило одного потребителя)
├── Enums/<Domain>/                  ← статусы, роли, коды событий, права
├── Events/<Domain>/                 ← доменные события
├── Exceptions/<Domain>/             ← доменные исключения
├── Filament/Resources/<Domain>/     ← админ-ресурсы
├── Http/
│   ├── Controllers/<Domain>/
│   ├── Requests/<Domain>/           ← Form Requests
│   └── Resources/<Domain>/          ← API Resources
├── Jobs/<Domain>/
├── Listeners/<Domain>/
├── Models/<Domain>/
├── Notifications/<Domain>/
├── Observers/<Domain>/
├── Policies/<Domain>/
├── Repositories/<Domain>/           ← *ReadRepository (read) + *StoreRepository (write)
└── Services/<Domain>/
```

Техническая ось (`Concerns/`, `Support/`, `Utils/`, `Attributes/Common/`, `Services/<Tech>/`) описана в `app-structure.md`.

## Правило зеркала

Если сущность в `app/Models/Order/Order.php` — все её соседи живут в `.../Order/`:

| Слой | Путь |
|:---|:---|
| Модель | `app/Models/Order/Order.php` |
| Enum | `app/Enums/Order/OrderStatus.php` |
| Controller | `app/Http/Controllers/Order/OrdersController.php` |
| Policy | `app/Policies/Order/CommonPolicy.php` |
| Repository (read) | `app/Repositories/Order/OrderReadRepository.php` |
| Repository (write) | `app/Repositories/Order/OrderStoreRepository.php` |
| Service | `app/Services/Order/WorkflowService.php` |
| Observer | `app/Observers/Order/Observer.php` |
| Exception | `app/Exceptions/Order/OrderAccessException.php` |
| DTO (форма) | `app/Dto/Order/Form/Form.php` |
| Action | `app/Actions/Order/Common/StoreAction.php` |

Полная таблица зеркала (включая `database/` и `tests/`) — в `mirroring.md`.

## Без уровня потребителя

Подпапки внутри слоя называются по домену, **не по потребителю**:

```
✅ app/Enums/Order/OrderStatus.php
❌ app/Enums/Models/Order/OrderStatus.php   — «для моделей» лжёт: enum читают
                                              политики, DTO, Filament, фронтенд
```

У enum, события, исключения много потребителей, и их список меняется; домен стабилен. Единственное узаконенное исключение — **правило одного потребителя**: `Dto/Actions/<Domain>/` зеркалит `Actions/<Domain>/`, потому что Command DTO имеет ровно одного потребителя — свой Action. По той же логике `Dto/<Domain>/Mapper/` живёт рядом с View DTO, которые он собирает.

## Sub-process разделение в Actions и DTO

Для сложных доменов Actions делятся на бизнес-процессы, и эта разбивка **зеркалится** в Dto/Actions, Enums/Permissions и Policies:

```
app/Actions/Order/
├── Common/        ← сохранение формы, взять в работу, общие переходы
├── Review/        ← согласование, утверждение, возврат на доработку
└── Application/   ← обработка заявки внешним исполнителем

app/Dto/Actions/Order/{Common,Review,Application}/
app/Enums/Order/Permissions/{CommonPermission,ReviewPermission,ApplicationPermission}.php
app/Policies/Order/{CommonPolicy,ReviewPolicy,ApplicationPolicy}.php
```

## Рост домена

Когда домен пухнет — смысловые подпапки **внутри** домена, не новые корневые оси:

```
app/Enums/Order/Workflow/      ← стадии процессов (CommonStage, ReviewStage)
app/Enums/Order/Permissions/   ← права по подпроцессам
app/Services/Order/Access/     ← доступ: evaluator, visibility, rules
app/Services/Order/Store/      ← персистентность: sync, persistence
app/Repositories/Order/Media/  ← вложения и файлы домена
```

## Именование классов

| Тип | Шаблон | Пример |
|:---|:---|:---|
| Action | `VerbNounAction` | `StoreAction`, `RegisteredAction` |
| Command DTO | `VerbNounCommand` | `StoreCommand`, `WrittenReplyCommand` |
| Policy | `<Subprocess>Policy`, методы `can<Action>` | `CommonPolicy::canEdit` |
| View DTO | суффикс `View` | `ListItemView`, `DetailExtraView` |
| Form DTO | `Form` (+ вложенные части) | `Form`, `Items` |
| Repository (read) | `*ReadRepository` | `OrderReadRepository` |
| Repository (write) | `*StoreRepository` | `OrderStoreRepository` |
| Service | `*Service`, `*Evaluator`, `*Allocator` | `WorkflowService`, `AccessEvaluator` |
| Exception | `*Exception` | `OrderAccessException` |

## Запреты

- **Нельзя** добавлять логику домена вне `*/<Domain>/*`, если это не инфраструктурный слой.
- **Нельзя** использовать папку `Common/` для логики, принадлежащей конкретному домену.
- **Нельзя** создавать класс без проверки: нет ли уже аналогичного в домене.
- **Нельзя** держать пустые доменные папки «на вырост» — папка появляется вместе с первым классом.

## Правило навигации

> Если сущность в `app/Models/<Domain>/`, её Policy, Service, Repository, Controller, DTO, Exception — все в `.../<Domain>/`.

## PR-чеклист

- [ ] Новый файл лежит в доменной папке своего слоя
- [ ] Соседние слои синхронизированы (Model / Policy / Service / DTO / тесты)
- [ ] Подпапка названа по домену или подпроцессу, не по потребителю
- [ ] Нет старых импортов из legacy-путей после rename
