<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Roles\SelectsAutomaticRoleScopes;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Testing\FakeSource;
use AzGuard\Tests\Fixtures\Contracts\Probes\RunsAsAProbe;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

#[Role('contract-auto')]
#[NotGrantable]
final class ContractQueryableAutomaticRole extends BaseRole implements GrantedAutomatically, SelectsAutomaticRoleScopes
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [new VisibilityProjectScope];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return $subject->getKey() === 1 && $scope->context->type() === 'project' && $scope->context->id() === '1';
    }

    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array
    {
        return $subject->getKey() === 1 && $contextType === 'project' ? [W::scope(1)] : [];
    }
}

#[Role('contract-unqueryable-auto')]
#[NotGrantable]
final class ContractUnqueryableAutomaticRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [new VisibilityProjectScope];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return $subject->getKey() === 1 && $scope->context->type() === 'project' && $scope->context->id() === '1';
    }
}

final class QueryParityProbe
{
    use RunsAsAProbe;
    use SourceContractTests;

    public function __construct(private readonly string $mode) {}

    protected function azguardSource(): Source
    {
        return new FakeSource;
    }

    protected function azguardQueryParity(): ?array
    {
        $source = new VisibilitySource(direct: [W::grant(1)]);

        if ($this->mode === 'broken') {
            $source->selection = static fn () => AssignmentScopeSelection::in([], []);
        } elseif ($this->mode === 'unsupported' || $this->mode === 'false-unsupported') {
            $source->selection = static fn () => null;
        }

        if ($this->mode === 'database') {
            app(StorageSchema::class)->create('default');
            [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource, extra: [DatabaseSource::make()]);
            AzGuard::panel('admin')->for(W::subject())->grantPermission('orders.view', AssignmentScopeRef::of('project', 1));
        } elseif (in_array($this->mode, ['folder', 'folder-unsupported'], true)) {
            $role = $this->mode === 'folder' ? ContractQueryableAutomaticRole::class : ContractUnqueryableAutomaticRole::class;
            [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource, fn (PanelBuilder $builder) => $builder->roles([$role]));
        } else {
            [$visibility, $panel, $authorizer] = W::compile($source);
        }
        $resources = VisibilityClient::query()->orderBy('id')->get();
        $requests = $resources->map(static fn ($resource) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->all();

        return [
            'supported' => ! in_array($this->mode, ['unsupported', 'false-supported', 'folder-unsupported'], true),
            'expected' => [101 => true, 102 => false, 103 => false, 104 => false],
            'scalar' => function () use ($authorizer, $panel, $requests, $resources): array {
                $out = [];
                foreach ($requests as $i => $request) {
                    $allowed = $authorizer->decide($panel, $request)->allowed();
                    $out[$resources[$i]->id] = $this->mode === 'false-agreement' ? ! $allowed : $allowed;
                }

                return $out;
            },
            'batch' => function () use ($authorizer, $requests, $resources): array {
                $set = $authorizer->decideMany($requests);
                $out = [];
                foreach ($requests as $i => $_) {
                    $allowed = $set->get($i)->allowed();
                    $out[$resources[$i]->id] = $this->mode === 'truthy-batch' ? (int) $allowed : ($this->mode === 'false-agreement' ? ! $allowed : $allowed);
                }

                return $out;
            },
            'visibility' => function () use ($visibility, $panel, $resources): array {
                $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->pluck('id')->all();
                $out = [];
                foreach ($resources as $resource) {
                    $allowed = in_array($resource->id, $ids, true);
                    $out[$resource->id] = $this->mode === 'truthy-visibility' ? (int) $allowed : ($this->mode === 'false-agreement' ? ! $allowed : $allowed);
                }

                return $out;
            },
        ];
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('accepts matching scalar batch and visibility results', function (): void {
    (new QueryParityProbe('supported'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('matches scalar batch and exact visibility for a scoped database grant', function (): void {
    (new QueryParityProbe('database'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('matches scalar batch and exact visibility for a folder automatic role', function (): void {
    (new QueryParityProbe('folder'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('refuses exact visibility for a folder automatic role without a selection adapter', function (): void {
    (new QueryParityProbe('folder-unsupported'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('expires a database grant at the same boundary in scalar batch and visibility', function (): void {
    app(StorageSchema::class)->create('default');
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource, extra: [DatabaseSource::make()]);
    $expiry = Carbon::now('UTC')->addMinute();
    AzGuard::panel('admin')->for(W::subject())->grantPermission('orders.view', AssignmentScopeRef::of('project', 1), $expiry);
    $resource = VisibilityClient::query()->findOrFail(101);
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource);

    expect($authorizer->decide($panel, $request)->allowed())->toBeTrue()
        ->and($authorizer->decideMany([$request])->get(0)->allowed())->toBeTrue()
        ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([101]);
    Carbon::setTestNow($expiry);
    expect($authorizer->decide($panel, $request)->allowed())->toBeFalse()
        ->and($authorizer->decideMany([$request])->get(0)->allowed())->toBeFalse()
        ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([]);
});

it('isolates a database grant from other tenant and context resources in scalar batch and visibility', function (): void {
    app(StorageSchema::class)->create('default');
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource, tenant: true, extra: [DatabaseSource::make()]);
    AzGuard::panel('admin')->inTenant(W::tenant('A'))->for(W::subject())->grantPermission('orders.view', AssignmentScopeRef::of('project', 1));
    $resources = VisibilityClient::query()->orderBy('id')->get();
    $requests = $resources->map(static fn ($resource) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))
        ->on(null, $resource)->inTenant(W::tenant('A')))->all();
    $batch = $authorizer->decideMany($requests);
    foreach ($requests as $index => $request) {
        $allowed = $index === 0;
        expect($authorizer->decide($panel, $request)->allowed())->toBe($allowed)
            ->and($batch->get($index)->allowed())->toBe($allowed);
    }
    expect($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->pluck('id')->all())->toBe([101]);
});

it('applies a tenant-wide database grant according to context inheritance without crossing tenants', function (string $mode, array $expected): void {
    app(StorageSchema::class)->create('default');
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource, mode: $mode, tenant: true, extra: [DatabaseSource::make()]);
    AzGuard::panel('admin')->inTenant(W::tenant('A'))->for(W::subject())->grantPermission('orders.view');
    $resources = VisibilityClient::query()->orderBy('id')->get();
    $requests = $resources->map(static fn ($resource) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))
        ->on(null, $resource)->inTenant(W::tenant('A')))->all();
    $batch = $authorizer->decideMany($requests);
    foreach ($requests as $index => $request) {
        $allowed = in_array($resources[$index]->id, $expected, true);
        expect($authorizer->decide($panel, $request)->allowed())->toBe($allowed)
            ->and($batch->get($index)->allowed())->toBe($allowed);
    }
    expect($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all())->toBe($expected);
})->with([
    'inherited' => ['inherit', [101, 102, 103]],
    'isolated' => ['isolated', []],
]);

it('denies a database grant when tenant membership is absent in scalar batch and visibility', function (): void {
    app(StorageSchema::class)->create('default');
    $membership = new Membership;
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource,
        fn (PanelBuilder $builder) => $builder->tenants(TenantPolicy::required(Organization::class)->requireMembership($membership)),
        tenant: true, extra: [DatabaseSource::make()]);
    AzGuard::panel('admin')->inTenant(W::tenant('A'))->for(W::subject())->grantPermission('orders.view', AssignmentScopeRef::of('project', 1));
    $membership->members = [];
    $resource = VisibilityClient::query()->findOrFail(101);
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource)->inTenant(W::tenant('A'));

    expect($authorizer->decide($panel, $request)->allowed())->toBeFalse()
        ->and($authorizer->decideMany([$request])->get(0)->allowed())->toBeFalse()
        ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->pluck('id')->all())->toBe([]);
});

it('rejects an incomplete source query filter', function (): void {
    expect(fn () => (new QueryParityProbe('broken'))->run('sourceVisibilityMatchesScalarAndBatch'))
        ->toThrow(AssertionFailedError::class, 'Visibility differs from scalar decisions.');
});

it('runs a supplied parity fixture through the ordinary source contract', function (): void {
    expect(fn () => (new QueryParityProbe('broken'))->run('describeMatchesWhatTheSourceGives'))
        ->toThrow(AssertionFailedError::class, 'Visibility differs from scalar decisions.');
});

it('accepts a typed unsupported visibility result', function (): void {
    (new QueryParityProbe('unsupported'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('rejects a mismatch between visibility support and the runtime result', function (string $mode): void {
    expect(fn () => (new QueryParityProbe($mode))->run('sourceVisibilityMatchesScalarAndBatch'))
        ->toThrow(AssertionFailedError::class);
})->with(['false-supported', 'false-unsupported']);

it('rejects truthy non-boolean batch and visibility results', function (string $mode): void {
    expect(fn () => (new QueryParityProbe($mode))->run('sourceVisibilityMatchesScalarAndBatch'))
        ->toThrow(AssertionFailedError::class);
})->with(['truthy-batch', 'truthy-visibility']);

it('rejects matching scalar batch and visibility results that contradict the independent oracle', function (): void {
    expect(fn () => (new QueryParityProbe('false-agreement'))->run('sourceVisibilityMatchesScalarAndBatch'))
        ->toThrow(AssertionFailedError::class, 'Scalar decisions differ from the expected authority.');
});

it('fails every decision in an affected batch if a source yields an invalid or incomplete contribution', function (string $failure): void {
    $source = new VisibilitySource(read: static function () use ($failure): iterable {
        yield W::grant(1);

        if ($failure === 'timeout') {
            throw new RuntimeException('source timeout');
        }
        yield 'invalid';
    });
    [, $panel, $authorizer] = W::compile($source);
    $resources = VisibilityClient::query()->orderBy('id')->limit(2)->get();
    $requests = $resources->map(static fn ($resource) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->all();

    foreach ($authorizer->decideMany($requests) as $decision) {
        expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError);
    }
})->with(['timeout', 'invalid']);
