<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
    mkdir($this->generated->path('app/Guards/Admin'), 0o755, true);
});
afterEach(fn () => $this->generated->release());

it('creates the enum of a group under Permissions/{Group}', function (): void {
    expect(Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders']))->toBe(0);
    $enum = $this->generated->read('app/Guards/Admin/Permissions/Orders/OrderPermission.php');

    expect($this->generated->files())->toBe(['app/Guards/Admin/Permissions/Orders/OrderPermission.php'])
        ->and($enum)->toContain(
            'namespace '.$this->generated->namespace.'\Guards\Admin\Permissions\Orders;',
            "#[Resource(label: 'Orders')]",
            '#[RequiresGrant]',
            'enum OrderPermission: string',
            "case ViewAny = 'orders.view-any';",
            "case Delete = 'orders.delete';",
        );
});

it('names the enum of a group by the singular of its last segment', function (string $group, string $class, string $key): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => $group]);

    expect($this->generated->files())->toHaveCount(1)
        ->and($this->generated->read('app/Guards/Admin/Permissions/'.str_replace('\\', '/', $group).'/'.$class.'.php'))->toContain("case View = '".$key.".view';");
})->with([
    'a plural' => ['Sources', 'SourcePermission', 'sources'],
    'users' => ['Users', 'UserPermission', 'users'],
    'a singular stays' => ['Profile', 'ProfilePermission', 'profile'],
    'a nested group' => ['Sales/Orders', 'OrderPermission', 'sales.orders'],
    'a nested group with a backslash' => ['Sales\Orders', 'OrderPermission', 'sales.orders'],
    'a camel case segment' => ['OrderItems', 'OrderItemPermission', 'order-items'],
]);

it('puts a nested group in nested folders and namespaces of all its files', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Sales/Orders', '--policy' => true, '--abilities' => true]);
    $namespace = $this->generated->namespace.'\Guards\Admin';

    expect($this->generated->files())->toBe([
        'app/Guards/Admin/Abilities/Sales/Orders/OrderAbilities.php',
        'app/Guards/Admin/Permissions/Sales/Orders/OrderPermission.php',
        'app/Guards/Admin/Policies/Sales/Orders/OrderPolicy.php',
    ])
        ->and($this->generated->read('app/Guards/Admin/Policies/Sales/Orders/OrderPolicy.php'))->toContain(
            'namespace '.$namespace.'\Policies\Sales\Orders;',
            'use '.$namespace.'\Permissions\Sales\Orders\OrderPermission;',
        )
        ->and($this->generated->read('app/Guards/Admin/Abilities/Sales/Orders/OrderAbilities.php'))->toContain('namespace '.$namespace.'\Abilities\Sales\Orders;');
});

it('gives the policy a #[Decides] method for each case and no #[PolicyFor]', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true]);
    $policy = $this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php');

    expect($policy)->toContain('final class OrderPolicy')->not->toContain('PolicyFor');
    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $case) {
        expect($policy)->toContain('#[Decides(OrderPermission::'.$case.')]'."\n".'    public function '.lcfirst($case).'(');
    }
    expect(substr_count($policy, '#[Decides('))->toBe(5)
        ->and(substr_count($policy, 'public function '))->toBe(5);
});

it('puts the model into #[Resource] and the abilities DTO on the enum of the group', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--model' => 'App\Models\Order', '--abilities' => true]);
    $abilities = $this->generated->read('app/Guards/Admin/Abilities/Orders/OrderAbilities.php');

    expect($this->generated->read('app/Guards/Admin/Permissions/Orders/OrderPermission.php'))
        ->toContain('use App\Models\Order;', "#[Resource(label: 'Orders', model: Order::class)]")
        ->and($abilities)->toContain('final readonly class OrderAbilities', 'public bool $viewAny,', 'hasPermission(OrderPermission::Update, $on)');
});

it('writes none of the files when one exists, and overwrites all of them with --force', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true]);
    file_put_contents($this->generated->path('app/Guards/Admin/Policies/Orders/OrderPolicy.php'), 'owner policy');
    unlink($this->generated->path('app/Guards/Admin/Permissions/Orders/OrderPermission.php'));

    expect(Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('app/Guards/Admin/Policies/Orders/OrderPolicy.php already exists', 'nothing was written')
        ->and($this->generated->has('app/Guards/Admin/Permissions/Orders/OrderPermission.php'))->toBeFalse()
        ->and($this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php'))->toBe('owner policy');

    expect(Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true, '--force' => true]))->toBe(0)
        ->and($this->generated->has('app/Guards/Admin/Permissions/Orders/OrderPermission.php'))->toBeTrue()
        ->and($this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php'))->toContain('final class OrderPolicy');
});

it('needs the panel directory and tells which command makes it', function (): void {
    expect(Artisan::call('azguard:make:permission', ['panel' => 'Missing', 'group' => 'Orders']))->toBe(2)
        ->and(Artisan::output())->toContain('app/Guards/Missing', 'azguard:make:panel Missing')
        ->and($this->generated->has('app/Guards/Missing/Permissions/Orders/OrderPermission.php'))->toBeFalse();
});

it('refuses a group that is not a path of names', function (string $group): void {
    expect(Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => $group]))->toBe(2)
        ->and(Artisan::output())->toContain('is not a group')
        ->and($this->generated->files())->toBe([]);
})->with(['..', '../Orders', 'Orders/../..', 'Or ders!', '1Orders', 'Sales//Orders']);

it('uses the folder names of the discovery configuration', function (): void {
    config()->set('azguard.discovery', ['permissions' => 'Rights', 'policies' => 'Rules', 'abilities' => 'Can']);
    app()->forgetInstance(AzGuardConfig::class);
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true, '--abilities' => true]);

    expect($this->generated->files())->toBe([
        'app/Guards/Admin/Can/Orders/OrderAbilities.php',
        'app/Guards/Admin/Rights/Orders/OrderPermission.php',
        'app/Guards/Admin/Rules/Orders/OrderPolicy.php',
    ]);
});

it('refuses a model that is not a class name', function (): void {
    expect(Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--model' => 'Order; drop']))->toBe(2)
        ->and($this->generated->files())->toBe([]);
});
