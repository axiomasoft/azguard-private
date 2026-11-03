# axiomasoft/azguard-filament

The Filament 5 plugin for [AzGuard](https://github.com/axiomasoft/azguard):

- resources, pages and widgets are authorized by AzGuard permissions;
- resource lists are filtered in SQL to the records the user may view;
- editors for role grants, permission grants and runtime permissions.

The 2.0 line requires Filament `^5.8.4` and the exact same core package version (`self.version`).
Supported pairs are PHP 8.3/8.4 with Laravel 12 and PHP 8.3/8.4/8.5 with Laravel 13, each resolved with
lowest and latest dependencies in CI. SQL visibility that cannot represent an authorization rule fails closed;
filtering a paginated collection afterwards does not implement authorization.

```bash
composer require axiomasoft/azguard-filament
```

```php
->plugin(AzGuardPlugin::make()->guardPanel('admin'))
```

Guide: <https://github.com/axiomasoft/azguard-private/blob/main/docs/guides/filament.md>. This is a read-only split of the
[monorepo](https://github.com/axiomasoft/azguard-private). Open issues and pull requests there. MIT license.

Existing installations: [upgrading to 2.0](https://github.com/axiomasoft/azguard-private/blob/main/docs/guides/upgrading-2.md).
