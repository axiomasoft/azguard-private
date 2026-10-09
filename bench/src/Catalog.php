<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Generates the code of the bench application once per shape and loads it: 1 000 permissions in 50 enums, 40 roles
 * of 50 permissions, the models, a policy with a SQL predicate and two panels (`bench` without tenants, `workspace`
 * with required tenants and membership). Code roles and enum permissions are classes, so the catalog is written as
 * PHP to a temporary directory instead of being committed 100 files at a time.
 */
final class Catalog
{
    public const int GROUPS = 50;

    public const int CASES = 20;

    public const int ROLES = 40;

    public const int ROLE_SIZE = 50;

    public const string NS = 'AzGuardLoad\\';

    /** @var array<int, list<string>>|null */
    private static ?array $roleMap = null;

    public static function load(): void
    {
        $dir = sys_get_temp_dir().'/azguard-load-'.md5(implode(',', [self::GROUPS, self::CASES, self::ROLES, self::ROLE_SIZE, (string) filemtime(__FILE__)]));

        if (! is_file($dir.'/.complete')) {
            self::write($dir);
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            require_once $file;
        }
    }

    /** @return list<string> the permissions of role r{n}, in the order of its declaration */
    public static function rolePermissions(int $role): array
    {
        return self::roleMap()[$role] ?? throw new RuntimeException("No role r{$role}.");
    }

    /** The permission with index $index (0..999): g1.p1, g2.p1, ..., g50.p1, g1.p2, ... */
    public static function name(int $index): string
    {
        return 'g'.($index % self::GROUPS + 1).'.p'.(intdiv($index, self::GROUPS) + 1);
    }

    /**
     * A permission that no role grants and no direct grant of the dataset (indexes below 900) names: the worst case
     * of a check, nothing matches.
     */
    public static function missingPermission(): string
    {
        $granted = array_flip(array_merge(...array_values(self::roleMap())));
        for ($index = self::GROUPS * self::CASES - 1; $index >= 900; $index--) {
            if (! isset($granted[self::name($index)])) {
                return self::name($index);
            }
        }

        throw new RuntimeException('Every permission of the catalog is granted.');
    }

    /** @return class-string */
    public static function class(string $name): string
    {
        $class = self::NS.$name;

        if (! class_exists($class)) {
            throw new RuntimeException("The bench catalog has no class [{$class}]; call Catalog::load() first.");
        }

        return $class;
    }

    /** @return class-string<Model> */
    public static function model(string $name): string
    {
        $class = self::class($name);

        if (! is_subclass_of($class, Model::class)) {
            throw new RuntimeException("[{$class}] is not a model.");
        }

        return $class;
    }

    /** @return array<int, list<string>> */
    private static function roleMap(): array
    {
        if (self::$roleMap === null) {
            self::$roleMap = [];
            for ($r = 1; $r <= self::ROLES; $r++) {
                // 61 is coprime with 1 000: the 50 permissions of a role are distinct; 40 roles cover 713 of them.
                self::$roleMap[$r] = array_map(static fn (int $k): string => self::name(($r * 37 + $k * 61) % (self::GROUPS * self::CASES)), range(0, self::ROLE_SIZE - 1));
            }
        }

        return self::$roleMap;
    }

