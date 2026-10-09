<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

/**
 * A load profile. The runner seeds once, calls `prepare()` in the parent, forks the workers of every stage and calls
 * `iteration()` in a worker; the time and the SQL queries of each iteration are one sample of the operation it names.
 */
interface Profile
{
    public function id(): string;

    public function description(): string;

    /** @return list<Stage> */
    public function stages(Tier $tier): array;

    /** Parent process, after seeding and before the workers of a stage are forked. */
    public function prepare(Stand $stand, Tier $tier, Stage $stage): void;

    /** Worker process, after its connections are open and before its first iteration. */
    public function boot(Stand $stand, Tier $tier, Stage $stage, int $worker): void;

    /** One operation; returns its name (the key of the samples). */
    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string;

    /**
     * Parent process, after the workers of a stage ended: the correctness checks of the stage.
     *
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    public function verify(Stand $stand, Tier $tier, Stage $stage): array;
}
