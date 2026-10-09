<?php

declare(strict_types=1);

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
    mkdir($this->generated->path('app/Guards/Admin'), 0o755, true);
});
afterEach(fn () => $this->generated->release());

it('puts a source, plugin, restriction or pipe in a panel or in what panels share', function (string $command, string $name, string $folder, string $class): void {
    expect(Artisan::call($command, ['name' => $name, '--panel' => 'Admin']))->toBe(0)
        ->and(Artisan::call($command, ['name' => $name, '--shared' => true]))->toBe(0)
        ->and($this->generated->files())->toBe([
            'app/Guards/Admin/'.$folder.'/'.$class.'.php',
            'app/Guards/Shared/'.$folder.'/'.$class.'.php',
        ])
        ->and($this->generated->read('app/Guards/Shared/'.$folder.'/'.$class.'.php'))->toContain('namespace '.$this->generated->namespace.'\Guards\Shared\\'.$folder.';');
})->with([
    'source' => ['azguard:make:source', 'Ldap', 'Sources', 'LdapSource'],
    'plugin' => ['azguard:make:plugin', 'AuditTrail', 'Plugins', 'AuditTrailPlugin'],
    'restriction' => ['azguard:make:restriction', 'AccountLocked', 'Restrictions', 'AccountLockedRestriction'],
    'pipe' => ['azguard:make:pipe', 'RequireReason', 'Changes', 'RequireReason'],
]);

it('asks for exactly one of --panel and --shared', function (string $command): void {
    $none = Artisan::call($command, ['name' => 'Thing']);
    $noneOutput = Artisan::output();
    $both = Artisan::call($command, ['name' => 'Thing', '--panel' => 'Admin', '--shared' => true]);

    expect($none)->toBe(2)->and($noneOutput)->toContain('--panel=<Panel>', '--shared')
        ->and($both)->toBe(2)
        ->and($this->generated->files())->toBe([]);
})->with(['azguard:make:source', 'azguard:make:plugin', 'azguard:make:restriction', 'azguard:make:pipe']);

it('needs the directory of the panel for --panel', function (): void {
    expect(Artisan::call('azguard:make:source', ['name' => 'Ldap', '--panel' => 'Missing']))->toBe(2)
        ->and(Artisan::output())->toContain('azguard:make:panel Missing');
});

it('makes a source with #[AsSource] and the capabilities asked for', function (array $options, array $interfaces): void {
    Artisan::call('azguard:make:source', ['name' => 'LdapSource', '--panel' => 'Admin', ...$options]);
    $source = $this->generated->read('app/Guards/Admin/Sources/LdapSource.php');

    expect($source)->toContain("#[AsSource('ldap')]", 'final class LdapSource implements '.implode(', ', $interfaces), "return 'ldap';");
    foreach (['grants' => 'ProvidesGrants', 'permissions' => 'ProvidesPermissions', 'roles' => 'ProvidesRoles', 'policies' => 'ProvidesPolicies'] as $option => $interface) {
        expect(str_contains($source, 'implements') && in_array($interface, $interfaces, true))->toBe(array_key_exists('--'.$option, $options));
    }
})->with([
    'a bare source' => [[], ['Source']],
    'grants' => [['--grants' => true], ['ProvidesGrants']],
    'permissions and roles' => [['--permissions' => true, '--roles' => true], ['ProvidesPermissions', 'ProvidesRoles']],
    'every capability' => [['--grants' => true, '--permissions' => true, '--roles' => true, '--policies' => true], ['ProvidesGrants', 'ProvidesPermissions', 'ProvidesPolicies', 'ProvidesRoles']],
]);

it('gives the source the id of its name and the method of each capability', function (): void {
    Artisan::call('azguard:make:source', ['name' => 'CorporateDirectory', '--shared' => true, '--grants' => true, '--policies' => true]);
    $source = $this->generated->read('app/Guards/Shared/Sources/CorporateDirectorySource.php');

    expect($source)->toContain("#[AsSource('corporate-directory')]", 'public function grants(', 'public function volatility(', 'public function policies(')
        ->not->toContain('public function roles(', 'public function permissions(');
});

it('makes a plugin with its own typed named factory and no options', function (): void {
    Artisan::call('azguard:make:plugin', ['name' => 'AuditTrailPlugin', '--panel' => 'Admin']);
    $class = $this->generated->class('Admin\Plugins\AuditTrailPlugin');
    $reflection = new ReflectionClass($class);
    $make = $reflection->getMethod('make');

    expect($reflection->getMethod('id')->invoke($make->invoke(null)))->toBe('app/audit-trail')
        ->and(is_subclass_of($class, Plugin::class))->toBeTrue()
        ->and($make->isStatic() && $make->isPublic())->toBeTrue()
        ->and($make->getReturnType()?->getName())->toBeIn(['self', $class]) // PHP 8.5 reflection resolves self to the class
        ->and($make->getNumberOfParameters())->toBe(0)
        ->and($reflection->getConstructor()?->isPrivate())->toBeTrue()
        ->and($reflection->hasMethod('options'))->toBeFalse()
        ->and($reflection->hasMethod('fromArray'))->toBeFalse()
        ->and($reflection->getProperties())->toBe([])
        ->and($this->generated->read('app/Guards/Admin/Plugins/AuditTrailPlugin.php'))->not->toContain('array $');
});

it('makes a restriction that only passes until the owner writes a rule', function (): void {
    Artisan::call('azguard:make:restriction', ['name' => 'AccountLocked', '--panel' => 'Admin']);
    $restriction = new ($this->generated->class('Admin\Restrictions\AccountLockedRestriction'));

    expect($restriction->key())->toBe('account-locked')
        ->and($restriction->exemptsSuperAdmin())->toBeFalse();
});

