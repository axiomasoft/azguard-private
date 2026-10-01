# Contributing to AzGuard

## Git Workflow

1. Создайте ветку от `develop`:
   - Новая возможность: `feature/short-description`
   - Исправление: `fix/short-description`
   - Документация: `docs/short-description`

2. Напишите код и тесты (обязательно!).

3. Убедитесь, что все проверки проходят:

```bash
composer test        # Все тесты
composer lint        # Стиль кода
composer analyse     # Статический анализ
composer api:check   # Манифест публичного API; после изменения @api/@spi — composer api:manifest
```

4. Создайте Pull Request в ветку `develop`.

## Стандарты кода

- **PSR-12** + стиль Laravel (Pint с конфигом `pint.json`)
- `declare(strict_types=1)` в каждом PHP-файле
- Типизация всех параметров и возвращаемых значений
- PHPDoc только там, где тип нельзя выразить нативно

## Тесты

- Каждая новая возможность **обязана** иметь тесты
- Unit-тесты для изолированной логики — `tests/Unit/`
- Feature-тесты для интеграции с Laravel — `tests/Feature/`
- Минимальное покрытие: **80%**

## Conventional Commits

```
feat: добавить поддержку wildcard permissions
fix: исправить сброс кэша при detach роли
docs: обновить README раздел установки
test: добавить Unit тесты для Authorizer
refactor: убрать дублирование BaseRole
chore: обновить зависимости
```

## Версионирование

Проект следует [Semantic Versioning](https://semver.org/):
- `MAJOR` — несовместимые изменения API
- `MINOR` — новые возможности с обратной совместимостью
- `PATCH` — исправления багов

## Code Review

- Минимум 1 approve перед merge
- Все CI-проверки должны быть зелёными
- Конфликты разрешает автор PR
