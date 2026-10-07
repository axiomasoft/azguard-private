<?php

declare(strict_types=1);

use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\TokenAbilitiesRestriction;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderGrantSource;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderWorld;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\ServicePrincipal;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(fn () => OrderWorld::seed());
afterEach(fn () => Relation::morphMap([], false));
it('uses token abilities as an AND cap and never as authority', function (bool $granted, bool $cap, DecisionReason $reason): void {
    $restriction = new TokenAbilitiesRestriction($cap ? [OrderPermission::Refund->value] : []);
    OrderWorld::compile([new OrderGrantSource($granted ? [OrderWorld::grant()] : [])], fn (PanelBuilder $p) => $p->restrictions([$restriction]));
    expect(OrderWorld::decide(OrderWorld::request())->reason)->toBe($reason);
})->with([[false, true, DecisionReason::NotGranted], [true, true, DecisionReason::Granted], [true, false, DecisionReason::Restricted]]);
it('caps a policy-only authority without consulting grants', function (): void {
    $source = new OrderGrantSource;
    OrderWorld::compile([$source], fn (PanelBuilder $p) => $p->restrictions([new TokenAbilitiesRestriction([])]));
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly))->reason)->toBe(DecisionReason::Restricted)
        ->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
});
it('requires a separate explicit subject trust mapping for a service principal', function (): void {
    $request = AccessRequest::for(SubjectRef::of('service', 1), PermissionKey::of('orders', OrderPermission::Refund->value));
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()])], fn (PanelBuilder $p) => $p->restrictions([new TokenAbilitiesRestriction([OrderPermission::Refund->value])]));
    expect(fn () => OrderWorld::decide($request))->toThrow(SubjectNotAcceptedException::class);
    $trust = fn (PanelBuilder $p) => $p->for([User::class, ServicePrincipal::class])->restrictions([new TokenAbilitiesRestriction([OrderPermission::Refund->value])]);
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()], onlySubject: SubjectRef::of('user', 1))], $trust);
    expect(OrderWorld::decide($request)->reason)->toBe(DecisionReason::NotGranted);
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()], onlySubject: SubjectRef::of('service', 1))], $trust);
    expect(OrderWorld::decide($request)->allowed())->toBeTrue();
});
