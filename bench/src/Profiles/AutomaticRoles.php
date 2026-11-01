<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use RuntimeException;

/** Cost of evaluating different automatic-role and role-permission catalog sizes. */
final class AutomaticRoles extends BaseProfile
{
    public function id(): string
    {
        return 'automatic-roles';
    }

    public function description(): string
    {
        return 'Automatic roles by count and permissions per role';
    }

    public function stages(Tier $tier): array
    {
        $stages = [];
        foreach ([1, 10, 100] as $roles) {
            foreach ([100, 500] as $permissions) {
                $stages[] = new Stage("automatic-{$roles}-{$permissions}", 1, $tier->iterations, $tier->warmup);
            }
        }

        return $stages;
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $stand->newRequest();

        if (! $this->check($this->subject(2, $stage->name), 'g1.p1')) {
            throw new RuntimeException('Automatic role failed to grant its permission.');
        }

        return 'check';
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $stand->newRequest();
        $subject = $this->subject(2, $stage->name);
        $granted = $this->check($subject, 'g1.p1');
        $denied = ! $this->check($subject, 'g50.p20');

        return [['name' => 'automatic grants and denials', 'ok' => $granted && $denied, 'detail' => 'first permission granted, last denied']];
    }
}
