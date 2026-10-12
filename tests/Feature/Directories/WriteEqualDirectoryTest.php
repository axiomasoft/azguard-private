<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Directories\PanelDirectories;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\Internal\ScopesOptions;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Scopes\ContextMembership;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * I9: the scope directory of a panel offers only what the final write accepts for the target (the findings F1 and F2
 * of 0.7.0 are the named cases), and it checks the target once per lookup, not once per option.
 */

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** @return list<string> the project ids the panel offers to the target for the analyst role */
function writeEqualOffers(Panel $panel, int $tenant, int $target, int $limit = 10): array
{
    return array_map(static fn ($option): string => (string) $option->scope->id(), (new PanelDirectories($panel, app()))->scopes('crm.project')
        ->search('crm.project', '', Lookups::make($panel, $tenant, $target, AnalystRole::class), $limit));
}

it('F1 offers nothing when the effective mode is None although an earlier layer declared the type, and the write agrees', function (): void {
    expect(writeEqualOffers(W::panel(), 1, 1))->not->toBe([]);
    $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, [])));
    $lookup = Lookups::make($panel, 1, 1, AnalystRole::class);

    expect(writeEqualOffers($panel, 1, 1))->toBe([])
        ->and((new PanelDirectories($panel, app()))->scopes('crm.project')->describe(AssignmentScopeRef::of('crm.project', 1), $lookup))->toBeNull()
        ->and(fn () => W::grant($panel, 'analyst', 1, 1))->toThrow(AssignmentScopeNotAcceptedException::class, 'has no assignment scopes');
});

it('F2 offers nothing in a tenant the target is not a member of, and the write agrees', function (): void {
    $panel = W::panel();
    $lookup = Lookups::make($panel, 2, 2, AnalystRole::class);

    expect(writeEqualOffers($panel, 2, 1))->toBe(['4'])
        ->and(writeEqualOffers($panel, 2, 2))->toBe([])
        ->and((new PanelDirectories($panel, app()))->scopes('crm.project')->describe(AssignmentScopeRef::of('crm.project', 4), $lookup))->toBeNull()
        ->and(fn () => W::grant($panel, 'analyst', 2, 4, tenant: 2))->toThrow(TenantMismatchException::class, 'is not a member of tenant');
});

it('offers only the scopes the target is a member of when the panel requires scope membership, and the write agrees', function (): void {
    $plain = W::panel();
    [$everyone, $member] = [writeEqualOffers($plain, 1, 1), writeEqualOffers($plain, 1, 2)];
    $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->options(new ScopesOptions(membership: new ContextMembership(new Membership(['2'])))));

    expect($everyone)->not->toBe([])
        ->and(writeEqualOffers($panel, 1, 1))->toBe([])
        ->and(writeEqualOffers($panel, 1, 2))->toBe($member)
        ->and(fn () => W::grant($panel, 'analyst', 1, (int) $everyone[0]))->toThrow(AssignmentScopeNotAcceptedException::class, 'is not a member of assignment scope');
});

it('checks the target once per lookup: the number of queries does not grow with the options', function (): void {
    $panel = W::panel();
    $queries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });
    $count = static function (int $limit) use ($panel, &$queries): int {
        $before = $queries;
        writeEqualOffers($panel, 1, 1, $limit);

        return $queries - $before;
    };

    expect(count(writeEqualOffers($panel, 1, 1)))->toBeGreaterThan(1)
        ->and($count(10))->toBe($count(1));
});

it('describes in other phases without the checks of the write', function (): void {
    $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, [])));
    $inspection = Lookups::make($panel, 1, 1, phase: AssignmentScopePhase::Inspection);

    expect((new PanelDirectories($panel, app()))->scopes('crm.project')->describe(AssignmentScopeRef::of('crm.project', 1), $inspection)?->scope->id())->toBe('1');
});
