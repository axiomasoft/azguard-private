<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantDetails;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Carbon;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('inserts a new role grant with one Created effect, one bump and a committed state', function (): void {
    $panel = W::panel();
    $before = W::version();
    $result = W::grant($panel, 'analyst', 2, 1, actor: ActorRef::of('crm.user', 3, 'onboarding'));

    expect($result->status)->toBe(ChangeStatus::Applied)
        ->and($result->committed)->toBeTrue()
        ->and($result->correlationId)->toMatch('/\A[0-9a-z]{26}\z/')
        ->and($result->effects)->toHaveCount(1)
        ->and($result->effects[0]->kind)->toBe(EffectKind::Created)
        ->and($result->effects[0]->type)->toBe(ChangeType::GrantRole)
        ->and($result->effects[0]->before)->toBeNull()
        ->and($result->effects[0]->after)->toBe($result->record)
        ->and($result->record?->role?->full())->toBe('crm:analyst')
        ->and($result->record?->scope->context->key())->toBe('crm.project:1')
        ->and($result->record?->actor)->toEqual(ActorRef::of('crm.user', 3, 'onboarding'))
        ->and($result->record?->createdAt?->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:00:00')
        ->and($result->state->version)->toBe($before + 1)
        ->and(W::version())->toBe($before + 1)
        ->and(W::keys())->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});

it('repeats the same grant as Unchanged without a bump, also for another actor', function (): void {
    $panel = W::panel();
    $first = W::grant($panel, 'analyst', 2, 1, actor: ActorRef::of('crm.user', 3));
    $version = W::version();
    $rows = W::rows();
    $again = W::grant($panel, 'analyst', 2, 1, actor: ActorRef::of('crm.user', 1));

    expect($again->status)->toBe(ChangeStatus::Unchanged)
        ->and($again->effects)->toBe([])
        ->and($again->record?->id)->toBe($first->record?->id)
        ->and($again->state->version)->toBe($version)
        ->and(W::version())->toBe($version)
        ->and(W::rows())->toBe($rows);
});

it('updates expiry and fields of an existing grant as one Updated effect', function (): void {
    $panel = W::panel();
    $first = W::grant($panel, 'analyst', 2, 1);
    Carbon::setTestNow('2026-10-06T12:30:00Z');
    $until = new DateTimeImmutable('2026-12-31T23:59:59.750+03:00');
    $updated = W::grant($panel, 'analyst', 2, 1, until: $until, fields: ['region' => 'R1', 'eligible' => true]);
    $effect = $updated->effects[0];

    expect($updated->status)->toBe(ChangeStatus::Applied)
        ->and($effect->kind)->toBe(EffectKind::Updated)
        ->and($effect->before?->until)->toBeNull()
        ->and($effect->after?->until?->format('Y-m-d H:i:s'))->toBe('2026-12-31 20:59:59')
        ->and($effect->after?->fields)->toBe(['eligible' => true, 'region' => 'R1'])
        ->and($effect->after?->id)->toBe($first->record?->id)
        ->and($effect->after?->updatedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:30:00')
        ->and($effect->after?->fingerprint)->not->toBe($first->record?->fingerprint)
        ->and(W::grant($panel, 'analyst', 2, 1, until: $until, fields: ['eligible' => true, 'region' => 'R1'])->status)->toBe(ChangeStatus::Unchanged);
});

it('deletes on revoke and reports a missing grant as Unchanged', function (): void {
    $panel = W::panel();
    $granted = W::grant($panel, 'analyst', 2, 1);
    $version = W::version();
    $revoked = W::revoke($panel, 'analyst', 2, 1);

    expect($revoked->status)->toBe(ChangeStatus::Applied)
        ->and($revoked->effects[0]->kind)->toBe(EffectKind::Deleted)
        ->and($revoked->effects[0]->before?->id)->toBe($granted->record?->id)
        ->and($revoked->effects[0]->after)->toBeNull()
        ->and(W::version())->toBe($version + 1);

    $missing = W::revoke($panel, 'analyst', 2, 1);

    expect($missing->status)->toBe(ChangeStatus::Unchanged)->and($missing->record)->toBeNull()->and(W::version())->toBe($version + 1);
});

it('grants exact and pattern permissions directly', function (): void {
    $panel = W::panel();
    $exact = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.update'), W::project(2));
    $pattern = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.*'), W::project());

    expect($exact->effects[0]->type)->toBe(ChangeType::GrantPermission)
        ->and($exact->record?->permission?->full())->toBe('crm:clients.update')
        ->and($pattern->record?->permission?->local())->toBe('clients.*')
        ->and(W::keys('permission'))->toBe([
            'crm.organization:1|clients.update|2|crm.project:2|manual',
            'crm.organization:1|clients.*|2|global|manual',
        ]);
});

it('updates a stored grant by id with the expected fingerprint and keeps its identity', function (): void {
    $panel = W::panel();
    $granted = W::grant($panel, 'analyst', 2, 1);
    $id = $granted->record?->id ?? '';
    $result = W::pipeline()->update($panel, W::tenant(), 'manual', $id, new GrantDetails(new DateTimeImmutable('2027-01-01T00:00:00Z'), ['region' => 'R1']),
        $granted->record?->fingerprint);

    expect($result->effects[0]->kind)->toBe(EffectKind::Updated)
        ->and($result->effects[0]->type)->toBe(ChangeType::UpdateGrant)
        ->and($result->record?->id)->toBe($id)
        ->and($result->record?->role)->toEqual(RoleKey::of('crm', 'analyst'))
        ->and($result->record?->scope)->toEqual($granted->record?->scope)
        ->and($result->record?->fields['region'])->toBe('R1')
        ->and(W::pipeline()->update($panel, W::tenant(), 'manual', $id, new GrantDetails(new DateTimeImmutable('2027-01-01T00:00:00Z'), ['region' => 'R1']),
            $result->record?->fingerprint)->status)->toBe(ChangeStatus::Unchanged);
});

it('keeps the actor out of equality but stores it with an effective change', function (): void {
    $panel = W::panel();
    W::grant($panel, 'analyst', 2, 1, actor: ActorRef::system('import'));
    $row = W::rows()[array_key_last(W::rows())];

    expect($row['actor_type'])->toBe(ActorRef::SYSTEM_TYPE)->and($row['actor_id'])->toBeNull()->and($row['actor_reason'])->toBe('import');
});
