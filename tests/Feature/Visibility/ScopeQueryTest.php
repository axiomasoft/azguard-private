<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\ContextAware;
use AzGuard\Tests\Fixtures\Scopes\RecordingEvaluator;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

beforeEach(function (): void {
    W::seed();
    RecordingEvaluator::reset();
});
afterEach(function (): void {
    W::reset();
    RecordingEvaluator::reset();
});

/** The reason visibleTo gives on the client list of a panel whose own scope declaration replaces the world's. */
function scopeQueryReason(?string $evaluator, ?Model $model = null): ?string
{
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), static fn (PanelBuilder $p) => $p
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new VisibilityProjectScope], evaluator: $evaluator)), mode: 'none');

    try {
        $visibility->visibleTo($panel, ($model ?? new VisibilityClient)->newQuery(), W::subject(), 'orders.view');
    } catch (VisibilityNotSupportedException $error) {
        return $error->reason;
    }

    return null;
}

it('lists scoped resources only through a queryable evaluator and never approximates without one', function (): void {
    // Without any evaluator the role filters of the world are refused at compilation (nobody would apply them).
    expect(fn () => scopeQueryReason(null))->toThrow(DefinitionException::class, 'without an evaluator that applies them')
        ->and(scopeQueryReason(RecordingEvaluator::class))->toBe('scope_query')
        ->and(RecordingEvaluator::$asked)->toBe([]);
});

it('maps a resource to its context only through MapsAssignmentScope, not by method names', function (): void {
    $unmapped = new class extends Model implements ProvidesAccessScope, ProvidesAssignmentScope
    {
        use ContextAware;

        protected $table = 'visibility_clients';

        public function azguardContextType(): string
        {
            return 'project';
        }

        public function azguardContextRelation(): ?string
        {
            return 'project';
        }

        /** @return BelongsTo<VisibilityProject, $this> */
        public function project(): BelongsTo
        {
            return $this->belongsTo(VisibilityProject::class, 'project_id');
        }

        public function azguardScope(): AccessScope
        {
            return AccessScope::in(TenantRef::global());
        }
    };
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));

    expect(fn () => $visibility->visibleTo($panel, $unmapped->newQuery(), W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'resource_context_mapping')
        ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->pluck('id')->all())->not->toBe([]);
});

it('marks witnesses of every context only when they are tenant-wide', function (): void {
    $global = W::grant();
    $scoped = W::grant(1);

    expect(AssignmentScopeSelection::inEveryContext([$global])->appliesInEveryContext())->toBeTrue()
        ->and(AssignmentScopeSelection::inEveryContext([$global])->isEverywhere())->toBeTrue()
        ->and(AssignmentScopeSelection::everywhere([$global])->appliesInEveryContext())->toBeFalse()
        ->and(fn () => AssignmentScopeSelection::inEveryContext([$scoped]))->toThrow(InvalidSourceContributionException::class, 'tenant-wide');
});
