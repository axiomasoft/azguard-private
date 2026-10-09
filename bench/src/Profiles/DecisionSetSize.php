<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Changes\ChangeResult;
use AzGuard\Exceptions\DecisionSetTooLargeException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One `decideMany()` over N distinct subjects, each with a role, in a new request: the evidence for the default of
 * `decision_sets.max_subjects` (owner decision 3 on the open questions of audits/2026-10-09-consistency-design.md).
 * `probe.snapshot` is the time the set keeps its read snapshot open. The `writer` stage adds a writer on other
 * subjects next to a set of the default size, to show that the snapshot does not hold up the panel lock
 * (`probe.lock_wait`). The stand raises the limit so that sets above the default can be measured.
 */
final class DecisionSetSize extends BaseProfile
{
    public const int STAND_LIMIT = 5_000;

    private static bool $seeded = false;

    public function id(): string
    {
        return 'decision-set';
    }

    public function description(): string
    {
        return 'decideMany() over 100 to 2 000 distinct subjects: snapshot span, SQL and latency; a writer beside a set of 500';
    }

    public function stages(Tier $tier): array
    {
        // A set of 2 000 takes seconds: few iterations are enough, and the spread is reported per repetition.
        $iterations = max(5, intdiv($tier->iterations, 40));
        $warmup = max(1, intdiv($tier->warmup, 20));
        $stages = array_map(static fn (int $size): Stage => new Stage('set'.$size.':w1', 1, $iterations, $warmup), $this->sizes($tier));
        $stages[] = new Stage('writer'.$this->sizes($tier)[2].':w2', 2, $iterations * 2, $warmup);

        return $stages;
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        if (self::$seeded) {
            return;
        }
        // Subjects past the users of the tier get one role, so every subject of a set is admitted and read.
        $max = max($this->sizes($tier));
        $rows = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'u'.$id], range($tier->users + 1, $max + 12));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        AzGuard::actingAs('benchmark', static function () use ($tier, $max): void {
            for ($id = $tier->users + 1; $id <= $max + 1; $id++) {
                AzGuard::panel('bench')->for(Dataset::model(Catalog::class('User'), $id))->grantRole('r'.($id % Catalog::ROLES + 1));
            }
        });
        self::$seeded = true;
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $stand->newRequest();
        $size = (int) preg_replace('/\D/', '', (string) strstr($stage->name, ':', true));

        if ($worker === 0 && $stage->workers > 1) {
            // A burst of writes at 20/s for about as long as one set takes, so writes overlap the set's snapshot; each
            // write reports its own probe.lock_wait and probe.lock_hold.
            $until = microtime(true) + $size * 0.003;
            $n = 0;
            do {
                $subject = $this->subject(max($this->sizes($tier)) + 2 + ($i + $n) % 10);
                $grant = intdiv($i + $n, 10) % 2 === 0;
                AzGuard::actingAs('benchmark', static fn (): ChangeResult => $grant
                    ? $subject->grantPermission(Catalog::missingPermission())
                    : $subject->revokePermission(Catalog::missingPermission()));
                $n++;
                usleep(50_000);
            } while (microtime(true) < $until);

            return 'write_burst';
        }
        $permission = PermissionKey::of('bench', Catalog::rolePermissions(1)[$i % Catalog::ROLE_SIZE]);
        $requests = [];
        for ($id = 2; $id <= $size + 1; $id++) {
            $requests[] = AccessRequest::for(SubjectRef::of('user', $id), $permission);
        }
        $set = AzGuard::panel('bench')->decideMany($requests);

        if ($set->failure() !== null || count($set) !== $size || count($set->states()) !== 1) {
            throw new RuntimeException('The set failed or did not match one state.');
        }

        return 'set';
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $requests = [];
        for ($id = 1; $id <= self::STAND_LIMIT + 1; $id++) {
            $requests[] = AccessRequest::for(SubjectRef::of('user', $id), PermissionKey::of('bench', Catalog::missingPermission()));
        }

        try {
            AzGuard::panel('bench')->decideMany($requests);
            $refused = false;
        } catch (DecisionSetTooLargeException) {
            $refused = true;
        }

        return [['name' => 'a set over decision_sets.max_subjects is refused, not split', 'ok' => $refused, 'detail' => (self::STAND_LIMIT + 1).' subjects']];
    }

    /** @return non-empty-list<int> */
    private function sizes(Tier $tier): array
    {
        return $tier->name === 'smoke' ? [5, 10, 20, 40] : [100, 250, 500, 1000, 2000];
    }
}
