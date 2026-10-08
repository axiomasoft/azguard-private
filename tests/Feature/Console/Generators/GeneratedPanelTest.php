<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(BootsWithCatalogCache::class);

beforeEach(function (): void {
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-catalog-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'generated-1';
    $this->generated = GeneratedApp::in(app(), true);
});
afterEach(function (): void {
    $this->generated->release();
    Relation::morphMap([], false);
    @unlink(self::$catalogCachePath);
    @rmdir(dirname(self::$catalogCachePath));
});

/** The application of the owner: one panel with the groups Orders of two departments and a group named Sources. */
function generateOrdersApp(GeneratedApp $generated, bool $vetoUpdate = false): void
{
    $steps = [
        ['azguard:make:panel', ['panel' => 'Orders', '--model' => User::class]],
        ['azguard:make:permission', ['panel' => 'Orders', 'group' => 'Sales/Orders', '--policy' => true, '--abilities' => true, '--model' => User::class]],
        ['azguard:make:permission', ['panel' => 'Orders', 'group' => 'Support/Orders', '--policy' => true]],
        ['azguard:make:permission', ['panel' => 'Orders', 'group' => 'Sources']],
        ['azguard:make:role', ['panel' => 'Orders', 'name' => 'Manager']],
        ['azguard:make:source', ['name' => 'Plain', '--shared' => true]],
        ['azguard:make:source', ['name' => 'Ldap', '--panel' => 'Orders', '--grants' => true]],
    ];

    foreach ($steps as [$command, $arguments]) {
        expect(Artisan::call($command, $arguments))->toBe(0, $command.': '.Artisan::output());
    }
    // An enum in the root Sources folder, next to the generated source, that discovery must not take for a group.
    file_put_contents($generated->path('app/Guards/Orders/Sources/NotAGroup.php'), "<?php\n\ndeclare(strict_types=1);\n\nnamespace "
        .$generated->namespace."\\Guards\\Orders\\Sources;\n\nuse AzGuard\\Permissions\\RequiresGrant;\n\n#[RequiresGrant]\nenum NotAGroup: string\n{\n    case Trap = 'sources.trap';\n}\n");
    // The owner gives the role a permission and makes the update of sales orders veto-able.
    $role = 'app/Guards/Orders/Roles/ManagerRole.php';
    file_put_contents($generated->path($role), str_replace('return [];', 'return [\\'.$generated->class('Orders\Permissions\Sales\Orders\OrderPermission').'::View];', $generated->read($role)));

    if ($vetoUpdate) {
        $policy = 'app/Guards/Orders/Policies/Sales/Orders/OrderPolicy.php';
        $source = $generated->read($policy);
        $update = (int) strpos($source, 'public function update(');
        file_put_contents($generated->path($policy), substr($source, 0, $update).preg_replace('/return true;/', 'return false;', substr($source, $update), 1));
    }
}

/**
 * Boots the application with the generated panel as a host does, then seeds the database of this boot, grants two
 * permissions and answers every question the generated code can be asked.
 *
 * @return array{snapshot: array<mixed>, fingerprint: string, decisions: array<string, mixed>, panels: list<string>}
 */
function bootGenerated(object $test, GeneratedApp $generated): array
{
    $test->bootCatalogPanels([$generated->class('Orders\OrdersGuardPanelProvider')]);
    Relation::morphMap(['user' => User::class], false);
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('department')->nullable();
        $table->boolean('is_root')->default(false);
    });
    User::query()->insert(['id' => 1, 'department' => 'sales']);
    app(StorageSchema::class)->create('default');

    $panel = app(AzGuardManager::class)->panel('orders');
    $subject = $panel->for(User::query()->findOrFail(1));
    $sales = $generated->class('Orders\Permissions\Sales\Orders\OrderPermission');
    $support = $generated->class('Orders\Permissions\Support\Orders\OrderPermission');
    $subject->grantPermission([$sales::View, $sales::Update, $support::View]);

    $decisions = [];
    foreach ([$sales, $support] as $enum) {
        foreach ($enum::cases() as $case) {
            $decision = $subject->decide($case);
            $decisions[$case->value] = [$decision->allowed(), $decision->reason->value];
        }
    }
    $abilities = $generated->class('Orders\Abilities\Sales\Orders\OrderAbilities');
    $decisions['abilities'] = (array) $abilities::for($subject);

    $registry = app(PanelRegistry::class);

    return [
        'snapshot' => $registry->catalog('orders')->snapshot(),
        'fingerprint' => $registry->fingerprint('orders'),
        'decisions' => $decisions,
        'panels' => array_keys($registry->all()),
    ];
}

