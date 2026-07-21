# Матрица инъекций: кто кого инжектит

Source: anonymized production Laravel project.

Constructor injection — единственный канал зависимостей доменного кода.
Конструктор = зависимости (коллабораторы), параметры метода = данные (модели, DTO, примитивы).

## Разрешённые направления

| Слой | Может инжектить | Не может инжектить | Примечание |
|:---|:---|:---|:---|
| **Controller** | Action, Mapper, ReadRepository, View-сервисы | Service записи напрямую (мимо Action) | Action — через method injection в экшен-метод (если нужен одному методу) или constructor (если нескольким) |
| **Action** | Service, Repository, другие Actions | Controller, Request | Композиция Actions = composite action, одна транзакция |
| **Service** | Repository, другие Service | Action, Controller, Request | Оркестрация и делегирование |
| **Repository** | Только узкие сервисы-фильтры (VisibilityService) | Service-оркестраторы, Action | Репозиторий — нижний слой, почти без зависимостей |
| **Policy** | Service (read-only evaluators) | Repository записи, Action | Policy только читает и отвечает bool |
| **FormRequest** | — (правила через rules()) | Доменные сервисы в конструктор | Сервис для правила — резолв в rules() допустим, это HTTP-граница |

**НИКТО не инжектит Controller или Request.** Request заканчивается на границе HTTP:
контроллер маппит его в DTO и передаёт данные параметрами метода.

## Что НЕ считается зависимостью (инжектить не нужно)

Инфраструктурные статики — не коллабораторы, их вызов из доменного кода допустим:

- `DB::transaction(...)` — границы атомарности;
- `Event::dispatch(...)` / `SomethingChanged::dispatch(...)` — публикация доменных событий;
- `Gate::allows(...)` — проверка прав в контроллере/представлении.

Граница простая: у коллаборатора есть своя логика и его хочется подменить/протестировать
отдельно — инжектим. Транспорт фреймворка — зовём статически.

## Антипаттерны

### 1. `app()` / `resolve()` / фасадный резолв в доменном коде

```php
// ПЛОХО: скрытая зависимость — не видна в сигнатуре, не подменяется в тесте
final readonly class StoreAction
{
    public function execute(StoreCommand $command): Document
    {
        $persistence = app(DocumentPersistenceService::class); // <-- service locator
        ...
    }
}

// ХОРОШО: зависимость объявлена в конструкторе
final readonly class StoreAction
{
    public function __construct(private DocumentPersistenceService $persistence) {}
}
```

### 2. Инъекция Request в Service/Action

```php
// ПЛОХО: доменный код привязан к HTTP, нетестируем без запроса
final readonly class StoreDocumentService
{
    public function __construct(private Request $request) {} // <-- запрещено
}

// ХОРОШО: контроллер маппит Request → DTO, глубже идут только данные
$action->execute(command: new StoreCommand(form: $form, user: $request->user()));
```

### 3. Циклическая зависимость

`ServiceA → ServiceB → ServiceA` — контейнер упадёт на резолве, но сама попытка
означает неверную границу: общая логика принадлежит третьему классу. Выдели
`ServiceC`, который инжектят оба.

### 4. God-конструктор: 6+ зависимостей

Конструктор на 6 и больше коллабораторов — сигнал, что класс делает слишком много.
Делить по сценариям: composite action на атомарные Actions, сервис — на оркестратор
и узкие сервисы.

### 5. Зависимость «для одного метода из десяти»

Если зависимость нужна одному методу из десяти — границы класса нарезаны неверно.
Либо метод переезжает в класс, где зависимость уместна, либо выделяется новый класс.
(В контроллере легальная альтернатива — method injection в конкретный экшен-метод.)

### 6. Интерфейс без второй реализации

`FooServiceInterface + FooService + bind()` ради единственной реализации — шум.
Конкретный класс резолвится контейнером zero-config. Интерфейс — только при
реальной вариативности (драйверы, внешние интеграции, подмена в тестах).
