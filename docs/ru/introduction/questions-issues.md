# Вопросы и проблемы

## Сообщить об ошибке

Если вы обнаружили баг, создайте issue на GitHub:

→ [github.com/axioma-studio/azguard/issues](https://github.com/axioma-studio/azguard/issues)

Пожалуйста, включите:
- Версию AzGuard (`composer show axioma-studio/azguard-core | grep versions`)
- Версии PHP и Laravel
- Минимальный воспроизводящий пример
- Текст исключения и stack trace (если применимо)

## Задать вопрос

Для общих вопросов используйте [GitHub Discussions](https://github.com/axioma-studio/azguard/discussions).

## Частые проблемы

### `hasPermission()` всегда возвращает `false`

Проверьте:
1. Трейт `HasAzGuard` добавлен в модель User
2. Роль синхронизирована с БД: `php artisan guard:sync-roles`
3. Пользователю назначена роль: `$user->assignRole('app:editor')`
4. Кэш сброшен: `php artisan guard:cache-reset`

### Конфликт с существующей реализацией Gate

AzGuard регистрируется через `Gate::before()`. Если у вас уже есть `Gate::before()` в `AuthServiceProvider`, убедитесь что AzGuard не перезаписывает его. Используйте `Gate::after()` для вашей логики или настройте порядок в конфиге.

### Ошибка миграции: таблица уже существует

```bash
php artisan migrate:status
```

Если миграции AzGuard уже применены, не запускайте `--force` без необходимости.

### Проблемы с Laravel Octane

См. раздел [Требования → Octane](/ru/introduction/prerequisites#laravel-octane-kubernetes).
