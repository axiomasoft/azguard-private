<?php

declare(strict_types=1);

use AzGuard\Authorization\Visibility;
use AzGuard\AzGuard;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Diagnostics\AzGuardHealthCheck;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\AzGuardPanelProvider;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\Reads;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePolicy;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Database\EditorRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Context\Data\ScopeRef;
use Packman\Doctor\Data\DoctorContext as PackmanDoctorContext;
use Packman\Doctor\Enums\Severity;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

final class PgDocument extends Model
{
    protected $table = 'pg_documents';

    public $timestamps = false;

    protected $guarded = [];
}

final class PgProjectRestriction implements FiltersAccessQueries, Restriction
{
    public function key(): string
    {
        return 'pg_project';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return $request->permission()->local() === DatabasePermission::Edit->value;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return $context->resource()?->getAttribute('project_id') === 7
            ? RestrictionResult::pass()
            : RestrictionResult::deny('wrong_project');
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return $request->permission()->local() === DatabasePermission::Edit->value
            ? P::eq('project_id', 7)
            : P::pass();
    }
}

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    Schema::dropIfExists('pg_documents');
    Schema::dropIfExists('users');
    app(StorageSchema::class)->drop('default');
    Relation::morphMap([], false);
});

it('runs AzGuard core migrations, grants, authorization checks, panel access, visibility, and doctor on PostgreSQL', function (): void {
    expect(Schema::hasTable('azg_permissions'))->toBeTrue()
        ->and(Schema::hasTable('azg_role_grants'))->toBeTrue()
        ->and(Schema::hasTable('azg_permission_grants'))->toBeTrue()
        ->and(Schema::hasTable('azg_panel_state'))->toBeTrue()
        ->and(Schema::hasTable('azg_subject_revisions'))->toBeTrue()
        ->and(Schema::hasTable('azg_storage_state'))->toBeTrue();

    Relation::morphMap([
        'user' => User::class,
        'pg_document' => PgDocument::class,
    ], false);

    Schema::create('users', static function (Blueprint $table): void {
        $table->id();
        $table->string('department')->nullable();
        $table->boolean('is_root')->default(false);
    });
    Schema::create('pg_documents', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('title');
    });

    User::query()->insert(['id' => 1, 'department' => 'legal', 'is_root' => false]);
    User::query()->insert(['id' => 2, 'department' => 'sales', 'is_root' => false]);

    PgDocument::query()->insert([
        ['id' => 101, 'project_id' => 7, 'title' => 'Alpha'],
        ['id' => 102, 'project_id' => 8, 'title' => 'Beta'],
    ]);

    $source = DatabaseSource::make();
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)
            ->default()
            ->entry(DatabasePermission::View)
            ->resourcePrefix(false)
            ->permissions([DatabasePermission::class, $source])
            ->roles([EditorRole::class])
            ->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)])
            ->restrictions([new PgProjectRestriction])
            ->consistency(Reads::Primary),
    ]);
    app()->instance(PanelRegistry::class, $registry);

    $globalScope = AccessScope::in(TenantRef::global());
    $subject = User::query()->findOrFail(1);
    $otherSubject = User::query()->findOrFail(2);

    DatabaseWorld::insert('role', [
        DatabaseWorld::row('role', $globalScope, ['subject_id' => '1', 'role' => 'editor']),
    ]);

    $docAllowed = PgDocument::query()->findOrFail(101);
    $docDenied = PgDocument::query()->findOrFail(102);

    $azguard = app(AzGuard::class);

    expect($azguard->check($subject, DatabasePermission::View))->toBeTrue()
        ->and($azguard->check($subject, DatabasePermission::Edit, $docAllowed))->toBeTrue()
        ->and($azguard->check($subject, DatabasePermission::Edit, $docDenied))->toBeFalse()
        ->and($azguard->check($otherSubject, DatabasePermission::View))->toBeFalse();

    $visibleForAllowed = app(Visibility::class)
        ->visibleTo($registry->get('admin'), PgDocument::query(), $subject, DatabasePermission::Edit->value)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $visibleForDenied = app(Visibility::class)
        ->visibleTo($registry->get('admin'), PgDocument::query(), $otherSubject, DatabasePermission::Edit->value)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($visibleForAllowed)->toBe([101])
        ->and($visibleForDenied)->toBe([]);

    $provider = new AzGuardPanelProvider(app());
    $panelRef = new PanelReference('azguard', 'admin', null);
    expect($provider->access(new PanelContext(new ScopeRef('global'), 'user', '2'), $panelRef)->view)->toBeFalse();

    DatabaseWorld::insert('role', [
        DatabaseWorld::row('role', $globalScope, ['subject_id' => '2', 'role' => 'editor']),
    ]);
    expect($provider->access(new PanelContext(new ScopeRef('global'), 'user', '2'), $panelRef)->view)->toBeTrue();

    $findings = app(AzGuardHealthCheck::class)->run(new PackmanDoctorContext(production: false));
    $errors = array_values(array_map(
        static fn ($f): string => $f->code.': '.$f->message,
        array_filter($findings, static fn ($f): bool => $f->severity === Severity::Error),
    ));
    expect($findings)->not->toBeEmpty()
        ->and($errors)->toBe([]);

    // Exercise write-side role grant & revocation on PostgreSQL and re-verify access check.
    User::query()->insert(['id' => 3, 'department' => 'ops', 'is_root' => false]);
    $userThree = User::query()->findOrFail(3);
    $azguard->actingAs('pg-integration-test', function () use ($azguard, $userThree): void {
        $azguard->panel('admin')->for($userThree)->grantRole('editor');
    });
    expect($azguard->check($userThree, DatabasePermission::View))->toBeTrue();

    $azguard->actingAs('pg-integration-test', function () use ($azguard, $userThree): void {
        $azguard->panel('admin')->for($userThree)->revokeRole('editor');
    });
    expect($azguard->check($userThree, DatabasePermission::View))->toBeFalse();
})->group('pg');