it('makes a change pipe with handle() that hands the change on', function (): void {
    Artisan::call('azguard:make:pipe', ['name' => 'RequireReason', '--panel' => 'Admin']);
    $pipe = $this->generated->read('app/Guards/Admin/Changes/RequireReason.php');

    expect($pipe)->toContain('final class RequireReason', 'public function handle(Change $change, Closure $next): ChangeResult', 'return $next($change);');
});

it('makes the grant models of a panel and the migration of their columns', function (): void {
    expect(Artisan::call('azguard:make:models', ['panel' => 'Admin']))->toBe(0);
    $files = $this->generated->files();
    $migration = array_values(array_filter($files, static fn (string $file): bool => str_starts_with($file, 'database/migrations/')));

    expect($files)->toContain('app/Guards/Admin/Models/AdminRoleGrant.php', 'app/Guards/Admin/Models/AdminPermissionGrant.php')
        ->and($migration)->toHaveCount(1)
        ->and($migration[0])->toMatch('#^database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_add_admin_columns_to_azguard_tables\.php$#')
        ->and($this->generated->read('app/Guards/Admin/Models/AdminRoleGrant.php'))->toContain('final class AdminRoleGrant extends RoleGrant', 'public static function azguardFields(): array')
        ->and($this->generated->read($migration[0]))->toContain("get('default')", "'role_grants'", "'permission_grants'")
        ->and(Artisan::output())->toContain('DatabaseSource::make()->models(roleGrant: AdminRoleGrant::class');

    // The classes are the subclasses a DatabaseSource accepts.
    expect(is_subclass_of($this->generated->class('Admin\Models\AdminRoleGrant'), RoleGrant::class))->toBeTrue()
        ->and(is_subclass_of($this->generated->class('Admin\Models\AdminPermissionGrant'), PermissionGrant::class))->toBeTrue();
});

it('does not write a second migration for the panel and does not overwrite the models', function (): void {
    Artisan::call('azguard:make:models', ['panel' => 'Admin']);
    $before = $this->generated->files();

    expect(Artisan::call('azguard:make:models', ['panel' => 'Admin']))->toBe(1)
        ->and(Artisan::output())->toContain('already exist', 'nothing was written')
        ->and($this->generated->files())->toBe($before)
        ->and(Artisan::call('azguard:make:models', ['panel' => 'Admin', '--force' => true]))->toBe(0)
        ->and($this->generated->files())->toBe($before);
});

it('refuses a storage that is not configured', function (): void {
    expect(Artisan::call('azguard:make:models', ['panel' => 'Admin', '--storage' => 'nowhere']))->toBe(2)
        ->and($this->generated->files())->toBe([]);
});

it('publishes the stubs, keeps the published ones and replaces them with --force', function (): void {
    expect(Artisan::call('azguard:stubs'))->toBe(0);
    $published = glob($this->generated->path('stubs/azguard/*.stub')) ?: [];
    $names = array_map('basename', $published);

    expect($names)->toContain('panel-provider.stub', 'permission.stub', 'policy.stub', 'abilities.stub', 'role.stub', 'source.stub', 'plugin.stub', 'restriction.stub', 'change-pipe.stub', 'panel-models.stub')
        ->and(Artisan::output())->toContain('Published stubs/azguard/permission.stub');

    file_put_contents($this->generated->path('stubs/azguard/role.stub'), 'owner stub');
    Artisan::call('azguard:stubs');

    expect($this->generated->read('stubs/azguard/role.stub'))->toBe('owner stub')
        ->and(Artisan::output())->toContain('stubs/azguard/role.stub exists and was kept');

    Artisan::call('azguard:stubs', ['--force' => true]);

    expect($this->generated->read('stubs/azguard/role.stub'))->toBe(file_get_contents(__DIR__.'/../../../../packages/core/stubs/role.stub'));
});

it('prefers a published stub to the stub of the package', function (): void {
    mkdir($this->generated->path('stubs/azguard'), 0o755, true);
    file_put_contents($this->generated->path('stubs/azguard/role.stub'), "<?php\n\n// {{ class }} of {{ namespace }}\n");

    Artisan::call('azguard:make:role', ['panel' => 'Admin', 'name' => 'Manager']);

    expect($this->generated->read('app/Guards/Admin/Roles/ManagerRole.php'))->toBe("<?php\n\n// ManagerRole of ".$this->generated->namespace."\\Guards\\Admin\\Roles\n");
});

it('refuses a published stub with a placeholder this release does not fill, and writes nothing', function (): void {
    mkdir($this->generated->path('stubs/azguard'), 0o755, true);
    file_put_contents($this->generated->path('stubs/azguard/role.stub'), "<?php\n// {{ from_an_older_release }}\n");

    expect(Artisan::call('azguard:make:role', ['panel' => 'Admin', 'name' => 'Manager']))->toBe(2)
        ->and(Artisan::output())->toContain('{{ from_an_older_release }}', 'azguard:stubs --force')
        ->and($this->generated->has('app/Guards/Admin/Roles/ManagerRole.php'))->toBeFalse();
});

it('does not write outside the scaffold path for a hostile name', function (string $command, array $arguments): void {
    expect(Artisan::call($command, $arguments))->toBe(2)
        ->and(array_filter($this->generated->files(), static fn (string $file): bool => ! str_starts_with($file, 'app/Guards/')))->toBe([]);
})->with([
    ['azguard:make:source', ['name' => '../../Evil', '--shared' => true]],
    ['azguard:make:plugin', ['name' => '..\\Evil', '--shared' => true]],
    ['azguard:make:role', ['panel' => 'Admin', 'name' => '../Evil']],
    ['azguard:make:pipe', ['name' => 'a/b', '--panel' => 'Admin']],
]);
