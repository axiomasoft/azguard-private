<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantDetails;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
    W::grant($this->panel, 'auditor', 1, null);
    W::grant($this->panel, 'auditor', 1, null, origin: 'import');
    W::grant($this->panel, 'seller', 1, 5);
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('syncs exactly one tenant, context and origin: extra revoked, missing granted, matching untouched', function (): void {
    $before = W::rows();
    $matching = array_values(array_filter($before, fn (array $row): bool => $row['role'] === 'seller' && $row['context_key'] === 'crm.project:5'))[0];
    Carbon::setTestNow('2026-10-06T13:00:00Z');
    $result = W::pipeline()->sync($this->panel, W::tenant(), W::user(1), 'role', [W::role('seller'), W::role('analyst')], W::project(5));

    expect(array_map(fn ($effect) => [$effect->kind, $effect->after?->role?->key() ?? $effect->before?->role?->key()], $result->effects))
        ->toBe([[EffectKind::Created, 'analyst']])
        ->and(W::keys())->toBe([
            'crm.organization:1|seller|1|crm.project:1|manual',
            'crm.organization:1|analyst|1|crm.project:2|manual',
            'crm.organization:2|analyst|1|crm.project:4|manual',
            'crm.organization:1|seller|2|crm.project:2|manual',
            'crm.organization:1|tenant-admin|3|crm.project:1|manual',
            'crm.organization:1|auditor|1|global|manual',
            'crm.organization:1|auditor|1|global|import',
            'crm.organization:1|seller|1|crm.project:5|manual',
            'crm.organization:1|analyst|1|crm.project:5|manual',
        ])
        ->and(array_values(array_filter(W::rows(), fn (array $row): bool => $row['id'] === $matching['id']))[0])->toBe($matching);

    $removal = W::pipeline()->sync($this->panel, W::tenant(), W::user(1), 'role', [], W::project(5));

    expect(array_map(fn ($effect) => $effect->kind, $removal->effects))->toBe([EffectKind::Deleted, EffectKind::Deleted])
        ->and(W::keys())->not->toContain('crm.organization:1|seller|1|crm.project:5|manual')
        ->and(W::keys())->toContain('crm.organization:1|seller|1|crm.project:1|manual', 'crm.organization:1|auditor|1|global|import');
});

it('revokes every context of one tenant and origin with AnyAssignmentScope, never other tenants or origins', function (): void {
    W::grant($this->panel, 'analyst', 1, 5);
    $result = W::revokeEverywhere($this->panel, 'analyst', 1);

    expect($result->effects)->toHaveCount(2)
        ->and(array_map(fn ($effect) => $effect->before?->scope->context->key(), $result->effects))->toBe(['crm.project:2', 'crm.project:5'])
        ->and(W::keys())->toContain('crm.organization:2|analyst|1|crm.project:4|manual')
        ->and(W::revokeEverywhere($this->panel, 'analyst', 1)->status)->toBe(ChangeStatus::Unchanged)
        ->and(W::revokeEverywhere($this->panel, 'auditor', 1, origin: 'import')->effects)->toHaveCount(1)
        ->and(W::keys())->toContain('crm.organization:1|auditor|1|global|manual');
});

it('R28 revokes orphan, expired and inactive grants by their stored scope without live eligibility', function (): void {
    Project::query()->whereKey(5)->update(['is_active' => false]);
    CrmWorld::assign('ghost', 1, 1);
    CrmWorld::assign('seller', 1, 99);
    W::grant($this->panel, 'analyst', 2, 1, until: new DateTimeImmutable('2026-10-06T12:00:30Z'));
    Carbon::setTestNow('2026-10-06T13:00:00Z');

    expect(W::revoke($this->panel, 'seller', 1, 5)->effects[0]->kind)->toBe(EffectKind::Deleted)
        ->and(W::revoke($this->panel, 'ghost', 1, 1)->effects[0]->before?->role?->key())->toBe('ghost')
        ->and(W::revoke($this->panel, 'seller', 1, 99)->effects)->toHaveCount(1)
        ->and(W::revoke($this->panel, 'analyst', 2, 1)->effects)->toHaveCount(1);

    $removedRole = CrmWorld::compile(fn (PanelBuilder $p) => $p->roles([SupportRole::class]));

    expect(fn () => W::revoke($this->panel, 'auditor', 1, null))->toThrow(StaleSelectionException::class, 'another build')
        ->and(W::revoke($removedRole, 'auditor', 1, null)->effects[0]->before?->role?->key())->toBe('auditor')
        ->and(fn () => W::grant($removedRole, 'auditor', 1, null))->toThrow(UnknownRoleException::class);
});

it('revokes by ids only inside the panel, tenant and origin and refuses the whole bulk for one foreign id', function (): void {
    $own = W::grant($this->panel, 'analyst', 1, 5)->record;
    $foreign = W::grant($this->panel, 'analyst', 1, 4, tenant: 2)->record;
    $before = [W::rows(), W::version()];

    expect(fn () => W::pipeline()->revokeIds($this->panel, W::tenant(), 'manual', [$own->id, $foreign->id]))->toThrow(StaleSelectionException::class)
        ->and([W::rows(), W::version()])->toBe($before)
        ->and(W::pipeline()->revokeIds($this->panel, W::tenant(), 'manual', [$own->id, $own->id])->effects)->toHaveCount(1);
});

it('runs one revocation per stored grant through the pipes, and a cancel of any of them cancels all', function (): void {
    W::grant($this->panel, 'analyst', 1, 5);
    $seen = [];
    $panel = W::panel([static function (Change $change, Closure $next) use (&$seen): ChangeResult {
        $seen[] = $change->grantId;

        if ($change->scope->context->key() === 'crm.project:5') {
            $change->cancel('Project 5 is frozen.');
        }

        return $next($change);
    }]);
    $before = W::rows();

    expect(fn () => W::revokeEverywhere($panel, 'analyst', 1))->toThrow(ChangeCancelledException::class)
        ->and($seen)->toHaveCount(2)
        ->and(W::rows())->toBe($before);
});

it('keeps an update to expiry and fields and makes a no-op update Unchanged', function (): void {
    $record = W::grant($this->panel, 'auditor', 2, null)->record;
    $first = W::pipeline()->update($this->panel, W::tenant(), 'manual', $record->id, new GrantDetails(null, ['region' => 'R1']));
    $again = W::pipeline()->update($this->panel, W::tenant(), 'manual', $record->id, new GrantDetails(null, ['region' => 'R1']));

    expect($first->status)->toBe(ChangeStatus::Applied)->and($again->status)->toBe(ChangeStatus::Unchanged)
        ->and($again->record?->fingerprint)->toBe($first->record?->fingerprint);
});

it('makes a role of another kind of sync input an invalid configuration', function (): void {
    expect(fn () => W::pipeline()->sync($this->panel, W::tenant(), W::user(1), 'role', [W::permission('clients.view')], W::project()))
        ->toThrow(InvalidConfigurationException::class);
});
