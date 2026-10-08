<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Illuminate\Support\Facades\Artisan;

enum IntegerBackedEnum: int
{
    case One = 1;
}

enum PureEnum
{
    case One;
}

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
    mkdir($this->generated->path('app/Guards/Admin'), 0o755, true);
});
afterEach(fn () => $this->generated->release());

it('writes the explicit key of a role into #[Role]', function (string $name, string $class, string $key, string $label): void {
    expect(Artisan::call('azguard:make:role', ['panel' => 'Admin', 'name' => $name]))->toBe(0);
    $role = $this->generated->read('app/Guards/Admin/Roles/'.$class.'.php');

    expect($this->generated->files())->toBe(['app/Guards/Admin/Roles/'.$class.'.php'])
        ->and($role)->toContain("#[Role('".$key."', label: '".$label."')]", 'final class '.$class.' extends BaseRole', 'return [];');
})->with([
    'a plain name' => ['Manager', 'ManagerRole', 'manager', 'Manager'],
    'the suffix is not doubled' => ['ManagerRole', 'ManagerRole', 'manager', 'Manager'],
    'a two word name' => ['SalesManager', 'SalesManagerRole', 'sales-manager', 'Sales Manager'],
    'a lowercase name' => ['super-admin', 'SuperAdminRole', 'super-admin', 'Super Admin'],
]);

it('does not overwrite a role without --force', function (): void {
    Artisan::call('azguard:make:role', ['panel' => 'Admin', 'name' => 'Manager']);
    file_put_contents($this->generated->path('app/Guards/Admin/Roles/ManagerRole.php'), 'owner role');

    expect(Artisan::call('azguard:make:role', ['panel' => 'Admin', 'name' => 'Manager']))->toBe(1)
        ->and($this->generated->read('app/Guards/Admin/Roles/ManagerRole.php'))->toBe('owner role');
});

it('refuses a role name that gives no valid key', function (): void {
    expect(Artisan::call('azguard:make:role', ['panel' => 'Admin', 'name' => str_repeat('Long', 20)]))->toBe(2)
        ->and(Artisan::output())->toContain('role key')
        ->and($this->generated->files())->toBe([]);
});

it('creates the policy of the one enum of a group with a method for each case', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders']);

    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders']))->toBe(0);
    $policy = $this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php');

    expect($policy)->toContain('use '.$this->generated->namespace.'\Guards\Admin\Permissions\Orders\OrderPermission;', 'final class OrderPolicy')
        ->not->toContain('PolicyFor')
        ->and(substr_count($policy, '#[Decides(OrderPermission::'))->toBe(5);
});

it('follows the cases of the enum as the owner changed them', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders']);
    $path = $this->generated->path('app/Guards/Admin/Permissions/Orders/OrderPermission.php');
    file_put_contents($path, preg_replace('/\s+#\[Describe\(\'(Create|Update|Delete)\'\)\]\n\s+case \w+ = [^\n]+/', '', (string) file_get_contents($path)));
    $cases = substr_count((string) file_get_contents($path), 'case ');

    Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders']);

    expect($cases)->toBe(2)
        ->and(substr_count($this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php'), '#[Decides('))->toBe(2);
});

it('does not guess when the group has no enum or several', function (): void {
    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders']))->toBe(2)
        ->and(Artisan::output())->toContain('has no permission enum', 'azguard:make:permission');

    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders']);
    copy($this->generated->path('app/Guards/Admin/Permissions/Orders/OrderPermission.php'), $this->generated->path('app/Guards/Admin/Permissions/Orders/RefundPermission.php'));
    file_put_contents($this->generated->path('app/Guards/Admin/Permissions/Orders/RefundPermission.php'), str_replace('OrderPermission', 'RefundPermission', $this->generated->read('app/Guards/Admin/Permissions/Orders/RefundPermission.php')));

    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders']))->toBe(2)
        ->and(Artisan::output())->toContain('2 permission enums', 'OrderPermission', 'RefundPermission', '--enum', '#[PolicyFor]')
        ->and($this->generated->has('app/Guards/Admin/Policies/Orders/OrderPolicy.php'))->toBeFalse();
});

it('binds the policy to the enum of --enum with #[PolicyFor]', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders']);
    $enum = $this->generated->class('Admin\Permissions\Orders\OrderPermission');
    copy($this->generated->path('app/Guards/Admin/Permissions/Orders/OrderPermission.php'), $this->generated->path('app/Guards/Admin/Permissions/Orders/RefundPermission.php'));
    file_put_contents($this->generated->path('app/Guards/Admin/Permissions/Orders/RefundPermission.php'), str_replace(
        ['OrderPermission', "'orders."],
        ['RefundPermission', "'refunds."],
        $this->generated->read('app/Guards/Admin/Permissions/Orders/RefundPermission.php'),
    ));

    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders', '--enum' => $enum]))->toBe(0);
    $policy = $this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php');

    expect($policy)->toContain('use AzGuard\Policies\PolicyFor;', '#[PolicyFor(OrderPermission::class)]'."\n".'final class OrderPolicy')
        ->and(substr_count($policy, '#[Decides('))->toBe(5);
});

it('refuses a second policy in a group because the pair would be ambiguous, and names the way out', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true]);
    file_put_contents($this->generated->path('app/Guards/Admin/Policies/Orders/ExtraPolicy.php'), '<?php // owner policy');
    unlink($this->generated->path('app/Guards/Admin/Policies/Orders/OrderPolicy.php'));

    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders']))->toBe(2)
        ->and(Artisan::output())->toContain('already has the policy ExtraPolicy', '#[PolicyFor]', '--enum=')
        ->and($this->generated->has('app/Guards/Admin/Policies/Orders/OrderPolicy.php'))->toBeFalse();
});

it('refuses an --enum that is not a string-backed enum', function (string $enum): void {
    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders', '--enum' => $enum]))->toBe(2)
        ->and(Artisan::output())->toContain('--enum must be a string-backed enum')
        ->and($this->generated->files())->toBe([]);
})->with([
    'a class that does not exist' => ['App\Missing'],
    'a class that is not an enum' => [GeneratedApp::class],
    'an int backed enum' => [IntegerBackedEnum::class],
    'a pure enum' => [PureEnum::class],
]);

it('does not overwrite a policy without --force', function (): void {
    Artisan::call('azguard:make:permission', ['panel' => 'Admin', 'group' => 'Orders', '--policy' => true]);
    file_put_contents($this->generated->path('app/Guards/Admin/Policies/Orders/OrderPolicy.php'), 'owner policy');

    expect(Artisan::call('azguard:make:policy', ['panel' => 'Admin', 'group' => 'Orders']))->toBe(1)
        ->and($this->generated->read('app/Guards/Admin/Policies/Orders/OrderPolicy.php'))->toBe('owner policy');
});
