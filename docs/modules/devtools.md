# DevTools

The DevTools module holds the generators and the installer. It registers its commands only when the application runs
in the console, so HTTP requests and queue workers load nothing from it.

## Commands

| Command | Creates |
|---|---|
| `azguard:install` | `config/azguard.php`, the connection and host key type, optionally the first panel (`--panel=Admin`) and the migrations (`--migrate`) |
| `azguard:stubs` | Publishes the generator stubs to `stubs/azguard` so you can edit them (`--force` overwrites) |
| `azguard:make:panel` | The directory and provider of a panel |
| `azguard:make:permission` | A permission enum, optionally its policy (`--policy`, `--authority=grants\|policy`, `--case=Name=local.key`) |
| `azguard:make:role` | A role with an explicit `#[Role('key')]` |
| `azguard:make:policy` | A policy bound to a permission enum |
| `azguard:make:source` | A source, in a panel (`--panel`) or shared (`--shared`) |
| `azguard:make:plugin` | A plugin skeleton |
| `azguard:make:restriction` | A restriction |
| `azguard:make:pipe` | A change pipe |
| `azguard:make:models` | The grant models of a panel and the migration of their extra columns |

Every generator takes `--force` to overwrite an existing file. All arguments and options are listed in
[Console commands](/reference/commands).

## Where files go

The generators write under the `scaffold` section of `config/azguard.php`; the published file does not list it, add it
to change the defaults:

```php
'scaffold' => [
    'namespace' => 'App\\Guards',
    'path' => 'app/Guards',
],
```

Only these two keys exist; any other key is an error that names the known keys. The section is validated whenever the
module is installed, in or outside the console.

## Stubs

`php artisan azguard:stubs` copies the code stubs to `stubs/azguard`. A published stub wins over the packaged one, so
edit the copy to match your conventions. After an upgrade, republish with `--force` to take new stubs.
