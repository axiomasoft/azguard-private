# Installation

For 2.0, use PHP 8.3/8.4 with Laravel 12 or PHP 8.3/8.4/8.5 with Laravel 13. Laravel 11 is unsupported.
The [compatibility policy](../reference/compatibility.md#supported-platforms) records the five CI pairs and server lanes.
For an existing installation, follow [upgrading to 2.0](../guides/upgrading-2.md) before running migrations.

```bash
composer require axiomasoft/azguard
php artisan azguard:install --migrate --panel=Admin
```

`azguard:install` does the following:

1. Publishes `config/azguard.php`.
2. Asks for the database connection of the AzGuard tables and the key type of your models. Options:
   - `--connection=` sets the connection;
   - `--host-keys=string|bigint|uuid|ulid` sets the key type.

   The default Laravel `users.id` is `bigint`.
3. With `--panel=Admin`, creates the first panel at `app/Guards/Admin/AdminGuardPanelProvider.php` and registers
   it in `azguard.panels.providers`.
4. With `--migrate`, runs the migrations. The tables use the prefix `azg_`.
5. Runs `azguard:doctor`.

The command is idempotent. An existing config is kept unless you pass `--force`.

## 1. Register a morph alias for every subject model

AzGuard stores subjects as `type:id`. The type must be a stable alias, not a class name, so that renaming a
class never orphans its grants. Add the alias in a service provider:

```php
// app/Providers/AppServiceProvider.php
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

public function boot(): void
{
    Relation::enforceMorphMap(['user' => User::class]);
}
```

Without the alias, Doctor's `morph.identity` check warns in production when a model uses its class name;
`azguard_doctor.strict_morph_identity=true` promotes this finding to an error. An enforced-map violation,
invalid alias or collision is an error. Doctor does not enable global morph-map enforcement or migrate stored rows.
An identity check rejects class names that do not satisfy the alias grammar. Every model you pass as a scope or
tenant needs an alias too, for example `Team` or `Organization`. Migrate existing stored identities before renaming
an alias; see the [upgrade guide](../guides/upgrading-2.md#morph-identities).

## 2. Make the model a subject

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;

class User extends Authenticatable implements AzGuardSubject
{
    use HasAzGuard;
}
```

## 3. Check the installation

```bash
php artisan azguard:doctor
```

The command exits with code 1 when it finds errors, so it can run in CI and deployment scripts.

## Optional: a dedicated database connection

By default, grants live on the default connection. A check that runs **inside an application transaction on
that connection** is denied (see [Decisions → transactions](/concepts/decisions#checks-inside-database-transactions)).
If your code authorizes inside `DB::transaction()`, give AzGuard its own connection to the same database:

```php
// config/database.php
'connections' => [
    // ...
    'azguard' => [/* the same database as your default connection */],
],
```

```dotenv
AZGUARD_DB_CONNECTION=azguard
```

## Optional: Filament

Use Filament `^5.8.4`. The plugin requires the exact same AzGuard core version through Composer `self.version`;
update both AzGuard packages together, including prerelease versions.

```bash
composer require axiomasoft/azguard-filament
```

See the [Filament guide](/guides/filament).

Next: [Quick start](/getting-started/quick-start).
