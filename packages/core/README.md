# axiomasoft/azguard

Code-first authorization for Laravel 11–13. Permissions are backed enums and roles are PHP classes. Every
check goes through one decision pipeline that fails closed.

0.7.0 is the first public release. The API may change before 1.0.

```bash
composer require axiomasoft/azguard
php artisan azguard:install --panel=Admin --migrate
```

Documentation: <https://github.com/axiomasoft/azguard-private/tree/main/docs>. Filament 5 plugin:
[`axiomasoft/azguard-filament`](https://github.com/axiomasoft/azguard-filament).

This is a read-only split of the [monorepo](https://github.com/axiomasoft/azguard-private). Open issues
and pull requests there. MIT license.
