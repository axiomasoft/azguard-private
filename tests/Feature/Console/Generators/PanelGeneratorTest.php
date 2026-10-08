<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Illuminate\Support\Facades\Artisan;

const GENERATED_USER = 'AzGuard\Tests\Fixtures\Panels\User';

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
});
afterEach(fn () => $this->generated->release());

/** @return list<string> the providers an included configuration file lists */
function listedProviders(GeneratedApp $generated): array
{
    return (require $generated->path('config/azguard.php'))['panels']['providers'];
}

it('creates the provider of a panel in the scaffold path', function (): void {
    expect(Artisan::call('azguard:make:panel', ['panel' => 'SalesDesk', '--model' => GENERATED_USER]))->toBe(0);
    $provider = $this->generated->read('app/Guards/SalesDesk/SalesDeskGuardPanelProvider.php');

    expect($this->generated->files())->toBe(['app/Guards/SalesDesk/SalesDeskGuardPanelProvider.php'])
        ->and($provider)->toContain(
            'namespace '.$this->generated->namespace.'\Guards\SalesDesk;',
            "return 'sales-desk';",
            '->for(User::class)',
            '->permissions([DatabaseSource::make()])',
        );
});

it('takes the subject model from the users provider of the application', function (): void {
    config()->set('auth.providers.users.model', GENERATED_USER);
    Artisan::call('azguard:make:panel', ['panel' => 'Admin']);

    expect($this->generated->read('app/Guards/Admin/AdminGuardPanelProvider.php'))->toContain('use AzGuard\Tests\Fixtures\Panels\User;');
});

it('puts the panel in the path and namespace of the scaffold configuration', function (): void {
    config()->set('azguard.scaffold', ['namespace' => 'Acme\Access', 'path' => 'src/Access']);
    app()->forgetInstance(AzGuardConfig::class);
    Artisan::call('azguard:make:panel', ['panel' => 'Admin', '--model' => GENERATED_USER]);

    expect($this->generated->read('src/Access/Admin/AdminGuardPanelProvider.php'))->toContain('namespace Acme\Access\Admin;');
});

it('lists the provider in the published configuration once', function (): void {
    $this->generated->publishConfig();
    $class = $this->generated->class('Orders\OrdersGuardPanelProvider');
    $before = $this->generated->read('config/azguard.php');

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(0)
        ->and(listedProviders($this->generated))->toBe([$class])
        ->and(Artisan::output())->toContain('Listed '.$class);

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER, '--force' => true]))->toBe(0)
        ->and(listedProviders($this->generated))->toBe([$class])
        ->and(Artisan::output())->toContain('already listed');

    Artisan::call('azguard:make:panel', ['panel' => 'Billing', '--model' => GENERATED_USER]);

    expect(listedProviders($this->generated))->toBe([$this->generated->class('Billing\BillingGuardPanelProvider'), $class])
        // Nothing but the added lines changes in the file.
        ->and(array_values(array_diff(explode("\n", $this->generated->read('config/azguard.php')), explode("\n", $before))))
        ->toBe(['            '.$this->generated->class('Billing\BillingGuardPanelProvider').'::class,', '            '.$class.'::class,']);
});

it('lists the provider in an empty providers list and one with entries', function (string $providers, string $indent): void {
    mkdir($this->generated->path('config'), 0o755, true);
    file_put_contents($this->generated->path('config/azguard.php'), "<?php\n\nreturn [\n{$indent}'panels' => [\n{$indent}    'providers' => {$providers},\n{$indent}],\n];\n");
    $class = $this->generated->class('Orders\OrdersGuardPanelProvider');

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(0)
        ->and(listedProviders($this->generated))->toBe(str_contains($providers, 'Existing') ? [$class, 'Existing\Provider'] : [$class]);
})->with([
    'empty list' => ['[]', '    '],
    'empty list at the left edge' => ['[]', ''],
    'a list with an entry' => ["[\n        'Existing\\Provider',\n    ]", '    '],
]);

it('does not create the configuration and tells how to list the provider', function (): void {
    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(0)
        ->and($this->generated->has('config/azguard.php'))->toBeFalse()
        ->and(Artisan::output())->toContain('config/azguard.php is not published', 'azguard.panels.providers');
});

it('leaves a configuration without a providers list alone and says so', function (): void {
    mkdir($this->generated->path('config'), 0o755, true);
    file_put_contents($this->generated->path('config/azguard.php'), "<?php\n\nreturn ['defaults' => []];\n");

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(0)
        ->and($this->generated->read('config/azguard.php'))->toBe("<?php\n\nreturn ['defaults' => []];\n")
        ->and(Artisan::output())->toContain('has no azguard.panels.providers list');
});

it('never registers a panel provider in another configuration section', function (): void {
    mkdir($this->generated->path('config'), 0o755, true);
    $config = "<?php\nreturn ['panels' => [], 'custom' => ['providers' => []]];\n";
    file_put_contents($this->generated->path('config/azguard.php'), $config);

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(0)
        ->and($this->generated->read('config/azguard.php'))->toBe($config)
        ->and(Artisan::output())->toContain('has no azguard.panels.providers list');
});

it('ignores commented provider names and commented configuration examples', function (): void {
    mkdir($this->generated->path('config'), 0o755, true);
    $class = $this->generated->class('Orders\\OrdersGuardPanelProvider');
    $comment = "// Example: 'panels' => ['providers' => [{$class}::class]];\n";
    file_put_contents($this->generated->path('config/azguard.php'), "<?php\n{$comment}return ['panels' => ['providers' => []]];\n");

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(0)
        ->and(listedProviders($this->generated))->toBe([$class])
        ->and($this->generated->read('config/azguard.php'))->toContain($comment);
});

it('does not overwrite the provider without --force', function (): void {
    Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]);
    file_put_contents($this->generated->path('app/Guards/Orders/OrdersGuardPanelProvider.php'), 'owner code');

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER]))->toBe(1)
        ->and(Artisan::output())->toContain('already exists', 'Pass --force')
        ->and($this->generated->read('app/Guards/Orders/OrdersGuardPanelProvider.php'))->toBe('owner code');

    expect(Artisan::call('azguard:make:panel', ['panel' => 'Orders', '--model' => GENERATED_USER, '--force' => true]))->toBe(0)
        ->and($this->generated->read('app/Guards/Orders/OrdersGuardPanelProvider.php'))->toContain('final class OrdersGuardPanelProvider');
});

it('refuses a name that is not a panel with exit code 2 and writes nothing', function (array $arguments, string $message): void {
    expect(Artisan::call('azguard:make:panel', $arguments))->toBe(2)
        ->and(Artisan::output())->toContain($message)
        ->and($this->generated->files())->toBe([]);
})->with([
    'a path' => [['panel' => '../Evil'], 'is not a panel name'],
    'a nested path' => [['panel' => 'A/B'], 'is not a panel name'],
    'a leading digit' => [['panel' => '1Admin'], 'is not a panel name'],
    'the shared folder' => [['panel' => 'Shared'], 'is not a panel'],
    'a model that is not a class name' => [['panel' => 'Admin', '--model' => 'App\Models\User; exit'], '--model must be the class of a model'],
]);
