<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Exercise the same concrete witnesses as the visibility boundary regression tests. */
final class VisibilityBranches extends BaseProfile
{
    private ?PanelRegistry $registry = null;

    /** @var array<string, class-string<Model>> */
    private array $morphMap = [];

    private ?Visibility $visibility = null;

    private ?Panel $panel = null;

    public function id(): string
    {
        return 'visibility-branches';
    }

    public function description(): string
    {
        return 'Exact visibility at the witness budget and over it';
    }

    public function stages(Tier $tier): array
    {
        return array_map(static fn (int $n): Stage => new Stage('branches-'.$n, 1, $tier->name === 'smoke' ? 1 : 5, 0), [999, 1000, 10000]);
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        require_once dirname(__DIR__, 3).'/tests/Fixtures/Visibility/VisibilityWorld.php';

        if ($this->registry === null) {
            $this->registry = app(PanelRegistry::class);
            $this->morphMap = Relation::morphMap();
        }
        Relation::morphMap(['user' => User::class], false);

        if (! Schema::hasTable('visibility_projects')) {
            Schema::create('visibility_projects', static function (Blueprint $table): void {
                $table->id();
                $table->integer('project_id')->nullable();
                $table->string('org');
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->boolean('active')->nullable();
            });
            DB::table('visibility_projects')->insert([
                ['id' => 1, 'org' => 'A', 'city' => 'Paris'],
                ['id' => 4, 'org' => 'B', 'city' => 'Paris'],
            ]);
        }
        $count = (int) substr($stage->name, strlen('branches-'));
        $sourceClass = 'AzGuard\\Tests\\Fixtures\\Visibility\\VisibilitySource';
        $source = new $sourceClass(direct: array_map(static fn (int $id): Grant => VisibilityWorld::grant($id), range(1, $count)));
        [$this->visibility, $this->panel] = VisibilityWorld::compile($source);
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        if ($this->visibility === null || $this->panel === null) {
            throw new RuntimeException('Visibility benchmark was not prepared.');
        }
        $query = VisibilityProject::query()->whereIn('id', [1, 4]);
        $original = $query->toSql();

        try {
            $visible = $this->visibility->visibleTo($this->panel, $query, VisibilityWorld::subject(), 'orders.view');

            if ($stage->name === 'branches-10000') {
                throw new RuntimeException('Over-budget visibility was accepted.');
            }
            $ids = $visible->orderBy('id')->pluck('id')->all();

            if ($ids !== [1, 4]) {
                throw new RuntimeException('Visibility returned incorrect rows.');
            }
            $page = $this->visibility->visibleTo($this->panel, VisibilityProject::query()->whereIn('id', [1, 4]), VisibilityWorld::subject(), 'orders.view')
                ->orderBy('id')->paginate(1, ['*'], 'page', 1);

            $first = $page->items()[0] ?? null;

            if ($page->total() !== 2 || ! $first instanceof Model || $first->getKey() !== 1) {
                throw new RuntimeException('Visibility pagination returned incorrect rows or count.');
            }

            return 'visible';
        } catch (VisibilityNotSupportedException $exception) {
            if ($stage->name !== 'branches-10000' || ! str_contains($exception->getMessage(), 'selection_budget') || $query->toSql() !== $original) {
                throw $exception;
            }

            return 'overlimit';
        }
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        if ($this->registry !== null) {
            app()->instance(PanelRegistry::class, $this->registry);
            app()->forgetInstance(Authorizer::class);
            app()->forgetInstance(Visibility::class);
            Relation::morphMap($this->morphMap, false);
            $this->registry = null;
        }

        return [['name' => 'visibility result and budget', 'ok' => true, 'detail' => 'checked in each iteration']];
    }
}