it('V78 finds the enum, the policy and the role of a generated panel by the folders, with #[Decides] on every method', function (): void {
    generateOrdersApp($this->generated);
    $booted = bootGenerated($this, $this->generated);
    $snapshot = $booted['snapshot'];
    $sales = $this->generated->class('Orders\Policies\Sales\Orders\OrderPolicy');
    $support = $this->generated->class('Orders\Policies\Support\Orders\OrderPolicy');

    expect(array_column($snapshot['permissions'], 'local'))->toBe([
        'sales.orders.view-any', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update', 'sales.orders.delete',
        'sources.view-any', 'sources.view', 'sources.create', 'sources.update', 'sources.delete',
        'support.orders.view-any', 'support.orders.view', 'support.orders.create', 'support.orders.update', 'support.orders.delete',
    ])
        ->and($snapshot['bindings']['sales.orders.update'])->toBe($sales)
        ->and($snapshot['bindings']['support.orders.update'])->toBe($support)
        ->and($snapshot['binding_methods']['sales.orders.view-any'])->toBe('viewAny')
        ->and(array_key_exists('sources.view', $snapshot['bindings']))->toBeFalse()
        ->and(array_keys($snapshot['roles']))->toBe(['manager'])
        ->and($snapshot['roles']['manager']['permissions'])->not->toBe([])
        ->and($snapshot['permissions'][0]['source'])->toBe('folder');
});

it('V78 derives no binding from a method name when the attribute is gone', function (): void {
    generateOrdersApp($this->generated);
    $policy = 'app/Guards/Orders/Policies/Sales/Orders/OrderPolicy.php';
    file_put_contents($this->generated->path($policy), str_replace("    #[Decides(OrderPermission::Create)]\n", '', $this->generated->read($policy)));

    $snapshot = bootGenerated($this, $this->generated)['snapshot'];

    expect(array_key_exists('sales.orders.create', $snapshot['bindings']))->toBeFalse()
        ->and(array_key_exists('sales.orders.update', $snapshot['bindings']))->toBeTrue()
        // The method is still named create(), and the case Create exists in the enum: the name binds nothing.
        ->and($this->generated->read($policy))->toContain('public function create(');
});

it('V78 does not take Shared for a panel and registers the names of the sources it finds', function (): void {
    generateOrdersApp($this->generated);
    $booted = bootGenerated($this, $this->generated);

    expect($booted['panels'])->toBe(['orders'])
        ->and(app(SourceManager::class)->names())->toHaveKey('plain')
        // The name of the source in the Sources folder of the panel is registered too, but the panel uses a source only
        // when its provider attaches it.
        ->and(app(SourceManager::class)->names())->toHaveKey('ldap')
        ->and(array_column($booted['snapshot']['sources'], 'id'))->toBe(['folder', 'database']);
});

it('V106 keeps the groups Orders of Sales and of Support apart and does not read the root Sources as a group', function (): void {
    generateOrdersApp($this->generated);
    $snapshot = bootGenerated($this, $this->generated)['snapshot'];
    $enumOf = static fn (string $local): string => $snapshot['permissions'][array_search($local, array_column($snapshot['permissions'], 'local'), true)]['case']['enum'];

    expect($enumOf('sales.orders.view'))->toBe($this->generated->class('Orders\Permissions\Sales\Orders\OrderPermission'))
        ->and($enumOf('support.orders.view'))->toBe($this->generated->class('Orders\Permissions\Support\Orders\OrderPermission'))
        // The generated group named Sources is a group of permissions; the enum in the root Sources folder is not.
        ->and($enumOf('sources.view'))->toBe($this->generated->class('Orders\Permissions\Sources\SourcePermission'))
        ->and(array_search('sources.trap', array_column($snapshot['permissions'], 'local'), true))->toBeFalse();
});

