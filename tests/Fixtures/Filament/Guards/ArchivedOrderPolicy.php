<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Illuminate\Database\Eloquent\Model;

/** Decides the policy-only archive: everybody opens it and sees the orders that are not secret. */
final class ArchivedOrderPolicy implements FiltersAccessQueries
{
    #[Decides(PolicyArchivedOrderPermission::ViewAny)]
    public function viewAny(Model $user): bool
    {
        return true;
    }

    #[Decides(PolicyArchivedOrderPermission::View)]
    public function view(Model $user, Order $order): bool
    {
        return ! $order->getAttribute('secret');
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        $allow = $request->permission()->local() === 'archived-orders.view' ? P::eq('secret', false) : P::pass();

        return P::partition($allow, P::not($allow), P::deny());
    }
}
