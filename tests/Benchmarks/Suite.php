<?php

declare(strict_types=1);

/*
 * Reproducible benchmark suite of the public API: `composer bench` (or `php tests/Benchmarks/Suite.php`).
 *
 * A real Eloquent subject, the database source and a generated catalog (permission enums and roles written to a temp
 * directory) run in one sequential process. Each scenario reports the median and p95 of the wall time of one operation
 * and the SQL queries of one operation. The numbers are relative: compare runs on the same machine, never thresholds.
 *
 * AZGUARD_BENCH_SAMPLES (default 300) sets the samples per scenario; DB_CONNECTION=pgsql|mysql|mariadb uses the test
 * databases of the suite (tests/TestCase.php), the default is SQLite in memory.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\TestCase;
use AzGuardBench\BenchPanelProvider;
use AzGuardBench\Post;
use AzGuardBench\RolePermissions;
use AzGuardBench\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const GROUPS = 50;          // permission enums
const CASES = 20;           // permissions per enum: 1 000 permissions
const ROLES = 40;           // roles in the catalog
const ROLE_SIZE = 50;       // permissions per role
const HEAVY_ROLES = 20;     // roles of the heavy subject
const HEAVY_DIRECT = 200;   // direct grants of the heavy subject
const POSTS = 10_000;       // rows for the list query

putenv('APP_ENV=testing');
$samples = (int) (getenv('AZGUARD_BENCH_SAMPLES') ?: 300);
$generated = generateCatalog();

$test = new class('bench') extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('azguard.panels.providers', [BenchPanelProvider::class]);
        $app['config']->set('azguard.schedule.enabled', false);
    }
};
$app = $test->createApplication();
Relation::enforceMorphMap(['user' => User::class, 'post' => Post::class]);
Schema::create('users', static function ($table): void {
    $table->id();
    $table->string('name');
});
Schema::create('posts', static function ($table): void {
    $table->id();
    $table->foreignId('user_id')->index();
});
$app->make('Illuminate\Contracts\Console\Kernel')->call('migrate', ['--force' => true]);

$queries = 0;
DB::listen(static function () use (&$queries): void {
    $queries++;
});

/** Runs $operation $samples times after a warm-up; $before runs untimed before each sample. */
$measure = static function (string $name, Closure $operation, ?Closure $before = null, ?int $count = null) use ($samples, &$queries): array {
    $count ??= $samples;
    for ($i = 0; $i < min(20, $count); $i++) {
        $before?->__invoke();
        $operation($i);
    }
    $times = [];
    $sql = 0;
    for ($i = 0; $i < $count; $i++) {
        $before?->__invoke();
        $start = $queries;
        $t = hrtime(true);
        $operation($i);
        $times[] = (hrtime(true) - $t) / 1e3;
        $sql += $queries - $start;
    }
    sort($times);

    return ['scenario' => $name, 'samples' => $count, 'median_us' => round($times[intdiv($count, 2)], 1),
        'p95_us' => round($times[(int) ceil(.95 * $count) - 1], 1), 'sql_per_op' => round($sql / $count, 2)];
};
$newRequest = static function (): void {
    app()->forgetScopedInstances();
};
$rows = [];

AzGuard::actingAs('benchmark', static function () use (&$light, &$heavy, &$wild): void {
    $light = User::query()->create(['name' => 'light']);
    $light->grantRole('r1');
    $heavy = User::query()->create(['name' => 'heavy']);
    $heavy->grantRole(array_map(static fn (int $r): string => 'r'.$r, range(1, HEAVY_ROLES)));
    $heavy->grantPermission(array_map(static fn (int $i): string => sprintf('g%d.p%d', $i % GROUPS + 1, intdiv($i, GROUPS) + 1), range(0, HEAVY_DIRECT - 1)));
    $wild = User::query()->create(['name' => 'wildcard']);
    $wild->grantPermission('g1.**');
});
$inRole = RolePermissions::of(1)[0];
$missing = 'g50.p20';

// 1. Checks in one request: the permission set is read once and reused.
$rows[] = $measure('check, same request, 1 role', static fn () => $light->hasPermission($inRole));
$rows[] = $measure('check, same request, 20 roles + 200 direct', static fn () => $heavy->hasPermission($missing));
$rows[] = $measure('check, same request, wildcard g1.**', static fn () => $wild->hasPermission('g1.p7'));

// 2. First check of a request: no persistent cache (cache.store = null) vs a persistent store.
$rows[] = $measure('check, new request, no cache store, 1 role', static fn () => $light->hasPermission($inRole), $newRequest);
$rows[] = $measure('check, new request, no cache store, heavy', static fn () => $heavy->hasPermission($missing), $newRequest);
BenchPanelProvider::rebuild(cacheStore: 'array');
$rows[] = $measure('check, new request, array cache store, 1 role', static fn () => $light->hasPermission($inRole), $newRequest);
$rows[] = $measure('check, new request, array cache store, heavy', static fn () => $heavy->hasPermission($missing), $newRequest);
BenchPanelProvider::rebuild(cacheStore: null);

