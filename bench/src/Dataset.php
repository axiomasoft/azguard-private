<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use AzGuard\Facades\AzGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The deterministic dataset of a tier, written through the public API (grants go through the change pipeline, as in
 * an application). User 1 is the heavy subject; every other user holds one or two of the 40 roles, every tenth also
 * `posts.view`; posts belong to users in turn; each team has its members, the first one an admin.
 */
final class Dataset
{
    public const int HEAVY = 1;

    /** @return array<string, int> row counts by table, the digest input of the result */
    public static function seed(Tier $tier): array
    {
        $user = Catalog::class('User');
        $rows = [];
        for ($i = 1; $i <= $tier->users; $i++) {
            $rows[] = ['id' => $i, 'name' => 'u'.$i];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        AzGuard::actingAs('benchmark', static function () use ($tier, $user): void {
            $bench = AzGuard::panel('bench');
            $heavy = self::model($user, self::HEAVY);
            $bench->for($heavy)->grantRole(array_map(static fn (int $r): string => 'r'.$r, range(1, $tier->heavyRoles)));
            $direct = [];
            for ($i = 0; $i < $tier->heavyDirect; $i++) {
                $direct[] = Catalog::name($i);
            }
            $bench->for($heavy)->grantPermission($direct);

            for ($i = 2; $i <= $tier->users; $i++) {
                $subject = $bench->for(self::model($user, $i));
                $roles = array_values(array_unique(['r'.($i % Catalog::ROLES + 1), 'r'.(($i * 7) % Catalog::ROLES + 1)]));
                $subject->grantRole($i % 2 === 0 ? $roles : [$roles[0]]);

                if ($i % 10 === 0) {
                    $subject->grantPermission('posts.view');
                }
            }
        });

        $posts = [];
        for ($i = 0; $i < $tier->posts; $i++) {
            $posts[] = ['user_id' => $i % $tier->users + 1];

            if (count($posts) === 1000) {
                DB::table('posts')->insert($posts);
                $posts = [];
            }
        }

        if ($posts !== []) {
            DB::table('posts')->insert($posts);
        }

        $team = Catalog::class('Team');
        $teams = [];
        $members = [];
        for ($t = 1; $t <= $tier->tenants; $t++) {
            $teams[] = ['id' => $t, 'name' => 't'.$t];
            foreach (self::members($tier, $t) as $member) {
                $members[] = ['team_id' => $t, 'user_id' => $member];
            }
        }
        foreach (array_chunk($teams, 500) as $chunk) {
            DB::table('teams')->insert($chunk);
        }
        foreach (array_chunk($members, 500) as $chunk) {
            DB::table('team_user')->insert($chunk);
        }

        AzGuard::actingAs('benchmark', static function () use ($tier, $user, $team): void {
            for ($t = 1; $t <= $tier->tenants; $t++) {
                $workspace = AzGuard::panel('workspace')->inTenant(self::model($team, $t));
                foreach (self::members($tier, $t) as $k => $member) {
                    $workspace->for(self::model($user, $member))->grantRole($k === 0 ? 'admin' : 'member');
                }
            }
        });

        $counts = [];
        foreach (['users', 'posts', 'teams', 'team_user', 'azg_role_grants', 'azg_permission_grants'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * The members of team $t: distinct users, the same for every run of the tier.
     *
     * @return list<int>
     */
    public static function members(Tier $tier, int $team): array
    {
        $members = [];
        for ($k = 0; count($members) < min($tier->membersPerTenant, $tier->users - 1); $k++) {
            $members[(($team * 13 + $k * 7) % ($tier->users - 1)) + 2] = true;
        }

        return array_keys($members);
    }

    /** @return Builder<Model> a query of the generated model $name */
    public static function query(string $name): Builder
    {
        return self::model(Catalog::class($name), 0)->newQuery();
    }

    /** @param class-string $class */
    public static function model(string $class, int $id): Model
    {
        $model = new $class;

        if (! $model instanceof Model) {
            throw new RuntimeException("[{$class}] is not a model.");
        }
        $model->setRawAttributes(['id' => $id], true);
        $model->exists = true;

        return $model;
    }
}
