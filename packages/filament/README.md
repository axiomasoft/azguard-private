# axiomasoft/azguard-filament

The Filament 5 plugin for [AzGuard](https://github.com/axiomasoft/azguard):

- resources, pages and widgets are authorized by AzGuard permissions;
- resource lists are filtered in SQL to the records the user may view;
- editors for role grants, permission grants and runtime permissions.

```bash
composer require axiomasoft/azguard-filament
```

```php
->plugin(AzGuardPlugin::make()->guardPanel('admin'))
```

Guide: <https://github.com/axiomasoft/azguard-private/blob/main/docs/guides/filament.md>. This is a read-only split of the
[monorepo](https://github.com/axiomasoft/azguard-private). Open issues and pull requests there. MIT license.
