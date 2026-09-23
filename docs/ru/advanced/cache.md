# Кеш

AzGuard кеширует эффективный набор прав пользователя. По умолчанию используется in-memory стор `array` (на время запроса); для кеширования между запросами выберите persistent-стор (Redis/Memcached).

## Конфигурация

```php
// config/az-guard.php
'cache' => [
    'store'           => 'array',
    'expiration_time' => 3600,
    'generation'      => 1,
],
```

`store => 'array'` отключает кросс-реквест кеш (только in-memory) — удобно для тестов.

## Абсолютный срок гранта

`PermissionSet::validUntil()` — необязательный абсолютный момент в UTC. Встроенные прямые и контекстные гранты ставят его равным ближайшему активному `expires_at`. На каждом попадании в request- и durable-кеш `now >= validUntil` считается промахом: набор пересчитывается один раз и уже истёкший результат не сохраняется. Durable-полезная нагрузка — `{version, keys, valid_until}`. TTL бэкенда — более ранний из `expiration_time` и этого дедлайна; `expiration_time => null` дедлайн не отменяет. Кастомный источник или слой без `validUntil()` по-прежнему живёт только по настроенному TTL.

## Сброс кеша

```bash
# Сдвигает DB revision и локальный request-кэш. Store не flush-ится.
php artisan guard:cache-reset

# Без подтверждения
php artisan guard:cache-reset --force
```

## Автоматический сброс

Кеш сбрасывается автоматически при изменении ролей и грантов — отдельный слушатель регистрировать не нужно. Это происходит при:

- `assignRole()` / `removeRole()` / `syncRoles()`
- `grant()` / `revoke()`

а также по соответствующим событиям `RoleAttached`, `RoleDetached`, `GrantGiven`, `GrantRevoked`.

## Кеш в Octane

AzGuard биндит свои per-request сервисы как `scoped` и сбрасывает их между запросами — он Octane-safe. In-memory часть кеша не переживает между запросами; persistent-стор (Redis) сохраняется.

```php
// Для Octane: persistent-стор с коротким TTL
'cache' => [
    'store'           => 'redis',
    'expiration_time' => 60, // 1 минута достаточно при Octane
    'key'             => 'azguard.permissions',
],
```