// 3. Many permissions at once.
$twenty = array_slice(RolePermissions::of(1), 0, 20);
$rows[] = $measure('abilities(20), new request, heavy', static fn () => $heavy->guard('bench')->abilities($twenty), $newRequest);

// 4. N+1: one request checks 100 records with a policy (the policy itself runs no SQL).
$posts = [];
foreach (range(1, 100) as $i) {
    $posts[] = Post::query()->create(['user_id' => $i % 2 === 0 ? $light->getKey() : $heavy->getKey()]);
}
AzGuard::actingAs('benchmark', static fn () => $light->grantPermission('posts.view'));
$rows[] = $measure('100 records with a policy, one request (per op = 100 checks)', static function () use ($light, $posts): void {
    foreach ($posts as $post) {
        $light->hasPermission('posts.view', $post);
    }
}, $newRequest, count: max(30, intdiv($samples, 10)));

// 5. The list query: visible rows compiled to SQL, whatever the number of rows.
$insert = [];
for ($i = 0; $i < POSTS; $i++) {
    $insert[] = ['user_id' => $i % 10 === 0 ? $light->getKey() : $heavy->getKey()];
}
foreach (array_chunk($insert, 500) as $chunk) {
    Post::query()->insert($chunk);
}
$panel = AzGuard::panel('bench');
$visible = 0;
$rows[] = $measure('visibleTo(): first page of '.POSTS.' posts, new request', static function () use ($panel, $light, &$visible): void {
    $visible = $panel->visibility()->visibleTo($panel->definition(), Post::query(), $light, 'posts.view')->limit(25)->get()->count();
}, $newRequest, count: max(30, intdiv($samples, 10)));
$rows[] = $measure('visibleTo(): count of '.POSTS.' posts, new request', static fn () => $panel->visibility()
    ->visibleTo($panel->definition(), Post::query(), $light, 'posts.view')->count(), $newRequest, count: max(30, intdiv($samples, 10)));

// 6. Writes go through the change pipeline in a transaction and publish events after commit.
$subjects = [];
for ($i = 0; $i < $samples + 20; $i++) {
    $subjects[] = User::query()->create(['name' => 'w'.$i]);
}
$rows[] = $measure('grantRole() of a new subject', static fn (int $i) => AzGuard::actingAs('benchmark', static fn () => $subjects[$i]->grantRole('r2')));

// 7. Compiling the panel registry: 1 000 permissions, 40 roles (once per process; cached by azguard:catalog:cache).
$rows[] = $measure('compile panel registry (1 000 permissions, 40 roles)', static function (): void {
    $registry = new PanelRegistry(app());
    $registry->register(BenchPanelProvider::class);
    $registry->freeze();
    $registry->get('bench');
}, count: max(30, intdiv($samples, 10)));

if ($visible !== 25) {
    fwrite(STDERR, "Unexpected list size {$visible}\n");
    exit(1);
}
$connection = DB::connection();
$cpu = preg_match('/model name\s*:\s*(.+)/', (string) @file_get_contents('/proc/cpuinfo'), $m) ? trim($m[1]) : php_uname('m');
$result = ['date' => gmdate('Y-m-d\TH:i:s\Z'), 'php' => PHP_VERSION, 'laravel' => $app->version(), 'database' => $connection->getDriverName(),
    'opcache' => (bool) ini_get('opcache.enable_cli'), 'xdebug' => extension_loaded('xdebug'), 'cpu' => $cpu,
    'catalog' => ['permissions' => GROUPS * CASES, 'roles' => ROLES, 'role_size' => ROLE_SIZE, 'heavy_roles' => HEAVY_ROLES, 'heavy_direct' => HEAVY_DIRECT, 'posts' => POSTS],
    'results' => $rows];

if (in_array('--markdown', $argv, true)) {
    echo "| Scenario | Median | p95 | SQL / op |\n|---|---:|---:|---:|\n";
    foreach ($rows as $row) {
        printf("| %s | %s | %s | %s |\n", $row['scenario'], human($row['median_us']), human($row['p95_us']), $row['sql_per_op']);
    }
    printf("\nPHP %s, Laravel %s, %s, %s, OPcache %s.\n", $result['php'], $result['laravel'], $result['database'], $cpu, $result['opcache'] ? 'on' : 'off');
} else {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
}

function human(float $us): string
{
    return $us >= 1000 ? round($us / 1000, 2).' ms' : round($us).' µs';
}

