<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Auth\Access\Response;

final class ClientPolicy implements FiltersAccessQueries
{
    public static bool $unsupported = false;

    public static bool $override = false;

    public static bool|Response|null $result = true;

    #[Decides(ClientPermission::View)]
    public function view(User $user, Client $client): bool|Response|null
    {
        return self::$override ? self::$result : true;
    }

    #[Decides(ClientPermission::Update)]
    public function update(User $user, Client $client): bool|Response|null
    {
        return self::$override ? self::$result : ! $client->getAttribute('do_not_call');
    }

    #[Decides(ClientPermission::ViewOwnProfile)]
    public function own(User $user, Client $client): bool|Response|null
    {
        return self::$override ? self::$result : $client->getAttribute('owner_user_id') === $user->getKey();
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        if (self::$unsupported) {
            return P::unsupported();
        }

        if (self::$override) {
            $result = self::$result instanceof Response ? self::$result->allowed() : self::$result;

            return P::policyResult($result);
        }
        $allow = match ($request->permission()->local()) {
            'clients.update' => P::eq('do_not_call', false),
            'clients.view_own_profile' => P::eq('owner_user_id', $request->subject()->id()),
            default => P::pass(),
        };

        return P::partition($allow, P::not($allow), P::deny());
    }
}
