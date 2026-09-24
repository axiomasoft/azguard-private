# Список изменений

Все значимые изменения в AzGuard документируются здесь.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/).
Проект следует [Semantic Versioning](https://semver.org/lang/ru/).

---

## [Unreleased]

### Добавлено
- Поддержка UUID/ULID для первичных ключей
- Интеграция с PhpStorm через `.phpstorm.meta.php`
- Русскоязычная документация

---

## 0.x

### Добавлено
- Code-first RBAC: роли как PHP-классы, права как PHP enum
- Мультипанельная изоляция (`app.*`, `admin.*`, `api.*`)
- Прямые гранты с TTL
- Нативная интеграция с Laravel Gate (`Gate::before()`)
- Поддержка PHP 8 Attributes: `#[CheckPermission]`, `#[GateAbility]`, `#[SkipGuardCheck]`
- Artisan-команды: `guard:sync-roles`, `guard:doctor`, `guard:cache-reset`
- Контекстные проверки (tenant, team, project)
- Entity Scopes для ресурсных ограничений
- Интеграция с Filament v5
- Полное покрытие тестами (Pest)

→ [Полный список на GitHub](https://github.com/axioma-studio/azguard/releases)
