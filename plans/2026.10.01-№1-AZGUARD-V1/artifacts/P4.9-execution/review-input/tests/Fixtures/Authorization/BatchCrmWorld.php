<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Project;

final class BatchCrmWorld
{
    public static function request(Client $client, ClientPermission $action = ClientPermission::View, string $panel = 'crm', int $user = 1): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of($panel, $action->value))
            ->inTenant(CrmWorld::scope((int) $client->getAttribute('organization_id'))->tenant)->on(null, $client);
    }

    /** @return list<int> Literal fixture rules, independent of the authorization engine. */
    public static function scale(int $resources, int $contexts): array
    {
        CrmWorld::clear();
        $projects = [];

        for ($offset = 0; $offset < $contexts; $offset++) {
            $id = 100 + $offset;
            $projects[] = ['id' => $id, 'organization_id' => 1, 'city_id' => $offset % 2 + 1, 'region' => 'R1', 'is_active' => $offset % 5 !== 0];
        }
        Project::query()->insert($projects);
        // Both roles cover every context: seller checks city, analyst adds the other city's projects.
        CrmWorld::storage()->mutate('crm', static function ($mutation) use ($projects): void {
            $rows = [];

            foreach ($projects as $project) {
                $scope = CrmWorld::scope(1, $project['id']);

                foreach (['seller', 'analyst'] as $role) {
                    $rows[] = [
                        'panel' => 'crm', 'tenant_key' => $scope->tenant->key(), 'tenant_type' => $scope->tenant->type(), 'tenant_id' => $scope->tenant->id(),
                        'context_key' => $scope->context->key(), 'context_type' => $scope->context->type(), 'context_id' => $scope->context->id(),
                        'subject_type' => 'crm.user', 'subject_id' => '1', 'role' => $role, 'origin' => 'manual',
                    ];
                }
            }
            $mutation->table('role_grants')->insert($rows);
            $mutation->touch('crm');
        });
        $clients = $allowed = [];

        for ($offset = 0; $offset < $resources; $offset++) {
            $context = $offset % $contexts;
            $id = 1000 + $offset;
            $clients[] = ['id' => $id, 'organization_id' => 1, 'project_id' => 100 + $context, 'do_not_call' => false, 'owner_user_id' => 1];

            if ($context % 5 !== 0) {
                $allowed[] = $id;
            }
        }

        foreach (array_chunk($clients, 500) as $chunk) {
            Client::query()->insert($chunk);
        }

        return $allowed;
    }
}
