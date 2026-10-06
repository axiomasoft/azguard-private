<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Visibility\VisibilityCondition;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

class VisibilityChangingSource extends VisibilitySource implements FencesReads
{
    public int $version = 0;

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        return StateToken::of('test', $panel->id(), 'inc', ++$this->version, 1, 'fingerprint');
    }
}

class VisibilityInapplicableRestriction implements Restriction
{
    public function key(): string
    {
        return 'unrelated';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return false;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        throw new RuntimeException('not applicable');
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}

class VisibilityUnsupportedCondition extends VisibilityCondition
{
    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return P::unsupported();
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

class VisibilityOnlyAssignments extends VisibilitySource
{
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        return [];
    }
}

it('rejects an unsupported, throwing or malformed source after another source allows, with safe diagnostics', function (string $failure): void {
    $bad = new VisibilityOnlyAssignments(name: 'bad');
    $bad->selection = match ($failure) {
        'null' => static fn () => null,
        'throws' => static function () {
            yield 1;

            throw new RuntimeException('secret-source-password');
        },
        'malformed' => static fn () => AssignmentScopeSelection::everywhere([W::grant(source: 'generated')]),
    };
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), extra: [$bad]);
    $query = VisibilityProject::query();
    $sql = $query->toSql();

    try {
        $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
        $this->fail('Partial source visibility must not succeed.');
    } catch (VisibilityNotSupportedException $error) {
        expect($error->getMessage())->not->toContain('secret-source-password')->and($error->getPrevious())->toBeNull();
    }
    expect($query->toSql())->toBe($sql);
})->with(['null', 'throws', 'malformed']);

it('retries the entire consumed fence at the same now and fails closed after all changes', function (): void {
    $source = new VisibilityChangingSource(direct: [W::grant(1)]);
    [$visibility, $panel] = W::compile($source);
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class, 'consistency_error');
    expect($source->selectionReads)->toBe(3)->and($source->version)->toBe(6);
});

it('passes a deterministically irrelevant restriction without an adapter', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), fn (PanelBuilder $panel) => $panel->restrictions([VisibilityInapplicableRestriction::class]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([1]);
});

it('rejects unsupported conditions before host aggregation', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), fn (PanelBuilder $panel) => $panel->grantConditions([VisibilityUnsupportedCondition::class]));
    DB::connection()->enableQueryLog();
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class);
    foreach (DB::connection()->getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain('count(');
    }
});

it('rejects an explicitly unsupported scope instead of widening the query', function (): void {
    [$visibility, $panel] = W::compile();
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('foreign', 1))))->toThrow(VisibilityNotSupportedException::class);
});

it('never silently omits arbitrary automatic folder roles', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource, fn (PanelBuilder $panel) => $panel->roles([VisibilityAutomaticRole::class]));
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class, 'unsupported_selection');
});

#[Role('automatic')]
class VisibilityAutomaticRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return true;
    }
}
