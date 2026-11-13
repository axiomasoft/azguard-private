# axiomasoft/azguard

Code-first authorization for Laravel 13. Permissions are backed enums and roles are PHP classes. Every
check goes through one decision pipeline that fails closed.

For 2.0, supported pairs are PHP 8.3, 8.4 and 8.5 with Laravel 13.
CI tests lowest and latest dependencies for each pair on SQLite; server lanes use MySQL 9.7,
PostgreSQL 18 and Redis 7. The optional Filament plugin requires Filament `^5.8.4` and the exact same core version.

```bash
composer require axiomasoft/azguard
php artisan azguard:install --panel=Admin --migrate
```

Documentation: <https://github.com/axiomasoft/azguard-private/tree/main/docs>. Filament 5 plugin:
`axiomasoft/azguard-filament`, retained in the monorepo for development and outside the current publication scope.
Before updating an existing installation, read the
[2.0 upgrade guide](https://github.com/axiomasoft/azguard-private/blob/main/docs/guides/upgrading-2.md) and
[compatibility policy](https://github.com/axiomasoft/azguard-private/blob/main/docs/reference/compatibility.md).

This is a read-only split of the [monorepo](https://github.com/axiomasoft/azguard-private). Open issues
and pull requests there. MIT license.
