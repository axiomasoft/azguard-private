<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;

/**
 * Multi-tenant checks in the `workspace` panel (required tenants, membership read from team_user): a member asking
 * in its team, an outsider asking in a team it does not belong to, and the admin of the team asking for an update,
 * each in a new request with a random team.
 */
final class MultiTenant extends BaseProfile
{
    private const array OPS = ['member', 'outsider', 'admin_update'];

    public function id(): string
    {
        return 'multi-tenant';
    }

    public function description(): string
    {
        return 'Checks in a random tenant: member, outsider and admin, with membership';
    }

    public function stages(Tier $tier): array
    {
        return $this->ladder($tier, 'tenant');
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $op = self::OPS[$i % count(self::OPS)];
        $team = mt_rand(1, $tier->tenants);
        $members = Dataset::members($tier, $team);
        $stand->newRequest();
        match ($op) {
            'member' => $this->check($this->in($team, $members[mt_rand(1, count($members) - 1)]), 'tasks.view'),
            'outsider' => $this->check($this->in($team, $this->outsider($tier, $members)), 'tasks.view'),
            'admin_update' => $this->check($this->in($team, $members[0]), 'tasks.update'),
        };

        return $op;
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $wrong = 0;
        for ($team = 1; $team <= min(5, $tier->tenants); $team++) {
            $members = Dataset::members($tier, $team);
            $stand->newRequest();
            $wrong += $this->check($this->in($team, $members[1]), 'tasks.view') ? 0 : 1;
            $wrong += $this->check($this->in($team, $members[1]), 'tasks.update') ? 1 : 0;
            $wrong += $this->check($this->in($team, $members[0]), 'tasks.update') ? 0 : 1;
            $wrong += $this->check($this->in($team, $this->outsider($tier, $members)), 'tasks.view') ? 1 : 0;
        }

        return [['name' => 'members, admins and outsiders get their answers', 'ok' => $wrong === 0, 'detail' => "{$wrong} wrong"]];
    }

    private function in(int $team, int $user): SubjectAccess
    {
        return AzGuard::panel('workspace')->inTenant(Dataset::model(Catalog::class('Team'), $team))
            ->for(Dataset::model(Catalog::class('User'), $user));
    }

    /** @param list<int> $members */
    private function outsider(Tier $tier, array $members): int
    {
        do {
            $user = mt_rand(2, $tier->users);
        } while (in_array($user, $members, true));

        return $user;
    }
}
