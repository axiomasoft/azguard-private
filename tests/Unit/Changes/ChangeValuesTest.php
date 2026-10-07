<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeContext;
use AzGuard\Changes\ChangeEffect;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\GrantRecord;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\AssignmentScopePhase;

function unitScope(): AccessScope
{
    return AccessScope::in(TenantRef::of('crm.organization', 1));
}

function unitRecord(string $id = 'role:1'): GrantRecord
{
    return new GrantRecord($id, 'crm', unitScope(), SubjectRef::of('crm.user', 1), RoleKey::of('crm', 'seller'), null, 'manual', null, [],
        null, null, null, str_repeat('a', 64));
}

function unitToken(int $version = 3): StateToken
{
    return StateToken::of('default', 'crm', '01j0000000000000000000000a', $version, 0, 'fingerprint');
}

it('keeps the change types and their phases', function (): void {
    expect(array_map(fn (ChangeType $type): string => $type->value, ChangeType::cases()))
        ->toBe(['grant_role', 'revoke_role', 'grant_permission', 'revoke_permission', 'update_grant'])
        ->and(array_map(fn (ChangeType $type): array => [$type->isGrant(), $type->isRevocation(), $type->proposes(), $type->phase()], ChangeType::cases()))->toBe([
            [true, false, true, AssignmentScopePhase::Assignment],
            [false, true, false, AssignmentScopePhase::Revocation],
            [true, false, true, AssignmentScopePhase::Assignment],
            [false, true, false, AssignmentScopePhase::Revocation],
            [false, false, true, AssignmentScopePhase::Assignment],
        ])
        ->and(ChangeStatus::cases())->toHaveCount(2)
        ->and(array_map(fn (EffectKind $kind): string => $kind->value, EffectKind::cases()))->toBe(['created', 'updated', 'deleted']);
});

it('derives with* copies without changing identity and refuses them for a revocation', function (): void {
    $grant = Change::grant('crm', unitScope(), SubjectRef::of('crm.user', 1), RoleKey::of('crm', 'seller'), 'manual', ActorRef::of('crm.user', 2));
    $until = new DateTimeImmutable('2027-01-01T00:00:00Z');
    $later = $grant->withUntil($until)->withFields(['region' => 'R1']);
    $revoke = Change::revoke('crm', unitScope(), SubjectRef::of('crm.user', 1), RoleKey::of('crm', 'seller'), 'manual', null, 'role:1');

    expect($later)->not->toBe($grant)
        ->and($later->until)->toBe($until)->and($later->fields)->toBe(['region' => 'R1'])
        ->and($grant->until)->toBeNull()->and($grant->fields)->toBe([])
        ->and($later->sameIdentity($grant))->toBeTrue()
        ->and($later->isFinal())->toBeFalse()->and($later->finalized()->isFinal())->toBeFalse()
        ->and($revoke->grantId)->toBe('role:1')
        ->and(fn () => $revoke->withFields([]))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $revoke->withUntil(null))->toThrow(InvalidConfigurationException::class)
        ->and((new ReflectionClass(Change::class))->isReadOnly())->toBeTrue();
});

it('has no context outside the pipeline and cancels with a reason', function (): void {
    $change = Change::grant('crm', unitScope(), SubjectRef::of('crm.user', 1), PermissionPattern::of('crm', 'clients.*'), 'manual', null);

    expect(fn () => $change->context())->toThrow(InvalidConfigurationException::class)
        ->and($change->correlationId())->toBeNull()
        ->and($change->type)->toBe(ChangeType::GrantPermission)
        ->and($change->isRole())->toBeFalse()
        ->and(fn () => $change->cancel('nope'))->toThrow(ChangeCancelledException::class, 'nope');
});

it('rejects a change shape that cannot name one stored grant', function (Closure $make, string $exception): void {
    expect($make)->toThrow($exception);
})->with([
    'foreign panel key' => [fn () => Change::grant('crm', unitScope(), SubjectRef::of('crm.user', 1), RoleKey::of('admin', 'seller'), 'manual', null), InvalidIdentityException::class],
    'unnamed fields' => [fn () => Change::grant('crm', unitScope(), SubjectRef::of('crm.user', 1), RoleKey::of('crm', 'seller'), 'manual', null, null, ['x']), InvalidChangeFieldsException::class],
    'invalid details' => [fn () => new GrantDetails(null, [1 => 'x']), InvalidChangeFieldsException::class],
]);

it('plans an update from the stored grant with immutable selection inputs', function (): void {
    $change = Change::update(unitRecord(), null, new GrantDetails(new DateTimeImmutable('2027-01-01T00:00:00Z'), ['region' => 'R1']), 'abc');

    expect($change->type)->toBe(ChangeType::UpdateGrant)
        ->and($change->grantId)->toBe('role:1')->and($change->expectedFingerprint)->toBe('abc')
        ->and($change->role?->key())->toBe('seller')->and($change->fields)->toBe(['region' => 'R1']);
});

it('derives the status of a result from writer effects and combines operation results in order', function (): void {
    $record = unitRecord();
    $effect = new ChangeEffect(EffectKind::Created, ChangeType::GrantRole, null, $record, 'event');
    $applied = ChangeResult::written($record, [$effect], unitToken(4), 'corr');
    $unchanged = ChangeResult::written(unitRecord('role:2'), [], unitToken(3), 'corr');
    $combined = ChangeResult::combine([$unchanged, $applied], unitToken(4), true, 'corr');

    expect($applied->status)->toBe(ChangeStatus::Applied)->and($applied->applied())->toBeTrue()->and($applied->committed)->toBeFalse()
        ->and($unchanged->status)->toBe(ChangeStatus::Unchanged)
        ->and($combined->status)->toBe(ChangeStatus::Applied)
        ->and($combined->record?->id)->toBe('role:2')
        ->and(array_map(fn (GrantRecord $r): string => $r->id, $combined->records))->toBe(['role:2', 'role:1'])
        ->and($combined->effects)->toBe([$effect])
        ->and($combined->committed)->toBeTrue()
        ->and(ChangeResult::combine([], unitToken(), false, 'corr')->status)->toBe(ChangeStatus::Unchanged)
        ->and((new ReflectionClass(ChangeResult::class))->getConstructor()?->isPrivate())->toBeTrue();
});

it('keeps every change value readonly', function (string $class): void {
    expect((new ReflectionClass($class))->isReadOnly())->toBeTrue()->and((new ReflectionClass($class))->isFinal())->toBeTrue();
})->with([Change::class, ChangeContext::class, ChangeResult::class, ChangeEffect::class, GrantRecord::class, GrantDetails::class]);