it('V106 refuses a group with two policies that nothing tells apart, as the generator does', function (): void {
    generateOrdersApp($this->generated);
    $folder = 'app/Guards/Orders/Policies/Sales/Orders/';
    file_put_contents($this->generated->path($folder.'ExtraPolicy.php'), str_replace('OrderPolicy', 'ExtraPolicy', $this->generated->read($folder.'OrderPolicy.php')));

    expect(fn () => bootGenerated($this, $this->generated))->toThrow(InvalidPolicyStructureException::class, 'Name the enum on the policy');
});

it('V105 R56 decides the same live and after azguard:catalog:cache', function (bool $vetoUpdate): void {
    generateOrdersApp($this->generated, $vetoUpdate);
    $live = bootGenerated($this, $this->generated);

    expect($live['decisions']['sales.orders.view'])->toBe([true, 'granted'])
        ->and($live['decisions']['sales.orders.create'][0])->toBeFalse()
        ->and($live['decisions']['support.orders.view'])->toBe([true, 'granted'])
        ->and($live['decisions']['support.orders.update'][0])->toBeFalse()
        // The grant of the update is vetoed by the policy the owner edited, and by that one only.
        ->and($live['decisions']['sales.orders.update'][0])->toBe(! $vetoUpdate)
        ->and($live['decisions']['abilities'])->toBe(['viewAny' => false, 'view' => true, 'create' => false, 'update' => ! $vetoUpdate, 'delete' => false]);

    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $cached = bootGenerated($this, $this->generated);

    expect($cached['snapshot'])->toBe($live['snapshot'])
        ->and($cached['fingerprint'])->toBe($live['fingerprint'])
        ->and($cached['decisions'])->toBe($live['decisions']);
})->with(['the policy allows' => false, 'the policy vetoes' => true]);

it('V105 the folder discovery and the cache give the catalog of the generated panel with an equal fingerprint', function (): void {
    generateOrdersApp($this->generated);
    $live = bootGenerated($this, $this->generated);
    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $file = require self::$catalogCachePath;

    expect($file['panels']['orders']['catalog'])->toBe($live['snapshot']);
    $cached = bootGenerated($this, $this->generated);

    expect($cached['fingerprint'])->toBe($live['fingerprint'])
        ->and($cached['snapshot'])->toBe($live['snapshot']);
});

it('V78 adds the folder of a module generated beside the panel with discover(), keeping its groups apart', function (): void {
    foreach ([
        ['azguard:make:panel', ['panel' => 'Shop', '--model' => User::class]],
        ['azguard:make:permission', ['panel' => 'Shop', 'group' => 'Orders', '--policy' => true]],
        ['azguard:make:panel', ['panel' => 'Blog', '--model' => User::class]],
        ['azguard:make:permission', ['panel' => 'Blog', 'group' => 'Posts', '--policy' => true]],
    ] as [$command, $arguments]) {
        expect(Artisan::call($command, $arguments))->toBe(0);
    }
    $provider = 'app/Guards/Shop/ShopGuardPanelProvider.php';
    file_put_contents($this->generated->path($provider), str_replace(
        '->permissions([DatabaseSource::make()]);',
        "->permissions([DatabaseSource::make()])\n            ->discover('".$this->generated->path('app/Guards/Blog')."', '".addslashes($this->generated->class('Blog'))."');",
        $this->generated->read($provider),
    ));
    $this->bootCatalogPanels([$this->generated->class('Shop\ShopGuardPanelProvider')]);
    $snapshot = app(PanelRegistry::class)->catalog('shop')->snapshot();

    expect(array_keys($snapshot['bindings']))->toContain('orders.view', 'posts.view')
        ->and($snapshot['bindings']['posts.view'])->toBe($this->generated->class('Blog\Policies\Posts\PostPolicy'))
        ->and($snapshot['bindings']['orders.view'])->toBe($this->generated->class('Shop\Policies\Orders\OrderPolicy'))
        ->and(count($snapshot['permissions']))->toBe(10);
});
