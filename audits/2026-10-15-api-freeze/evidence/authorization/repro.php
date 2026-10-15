<?php

declare(strict_types=1);

// Standalone audit evidence: every run uses a fresh SQLite in-memory Testbench app.
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'AUTHORITY_REPLICA_TEST' => ''] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require dirname(__DIR__, 4).'/vendor/autoload.php';

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Context\AssignmentScopeDescription;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

$app = (new TestCase('audit'))->createApplication();
$connection = $app['db']->connection();
if ($app['config']->get('app.env') !== 'testing' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Audit requires testing / sqlite / :memory: before any write.');
}
$databaseFiles = $connection->select('PRAGMA database_list');
if ($databaseFiles[0]->file !== '') {
    throw new RuntimeException('Audit connected to a persistent SQLite file.');
}
echo json_encode(['isolation' => ['environment' => $app['config']->get('app.env'), 'driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName(), 'sqlite_file' => $databaseFiles[0]->file]], JSON_THROW_ON_ERROR).PHP_EOL;
W::seed();

// A restriction derived purely from the advertised EvaluationContext input.
final class RestrictedGrantRule implements Restriction, FiltersAccessQueries
{
    public function key(): string { return 'restricted-grant'; }
    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool { return true; }
    public function exemptsSuperAdmin(): bool { return false; }
    private function allowed(EvaluationContext $context): bool
    {
        foreach ($context->matchingGrants() as $grant) {
            if ($grant->fields()['restricted'] ?? false) {
                return false;
            }
        }
        return true;
    }
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return $this->allowed($context) ? RestrictionResult::pass() : RestrictionResult::deny('restricted_grant');
    }
    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return $this->allowed($context) ? P::pass() : P::deny();
    }
}

final class NumericFieldEvaluator implements \AzGuard\Contracts\Scopes\AssignmentScopeEvaluator
{
    public function validate(\AzGuard\Contracts\Scopes\AssignmentScopeDefinition $definition, ?\AzGuard\Contracts\Scopes\AssignmentScopeDefinition $registered): void {}
    public function allows(\AzGuard\Context\AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        return $runtime->grant === null || is_int($runtime->grant->fields()['score']);
    }
    public function allowsMany(array $checks): array
    {
        return array_map(fn (array $check): bool => $this->allows(...$check), $checks);
    }
}

$scenario = $argv[1] ?? 'matching-grants';
if ($scenario === 'baseline') {
    [$visibility, $panel, $engine] = W::compile(new VisibilitySource(direct: [W::grant()]));
} elseif ($scenario === 'matching-grants') {
    [$visibility, $panel, $engine] = W::compile(new VisibilitySource(direct: [W::grant(fields: ['restricted' => true])]),
        configure: fn (PanelBuilder $builder) => $builder->restrictions([new RestrictedGrantRule]));
} elseif ($scenario === 'batch-fields') {
    [$visibility, $panel, $engine] = W::compile(new VisibilitySource(direct: [W::grant(fields: ['binary' => "\xB1"])]));
} elseif ($scenario === 'batch-numbers') {
    $source = new VisibilitySource(read: static fn ($subject, $scopes, $context): array => [W::grant(1, ['score' => $context->resource()->score])]);
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $builder) => $builder
        ->for(User::class)->permissions([$source])->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])
        ->assignmentScopes(new \AzGuard\Context\AssignmentScopeDeclaration(\AzGuard\Context\AssignmentScopeMode::Inherit,
            [new \AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope], evaluator: NumericFieldEvaluator::class))]);
    $app->instance(PanelRegistry::class, $registry);
    $panel = $registry->get('admin');
    $engine = $app->make(Authorizer::class);
    $requests = array_map(static fn ($score) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))
        ->on(AssignmentScopeRef::of('project', 1), (object) ['score' => $score]), [1, 1.0]);
    $scalar = array_map(static fn ($request) => $engine->decide($panel, $request)->reason->value, $requests);
    $batch = array_map(static fn ($decision) => $decision->reason->value, iterator_to_array($engine->decideMany($requests)));
    echo json_encode(['scenario' => $scenario, 'field_types' => ['int', 'float'], 'scalar' => $scalar, 'batch' => $batch,
        'same_json' => json_encode(['score' => 1]) === json_encode(['score' => 1.0])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    W::reset();
    exit;
} elseif ($scenario === 'given-model') {
    [$engine, $panel] = \AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld::compile(
        new \AzGuard\Tests\Fixtures\Authorization\GeneratedSource, rootRole: RootRole::class);
    \Illuminate\Support\Facades\Schema::table('users', static fn ($table) => $table->boolean('is_root')->default(false));
    $observe = static function (User $user) use ($panel): array {
        $access = new SubjectAccess($panel, $user);
        return ['given_is_root' => $user->getAttribute('is_root'), 'stored_is_root' => User::query()->findOrFail(1)->getAttribute('is_root'),
            'decide' => $access->decide('orders.view')->reason->value,
            'isSuperAdmin' => $access->isSuperAdmin(), 'hasRole' => $access->hasRole('root'),
            'roleNames' => $access->roleNames()->all(), 'permissionSet' => count($access->permissionSet()->patterns())];
    };
    $user = User::query()->findOrFail(1);
    $user->setAttribute('is_root', true);
    $heldTrue = $observe($user);
    \Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['is_root' => true]);
    $user = User::query()->findOrFail(1);
    $user->setAttribute('is_root', false);
    echo json_encode(['scenario' => $scenario, 'held_true_database_false' => $heldTrue, 'held_false_database_true' => $observe($user),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    W::reset();
    exit;
} elseif (in_array($scenario, ['structural-resolver', 'directory-resolver'], true)) {
    $definition = new class extends BaseAssignmentScope {
        public function type(): string { return 'project'; }
        public function query(): Builder { return VisibilityProject::query(); }
        public function tenantOf(Model $record): TenantRef { return TenantRef::global(); }
        public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
        {
            return $ref->id() === '1' ? null : parent::resolve($ref);
        }
        public function description(): AssignmentScopeDescription
        {
            return new AssignmentScopeDescription(tenantOwner: false, resolvesThroughQuery: false);
        }
    };
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $builder) => $builder
        ->for(User::class)->permissions([new VisibilitySource(direct: [W::grant()])])
        ->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])
        ->plugins([ScopesPlugin::inherit($definition)])]);
    $app->instance(PanelRegistry::class, $registry);
    $panel = $registry->get('admin');
    $visibility = $app->make(Visibility::class);
    $engine = $app->make(Authorizer::class);
    if ($scenario === 'directory-resolver') {
        $lookup = new \AzGuard\Directories\LookupContext($panel, W::scope(), null, null, W::subject(), User::query()->findOrFail(1), null, [],
            \AzGuard\Context\AssignmentScopePhase::Assignment, new DateTimeImmutable('2026-10-06 12:00:00 UTC'));
        $directory = (new \AzGuard\Directories\PanelDirectories($panel, $app))->scopes('project');
        $options = $directory->search('project', '', $lookup, 10);
        echo json_encode(['scenario' => $scenario, 'offered' => array_map(static fn ($option) => $option->scope->key(), $options),
            'describe' => array_map(static fn ($option) => $directory->describe($option->scope, $lookup)?->scope->key(), $options),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        W::reset();
        exit;
    }
} else {
    throw new RuntimeException('Unknown scenario');
}

$scalar = [];
$scalarAllowed = [];
$reasons = [];
$requests = [];
foreach (VisibilityClient::query()->orderBy('id')->get() as $resource) {
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource);
    $requests[] = $request;
    $decision = $engine->decide($panel, $request);
    $scalarAllowed[] = $decision->allowed();
    $reasons[$resource->id] = $decision->reason->value;
    if ($decision->allowed()) {
        $scalar[] = $resource->id;
    }
}
$visible = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();
$batch = $engine->decideMany($requests);
$batchAllowed = array_map(static fn ($decision) => $decision->allowed(), iterator_to_array($batch));
echo json_encode(['scenario' => $scenario, 'scalar' => $scalar, 'scalar_reasons' => $reasons,
    'batch_reasons' => array_map(static fn ($decision) => $decision->reason->value, iterator_to_array($batch)),
    'visibleTo' => $visible, 'scalar_visible_parity' => $scalar === $visible, 'scalar_batch_parity' => $scalarAllowed === $batchAllowed], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
W::reset();