/** Writes the generated catalog, models, policy and panel provider once per shape and loads them. */
function generateCatalog(): string
{
    $dir = sys_get_temp_dir().'/azguard-bench-'.md5(implode(',', [GROUPS, CASES, ROLES, ROLE_SIZE, filemtime(__FILE__)]));

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
        $head = "<?php\n\ndeclare(strict_types=1);\n\nnamespace AzGuardBench;\n\n";
        $enums = [];
        for ($g = 1; $g <= GROUPS; $g++) {
            $cases = '';
            for ($c = 1; $c <= CASES; $c++) {
                $cases .= "    case P{$c} = 'g{$g}.p{$c}';\n";
            }
            file_put_contents("{$dir}/G{$g}Permission.php", $head."#[\\AzGuard\\Permissions\\RequiresGrant]\nenum G{$g}Permission: string\n{\n{$cases}}\n");
            $enums[] = "G{$g}Permission::class";
        }
        file_put_contents("{$dir}/PostPermission.php", $head."#[\\AzGuard\\Permissions\\RequiresGrant]\n#[\\AzGuard\\Permissions\\Resource(model: Post::class)]\nenum PostPermission: string\n{\n    case View = 'posts.view';\n}\n");
        $roleMap = [];
        $roles = [];
        for ($r = 1; $r <= ROLES; $r++) {
            $list = [];
            for ($k = 0; count($list) < ROLE_SIZE; $k++) {
                $list['g'.(($r * 7 + $k) % GROUPS + 1).'.p'.(($k * 13 + $r) % CASES + 1)] = true;
            }
            $roleMap[$r] = array_keys($list);
            $cases = implode(', ', array_map(static function (string $name): string {
                [$g, $p] = explode('.', $name);

                return 'G'.substr($g, 1).'Permission::P'.substr($p, 1);
            }, $roleMap[$r]));
            file_put_contents("{$dir}/R{$r}Role.php", $head."#[\\AzGuard\\Roles\\Attributes\\Role('r{$r}')]\nfinal class R{$r}Role extends \\AzGuard\\Roles\\BaseRole\n{\n    public function permissions(): array\n    {\n        return [{$cases}];\n    }\n}\n");
            $roles[] = "R{$r}Role::class";
        }
        file_put_contents("{$dir}/RolePermissions.php", $head."final class RolePermissions\n{\n    /** @return list<string> */\n    public static function of(int \$role): array\n    {\n        return ".var_export($roleMap, true)."[\$role];\n    }\n}\n");
        file_put_contents("{$dir}/User.php", $head."final class User extends \\Illuminate\\Database\\Eloquent\\Model implements \\AzGuard\\Contracts\\AzGuardSubject\n{\n    use \\AzGuard\\Concerns\\HasAzGuard;\n\n    public \$timestamps = false;\n\n    protected \$guarded = [];\n}\n");
        file_put_contents("{$dir}/Post.php", $head."final class Post extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    public \$timestamps = false;\n\n    protected \$guarded = [];\n}\n");
        file_put_contents("{$dir}/PostPolicy.php", $head.<<<'PHP'
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Kernel\Decision\AccessPredicate;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Policies\Decides;
use Illuminate\Database\Eloquent\Model;

/** Own posts only, in PHP and in SQL. */
final class PostPolicy implements FiltersAccessQueries
{
    #[Decides(PostPermission::View)]
    public function view(Model $user, mixed $post = null): bool
    {
        return $post === null || (string) $post->user_id === (string) $user->getKey();
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): AccessPredicate
    {
        $allow = AccessPredicate::eq('user_id', $request->subject()->id());

        return AccessPredicate::partition($allow, AccessPredicate::not($allow), AccessPredicate::deny());
    }
}

PHP);
        $enumList = implode(', ', $enums);
        $roleList = implode(', ', $roles);
        file_put_contents("{$dir}/BenchPanelProvider.php", $head.<<<PHP
use AzGuard\\Panels\\PanelBuilder;
use AzGuard\\Panels\\PanelProvider;
use AzGuard\\Panels\\PanelRegistry;
use AzGuard\\Policies\\PolicyBinding;
use AzGuard\\Sources\\Database\\DatabaseSource;

final class BenchPanelProvider extends PanelProvider
{
    public static ?string \$cacheStore = null;

    public static function getId(): string
    {
        return 'bench';
    }

    public function panel(PanelBuilder \$panel): PanelBuilder
    {
        return \$panel->for(User::class)->default()
            ->permissions([{$enumList}, PostPermission::class, DatabaseSource::make()])
            ->roles([{$roleList}])
            ->policies([PolicyBinding::for(PostPermission::View, PostPolicy::class)])
            ->cache(store: self::\$cacheStore);
    }

    /** A new registry after a configuration change, as a new deployment would have. */
    public static function rebuild(?string \$cacheStore): void
    {
        self::\$cacheStore = \$cacheStore;
        \$registry = new PanelRegistry(app());
        \$registry->register(self::class);
        \$registry->freeze();
        app()->instance(PanelRegistry::class, \$registry);
        app()->forgetScopedInstances();
    }
}

PHP);
    }
    foreach (glob($dir.'/G*Permission.php') as $file) {
        require_once $file;
    }
    foreach (['PostPermission', 'User', 'Post', 'PostPolicy', 'RolePermissions', 'BenchPanelProvider'] as $class) {
        require_once "{$dir}/{$class}.php";
    }
    foreach (glob($dir.'/R*Role.php') as $file) {
        require_once $file;
    }

    return $dir;
}
