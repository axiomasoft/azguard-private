<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Panel lock contention; writers own distinct permissions even when they share a subject. */
final class LockContention extends BaseProfile
{
    public function id(): string
    {
        return 'lock-contention';
    }

    public function description(): string
    {
        return 'Panel contention with disjoint subjects and hot subjects';
    }

    public function stages(Tier $tier): array
    {
        $stages = [];
        $writers = $tier->name === 'smoke' ? [1] : [1, 4, 8];
        $readers = $tier->name === 'smoke' ? [1] : [1, 8, 16];
        foreach (['disjoint', 'hot-subject', 'hot-panel'] as $scenario) {
            foreach ($writers as $w) {
                foreach ($readers as $r) {
                    $stages[] = new Stage("{$scenario}:writers{$w}:readers{$r}", $w + $r, max(20, intdiv($tier->iterations, 2)), $tier->warmup);
                }
            }
        }

        return $stages;
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        [$scenario, $writers] = $this->parameters($stage);
        for ($w = 0; $w < $writers; $w++) {
            $id = $this->target($scenario, $w);
            DB::table('users')->insertOrIgnore(['id' => $id, 'name' => 'contention-'.$id]);
            AzGuard::actingAs('benchmark', fn () => $this->subject($id)->revokePermission(Catalog::name(900 + $w)));
        }
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        [$scenario, $writers] = $this->parameters($stage);
        $stand->newRequest();

        if ($worker < $writers) {
            $subject = $this->subject($this->target($scenario, $worker));
            $permission = Catalog::name(900 + $worker);
            AzGuard::actingAs('benchmark', fn () => $i % 2 === 0 ? $subject->grantPermission($permission) : $subject->revokePermission($permission));

            return 'write';
        }
        $id = $scenario === 'disjoint' ? 2 : $this->target($scenario, ($worker - $writers) % $writers);
        $decision = $this->subject($id)->decide(Catalog::name(900));

        return match ($decision->reason->value) {
            'consistency_error' => 'check_inconsistent',
            'granted', 'not_granted' => 'check',
            default => throw new RuntimeException('Check failed: '.$decision->reason->value),
        };
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        [$scenario, $writers] = $this->parameters($stage);
        $expected = ($stage->warmup + $stage->iterations) % 2 === 1;
        $wrong = 0;
        for ($w = 0; $w < $writers; $w++) {
            $stand->newRequest();
            $wrong += (int) ($this->check($this->subject($this->target($scenario, $w)), Catalog::name(900 + $w)) !== $expected);
        }

        return [['name' => 'all writers end in their last state', 'ok' => $wrong === 0, 'detail' => "{$wrong} of {$writers} incorrect"]];
    }

    private function parameters(Stage $stage): array
    {
        if (preg_match('/^(disjoint|hot-subject|hot-panel):writers(\d+):readers\d+$/', $stage->name, $m) !== 1) {
            throw new RuntimeException('Invalid contention stage');
        }

        return [$m[1], (int) $m[2]];
    }

    private function target(string $scenario, int $writer): int
    {
        return 10000000 + ($scenario === 'hot-subject' ? 0 : $writer);
    }
}