    private static function write(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create [{$dir}].");
        }
        $head = "<?php\n\ndeclare(strict_types=1);\n\nnamespace AzGuardLoad;\n\n";
        $files = [];
        $enums = [];
        for ($g = 1; $g <= self::GROUPS; $g++) {
            $cases = '';
            for ($c = 1; $c <= self::CASES; $c++) {
                $cases .= "    case P{$c} = 'g{$g}.p{$c}';\n";
            }
            $files["G{$g}Permission"] = "#[\\AzGuard\\Permissions\\RequiresGrant]\nenum G{$g}Permission: string\n{\n{$cases}}\n";
            $enums[] = "G{$g}Permission::class";
        }
        $files['PostPermission'] = "#[\\AzGuard\\Permissions\\RequiresGrant]\n#[\\AzGuard\\Permissions\\Resource(model: Post::class)]\nenum PostPermission: string\n{\n    case View = 'posts.view';\n}\n";
        $files['TaskPermission'] = "#[\\AzGuard\\Permissions\\RequiresGrant]\nenum TaskPermission: string\n{\n    case View = 'tasks.view';\n    case Update = 'tasks.update';\n}\n";
        $roles = [];
        foreach (self::roleMap() as $r => $permissions) {
            $cases = implode(', ', array_map(static function (string $name): string {
                [$g, $p] = explode('.', $name);

                return 'G'.substr($g, 1).'Permission::P'.substr($p, 1);
            }, $permissions));
            $files["R{$r}Role"] = "#[\\AzGuard\\Roles\\Attributes\\Role('r{$r}')]\nfinal class R{$r}Role extends \\AzGuard\\Roles\\BaseRole\n{\n    public function permissions(): array\n    {\n        return [{$cases}];\n    }\n}\n";
            $roles[] = "R{$r}Role::class";
        }
        $files['MemberRole'] = "#[\\AzGuard\\Roles\\Attributes\\Role('member')]\nfinal class MemberRole extends \\AzGuard\\Roles\\BaseRole\n{\n    public function permissions(): array\n    {\n        return [TaskPermission::View];\n    }\n}\n";
        $files['AdminRole'] = "#[\\AzGuard\\Roles\\Attributes\\Role('admin')]\nfinal class AdminRole extends \\AzGuard\\Roles\\BaseRole\n{\n    public function permissions(): array\n    {\n        return [TaskPermission::View, TaskPermission::Update];\n    }\n}\n";
        $files['User'] = "final class User extends \\Illuminate\\Database\\Eloquent\\Model implements \\AzGuard\\Contracts\\AzGuardSubject\n{\n    use \\AzGuard\\Concerns\\HasAzGuard;\n\n    public \$timestamps = false;\n\n    protected \$guarded = [];\n}\n";
        $files['Post'] = "final class Post extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    public \$timestamps = false;\n\n    protected \$guarded = [];\n}\n";
        $files['Team'] = "final class Team extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    public \$timestamps = false;\n\n    protected \$guarded = [];\n}\n";
        $files['TeamMembership'] = <<<'PHP'
use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Support\Facades\DB;

/** Membership is a row of team_user, read on every check as an application would. */
final class TeamMembership implements TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef $tenant): bool
    {
        return DB::table('team_user')->where('user_id', $subject->id())->where('team_id', $tenant->id())->exists();
    }
}

PHP;
        $files['PostPolicy'] = <<<'PHP_WRAP'
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
        
        PHP_WRAP;
        $enumList = implode(', ', $enums);
        $roleList = implode(', ', $roles);
        $files['BenchPanelProvider'] = <<<PHP
use AzGuard\\Panels\\PanelBuilder;
use AzGuard\\Panels\\PanelProvider;
use AzGuard\\Policies\\PolicyBinding;
use AzGuard\\Sources\\Database\\DatabaseSource;

/** The panel without tenants: the large catalog, the post policy and the database source. */
final class BenchPanelProvider extends PanelProvider
{
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
            ->cache(store: config('bench.cache_store'));
    }
}

PHP;
        $files['WorkspacePanelProvider'] = <<<'PHP'
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;

/** The panel with required tenants (teams) and membership read from team_user. */
final class WorkspacePanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'workspace';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)
            ->tenants(TenantPolicy::required(Team::class)->requireMembership(TeamMembership::class))
            ->permissions([TaskPermission::class, DatabaseSource::make()])
            ->roles([MemberRole::class, AdminRole::class])
            ->cache(store: config('bench.cache_store'));
    }
}

PHP;
        foreach ($files as $name => $code) {
            file_put_contents("{$dir}/{$name}.php", $head.$code);
        }
        touch($dir.'/.complete');
    }
}
