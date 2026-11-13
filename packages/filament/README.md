# axiomasoft/azguard-filament

The Filament 5 plugin for [AzGuard](https://github.com/axiomasoft/azguard):

- resources, pages and widgets are authorized by AzGuard permissions;
- resource lists are filtered in SQL to the records the user may view;
- editors for role grants, permission grants and runtime permissions.

The 2.0 line requires Filament `^5.8.4` and the exact same core package version (`self.version`).
Supported pairs are PHP 8.3, 8.4 and 8.5 with Laravel 13, each resolved with
lowest and latest dependencies in CI. SQL visibility that cannot represent an authorization rule fails closed;
filtering a paginated collection afterwards does not implement authorization.

This package is retained in the monorepo for development and is not in the current publication scope. The root Composer path
repository resolves it locally; do not use the package name as a public installation instruction.

```php
->plugin(AzGuardPlugin::make()->guardPanel('admin'))
```

Guide: <https://github.com/axiomasoft/azguard-private/blob/main/docs/guides/filament.md>.
Development happens in the [monorepo](https://github.com/axiomasoft/azguard-private). MIT license.

Existing installations: [upgrading to 2.0](https://github.com/axiomasoft/azguard-private/blob/main/docs/guides/upgrading-2.md).
