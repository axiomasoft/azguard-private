<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityCondition;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('applies one grouped final constraint before count and pagination while preserving host filters', function (): void {
    $source = new VisibilitySource(direct: [W::grant(1), W::grant(2)]);
    [$visibility, $panel, $authorizer] = W::compile($source);
    $host = VisibilityClient::query()->where(fn (Builder $query) => $query->where('city', 'Paris')->orWhere('city', 'Rome'))->orderBy('id');
    $query = $authorizer->visibleTo($panel, $host, W::subject(), 'orders.view');
    expect($query)->toBe($host)->and($query->count())->toBe(2)
        ->and((clone $query)->paginate(1)->total())->toBe(2)
        ->and($query->pluck('id')->all())->toBe([101, 102]);
});

it('preserves literal scalar parity for disjoint scoped witnesses and an unassigned resource', function (): void {
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant(1), W::grant(2)]));
    $expected = [];
    foreach (VisibilityClient::query()->orderBy('id')->get() as $resource) {
        $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource);

        if ($authorizer->decide($panel, $request)->allowed()) {
            $expected[] = $resource->id;
        }
    }
    expect($expected)->toBe([101, 102])->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe($expected);
});

it('keeps the authoritative tenant owner for tenant-wide grants and relation resources', function (): void {
    W::$tenanted = true;
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]), tenant: true);
    expect($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all())->toBe([101, 102, 103]);
});

it('never inherits a tenant-wide assignment into isolated contexts', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(), W::grant(2)]), mode: 'isolated');
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([2]);
});

it('uses one fixed now and preserves conditions on individual witnesses', function (): void {
    $source = new VisibilitySource(direct: [W::grant(1, ['city' => 'Rome']), W::grant(1, ['city' => 'Paris']), W::grant(2, ['city' => 'Paris']), W::grant(3, expiry: new DateTimeImmutable('2026-10-06 12:00:00 UTC'))]);
    [$visibility, $panel] = W::compile($source, fn (PanelBuilder $panel) => $panel->grantConditions([VisibilityCondition::class]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([1]);
    expect(VisibilityCondition::$times)->toHaveCount(3);
    foreach (VisibilityCondition::$times as $now) {
        expect($now)->toBe(VisibilityCondition::$times[0]);
    }
});

it('applies native common and role filters as separate AND groups per concrete runtime', function (): void {
    $source = new VisibilitySource(roles: [W::role(1, ['city' => 'Rome']), W::role(1, ['city' => 'Paris']), W::role(2, ['city' => 'Rome'])]);
    [$visibility, $panel] = W::compile($source, fn (PanelBuilder $panel) => $panel->scopes(AssignmentScopePolicy::inherit(
        (new VisibilityProjectScope)->filter(static fn (Builder $query, $runtime) => $query->whereKey($runtime->scope->context->id())->where('active', true)),
    )));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([1]);
});

it('throws before resource execution without partially mutating the caller when an adapter is missing', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), fn (PanelBuilder $panel) => $panel->before([static fn () => BeforeResult::Continue]));
    $query = VisibilityClient::query()->where('city', 'Paris');
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    DB::connection()->enableQueryLog();
    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class);
    expect($query->toSql())->toBe($sql)->and($query->getBindings())->toBe($bindings);
    foreach (DB::connection()->getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain('visibility_clients');
    }
});

it('rejects an ungrouped raw context relation before it can widen scoped visibility', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $model = new class extends VisibilityClient
    {
        public function project(): BelongsTo
        {
            return parent::project()->whereRaw('active = ? or active = ?', [true, false]);
        }
    };
    $query = $model->newQuery();
    $sql = $query->toSql();

    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class)
        ->and($query->toSql())->toBe($sql);
});
