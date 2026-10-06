<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Visibility\VisibilityBefore;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityRestriction;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

class VisibilityVeto extends VisibilityPolicy
{
    #[Decides('orders.view')]
    public function view(?Model $user, ?Model $resource): ?bool
    {
        return parent::view($user, $resource);
    }
}

class VisibilityDenyBefore extends VisibilityBefore
{
    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return P::beforeResult(BeforeResult::Deny);
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('does not let policy allow or a root role bypass before deny', function (string $permission): void {
    $source = new VisibilitySource(roles: [W::role(key: 'root')]);
    [$visibility, $panel] = W::compile($source, fn (PanelBuilder $panel) => $panel->before([VisibilityDenyBefore::class]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), $permission)->count())->toBe(0);
})->with(['orders.view', 'orders.policy']);

it('compiles before pass and PolicyOnly allow while leaving all assignment sources unread', function (): void {
    $source = new VisibilitySource;
    $source->selection = static fn () => throw new RuntimeException('assignment outage');
    [$visibility, $panel] = W::compile($source, fn (PanelBuilder $panel) => $panel->before([VisibilityBefore::class]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.policy')->orderBy('id')->pluck('id')->all())->toBe([1, 4]);
    expect($source->selectionReads)->toBe(0)->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
});

it('uses policy as a veto and preserves abstain only with a grant', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]), fn (PanelBuilder $panel) => $panel->policies([
        PolicyBinding::for('orders.view', VisibilityVeto::class),
    ]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 3, 4]);
});

it('keeps qualified admin exemptions inside its own complete witness branch', function (): void {
    VisibilityRestriction::$exempt = true;
    $source = new VisibilitySource(direct: [W::grant()], roles: [W::role(key: 'root')]);
    [$visibility, $panel] = W::compile($source, fn (PanelBuilder $panel) => $panel->restrictions([VisibilityRestriction::class])->before([VisibilityBefore::class]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 3, 4]);
    $source->roles = [W::role(key: 'root', expiry: new DateTimeImmutable('2026-10-06 12:00:00 UTC'))];
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 4]);
    VisibilityRestriction::$exempt = false;
    $source->roles = [W::role(key: 'root')];
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 4]);
});
