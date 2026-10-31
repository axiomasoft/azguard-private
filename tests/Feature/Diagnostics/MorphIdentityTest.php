<?php

declare(strict_types=1);

use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\Internal\Checks\MorphIdentity;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelSettings;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Tenancy\ModelTenantDefinition;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class MorphProbeOne extends Model {}
class MorphProbeTwo extends Model {}
class MorphProbeCustom extends Model
{
    public function getMorphClass(): string
    {
        return 'probe';
    }
}
class MorphProbeCollision extends Model
{
    public function getMorphClass(): string
    {
        return 'probe';
    }
}
class MorphHostRecord extends Model
{
    public function owner(): mixed
    {
        return $this->morphTo();
    }
}

beforeEach(function (): void {
    $this->oldMap = Relation::morphMap();
    $this->oldRequired = Relation::requiresMorphMap();
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
});

afterEach(function (): void {
    Relation::morphMap($this->oldMap, false);
    Relation::requireMorphMap($this->oldRequired);
});

function morphProbe(array $models, bool $production = true): array
{
    $settings = new PanelSettings(null, null, 300, 1, Reads::Primary, StateRefresh::Request, false, []);
    $panel = new Panel(id: 'test', label: 'test', default: false, settings: $settings, subjectModels: $models, pluginIds: [], tenantPolicy: null);
    $context = new DoctorContext([$panel], [], $production, app(AzGuardConfig::class));

    return iterator_to_array(app(MorphIdentity::class)->run($context), false);
}

it('warns about unmapped classes only in production and supports explicit strict severity', function (): void {
    expect(morphProbe([MorphProbeOne::class], false))->toBe([])
        ->and(morphProbe([MorphProbeOne::class])[0]->severity->value)->toBe('warning');
    config(['azguard_doctor.strict_morph_identity' => true]);
    expect(morphProbe([MorphProbeOne::class])[0]->severity->value)->toBe('error');
});

it('checks custom aliases, reverse resolution, collisions and alias renames', function (): void {
    Relation::morphMap(['probe' => MorphProbeOne::class, 'other' => MorphProbeTwo::class], false);
    expect(morphProbe([MorphProbeOne::class, MorphProbeTwo::class]))->toBe([])
        ->and(SubjectRef::of('probe', 7)->equals(SubjectRef::of('other', 7)))->toBeFalse()
        ->and(morphProbe([MorphProbeCustom::class]))->toHaveCount(1);
    Relation::morphMap(['renamed' => MorphProbeOne::class], false);
    expect(morphProbe([MorphProbeOne::class]))->toBe([])
        ->and(SubjectRef::of('probe', 7)->equals(SubjectRef::of('renamed', 7)))->toBeFalse();
    Relation::morphMap(['probe' => MorphProbeCustom::class], false);
    expect(morphProbe([MorphProbeCustom::class, MorphProbeCollision::class]))->toHaveCount(1);
});

it('checks declared tenant and context identity types', function (): void {
    Relation::morphMap(['tenant' => MorphProbeOne::class, 'context' => MorphProbeTwo::class], false);
    $definition = new class implements AssignmentScopeDefinition
    {
        public function type(): string
        {
            return 'wrong';
        }

        public function model(): ?string
        {
            return MorphProbeTwo::class;
        }

        public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
        {
            return null;
        }
    };
    $settings = new PanelSettings(null, null, 300, 1, Reads::Primary, StateRefresh::Request, false, []);
    $panel = new Panel(id: 'test', label: 'test', default: false, settings: $settings, subjectModels: [], pluginIds: [],
        tenantPolicy: TenantPolicy::required(ModelTenantDefinition::make(MorphProbeOne::class, 'wrongtenant')),
        scopeDefinitions: ['wrong' => $definition]);
    $context = new DoctorContext([$panel], [], true, app(AzGuardConfig::class));
    $findings = iterator_to_array(app(MorphIdentity::class)->run($context), false);
    expect(array_column(array_map(fn ($finding) => $finding->details, $findings), 'alias'))->toBe(['wrongtenant', 'wrong']);
});

it('does not change the global strict flag or unrelated host polymorphic relations', function (): void {
    $before = Relation::morphMap();
    $host = new MorphHostRecord;
    $host->setAttribute('owner_type', MorphProbeTwo::class);
    $host->setAttribute('owner_id', 7);
    morphProbe([MorphProbeOne::class]);
    expect(Relation::requiresMorphMap())->toBeFalse()
        ->and(Relation::morphMap())->toBe($before)
        ->and($host->owner()->getRelated())->toBeInstanceOf(MorphProbeTwo::class);
    Relation::requireMorphMap();
    morphProbe([MorphProbeOne::class]);
    expect(Relation::requiresMorphMap())->toBeTrue();
});

it('runs through Doctor without database writes or global enforcement', function (): void {
    Relation::morphMap(['user' => User::class], false);
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)]);
    Relation::morphMap([], false);
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $findings = DoctorWorld::only(DoctorWorld::run(production: true), 'morph.identity');
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity->value)->toBe('warning')
        ->and(Relation::requiresMorphMap())->toBeFalse()
        ->and(array_filter($queries, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1))->toBe([]);
});
