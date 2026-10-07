<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderWorld;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\OrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\WorkingHoursPolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;

final class RenamedOrderDecision
{
    #[Decides(OrderPermission::UserOnly)]
    public function arbitraryNewName(User $user): bool
    {
        return $user->getKey() === 1;
    }
}
final class MissingOrderDecision
{
    public function arbitraryNewName(User $user): bool
    {
        return true;
    }
}
function orderBindingsExceptUserOnly(): array
{
    return array_map(fn (OrderPermission $p) => PolicyBinding::for($p, $p === OrderPermission::Refund ? WorkingHoursPolicy::class : OrderPolicy::class), array_values(array_filter(OrderPermission::cases(), fn (OrderPermission $p) => ! in_array($p, [OrderPermission::UserOnly, OrderPermission::Unbound], true))));
}
beforeEach(fn () => OrderWorld::seed());
afterEach(fn () => Relation::morphMap([], false));
it('keeps exact enum and string identities after renaming the attributed method', function (bool $asString): void {
    $target = $asString ? OrderPermission::UserOnly->value : OrderPermission::UserOnly;
    $registry = OrderWorld::compile(configure: fn (PanelBuilder $p) => $p->policies([...orderBindingsExceptUserOnly(), PolicyBinding::for($target, RenamedOrderDecision::class)]), discoverPolicies: false);
    expect($registry->catalog('orders')->bindingMethod(OrderPermission::UserOnly->value))->toBe('arbitraryNewName')
        ->and(OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly))->allowed())->toBeTrue();
})->with([false, true]);
it('rejects a method after Decides is removed rather than guessing its name', function (): void {
    expect(fn () => OrderWorld::compile(configure: fn (PanelBuilder $p) => $p->policies([...orderBindingsExceptUserOnly(), PolicyBinding::for(OrderPermission::UserOnly, MissingOrderDecision::class)]), discoverPolicies: false))->toThrow(DefinitionException::class);
});
