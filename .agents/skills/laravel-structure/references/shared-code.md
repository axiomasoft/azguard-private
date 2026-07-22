# Вынос общего кода: лестница ступеней

Код выносится «вверх» только когда дублирование реально случилось (второй потребитель существует, а не предполагается). Каждая следующая ступень — больше абстракции и больше стоимость сопровождения; берём минимально достаточную.

```
1. Scope / метод модели          ← код нужен в двух местах одного домена
2. Concern-трейт                 ← одинаковая примесь у классов разных доменов
3. PHP-атрибут + resolver        ← декларативные метаданные вместо match-простыней
4. Support/<Tech>-хелпер         ← статичный механизм без состояния
5. Utils                         ← чистые функции без Laravel
6. DTO вместо массива            ← общая структура данных между слоями
7. Локальный пакет               ← код полезен за пределами проекта
```

## Ступень 1: scope / метод модели

Логика принадлежит одной сущности — остаётся в модели:

```php
// app/Models/Order/Order.php
public function scopeActive(Builder $query): Builder
{
    return $query->whereNot('status', OrderStatus::Closed);
}
```

Не выноси в трейт/хелпер то, что используют только запросы по `Order`.

## Ступень 2: Concern-трейт

Одинаковая **примесь метаданных или поведения** нужна классам разных доменов — трейт в `app/Concerns/<Tech>/`, где `<Tech>` — механизм, а не домен:

```
app/Concerns/Enums/HasLabelAttribute.php     ← метод getLabel() для любых enum
app/Concerns/Enums/HasColorAttribute.php
app/Concerns/Media/HasMediaPathAttribute.php ← путь хранения медиа для моделей
```

Границы:

- Concern — это примесь (accessor, метаданные, мелкое поведение), **НЕ бизнес-логика**. «Пошарить» правило согласования через трейт нельзя — это Service.
- Трейт не знает о доменах: внутри нет `Order`, `Document` и т.п.
- Группировка по механизму: `Concerns/Enums/`, `Concerns/Media/`, не `Concerns/Order/`.

## Ступень 3: PHP-атрибут + resolver

`match`-простыни по кейсам enum (label, color, стадия) заменяются декларативными атрибутами над кейсом + reflection-резолвером:

```
app/Attributes/Common/Label.php                 ← универсальный атрибут
app/Attributes/Order/Stage.php                  ← доменный атрибут
app/Support/Enums/EnumCaseAttributeResolver.php ← единый резолвер
app/Concerns/Enums/HasLabelAttribute.php        ← трейт-фасад над резолвером
```

Полный паттерн (атрибут, резолвер, трейт, доменные оси) — скилл `laravel-architecture/enum-attributes`.

## Ступень 4: Support/<Tech>-хелпер

Статичный механизм с reflection/регистрацией, нужный нескольким слоям — `app/Support/<Tech>/`:

```
app/Support/Enums/EnumCaseAttributeResolver.php
app/Support/Auth/PolicyAttributeRegistrar.php
```

**Когда Support, а когда Service:**

| | `Support/<Tech>/` | `Services/<Tech>/` или `Services/<Domain>/` |
|---|---|---|
| Состояние | Нет (статические методы) | Может быть |
| Зависимости | Нет — не инжектируется, не дёргает контейнер | Инжектируется через конструктор (DI) |
| Тестирование | Прямой вызов | Через контейнер/моки |
| Пример | Reflection-резолвер атрибутов | `Broadcast/ChannelManager`, `Order/WorkflowService` |

Если хелперу понадобилась зависимость (репозиторий, конфиг через DI, состояние) — это Service, переезд в `Services/`.

## Ступень 5: Utils

Чистые функции без Laravel-зависимостей (даты, строки, общие операции над enum):

```
app/Utils/DateHelper.php
app/Utils/StrHelper.php
app/Utils/EnumHelper.php
```

Правило чистоты: не трогает БД, контейнер, request, auth. Если трогает — это не Utils.

## Ступень 6: DTO вместо массивов

Общая структура данных между слоями (Action ↔ Controller ↔ фронтенд) — не ассоциативный массив, а типизированный DTO в `app/Dto/`:

- Command DTO для Action: `Dto/Actions/<Domain>/<Subprocess>/StoreCommand.php`
- View DTO для фронтенда: `Dto/<Domain>/View/ListItemView.php`
- UI-обвязка страницы: псевдодомен `Dto/Layout/View/SharedPageProps.php`

Бакеты Form/View/Command/Mapper и генерация TypeScript — `php/laravel` → `snippets/dto.md`.

## Ступень 7: локальный пакет (path-repository)

Финальная ступень: код стабилен, не знает о проекте и полезен за его пределами (enum-коллекции, generic-резолверы) — выносится в `packages/<name>/` и подключается через composer path-repository с symlink:

```json
"repositories": [
    { "type": "path", "url": "packages/enum-concern", "options": { "symlink": true } }
]
```

Шаблон подключения — `laravel-architecture/enum-attributes` → `snippets/composer-path-repo.json`; устройство пакета — скиллы `php/laravel-package-*`.

## Анти-паттерны выноса

- **Преждевременная абстракция**: трейт/хелпер «на будущее» при одном потребителе — держи код у потребителя.
- **Трейт как контейнер бизнес-логики**: правило домена в Concern — должно быть Service в `Services/<Domain>/`.
- **Доменное знание на технической оси**: `Support/`/`Utils/`/`Concerns/` с упоминанием конкретного домена — переезд в доменную папку.
- **Хелпер-свалка**: `Utils/Helper.php` с десятком несвязанных методов — дроби по осям (Date, Str, Enum).
