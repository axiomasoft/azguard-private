<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use RuntimeException;

/**
 * The result ledger of the matrix: every path verdict per case. A case agrees when every path answers and all answers
 * are equal; a case with an unsupported path is never counted as agreement, its answering paths must still agree,
 * and its unsupported paths are compared with `unsupported-0.7.0.json`.
 *
 * The snapshot is the set itself, without loss: per reason and group (one list query or directory search), the
 * unsupported paths, the seeds and the members (clients, contexts). Seeds with the same paths and members share one
 * line. A new unsupported case fails, and a case that became supported fails until it is deleted from the snapshot.
 * It is rewritten only in writer mode, `AZGUARD_FORMATS_WRITE=1`, like the other baseline fixtures (D16).
 *
 * A disagreement is an I9 finding. The ones 0.7.0 has are reported to the owner in the plan log and listed, by
 * finding, in the hand-written `i9-findings-0.7.0.json`; the matrix fails on any other disagreement and on a listed
 * one that no longer occurs. That file is never generated.
 */
final class ParityLedger
{
    public const string SNAPSHOT = __DIR__.'/unsupported-0.7.0.json';

    public const string FINDINGS = __DIR__.'/i9-findings-0.7.0.json';

    /** @var array<string, array<string, Verdict|Unsupported>> */
    private array $cases = [];

    /** @var array<string, ParityCase> */
    private array $questions = [];

    /**
     * @param  list<ParityCase>  $cases
     * @param  array<string, array<string, Verdict|Unsupported>>  $results  per case id, per path
     */
    public function record(array $cases, array $results): void
    {
        foreach ($cases as $case) {
            $this->questions[$case->id()] = $case;
            $this->cases[$case->id()] = [...$this->cases[$case->id()] ?? [], ...$results[$case->id()]];
        }
    }

    public function cases(): int
    {
        return count($this->cases);
    }

    /** @return array<string, string> per case whose answering paths differ, every path's answer */
    public function disagreements(): array
    {
        $failures = [];

        foreach ($this->cases as $case => $paths) {
            $answers = array_filter($paths, static fn (Verdict|Unsupported $verdict): bool => $verdict instanceof Verdict);

            if (count(array_unique(array_map(static fn (Verdict $verdict): bool => $verdict->allowed, $answers))) > 1) {
                $failures[$case] = implode(' ', array_map(static fn (string $path, Verdict|Unsupported $verdict): string => $path.'='.$verdict->label(),
                    array_keys($paths), $paths));
            }
        }
        ksort($failures);

        return $failures;
    }

    /**
     * The disagreements of a section reported as I9 findings of 0.7.0, in the shape of `disagreements()`.
     *
     * @return array<string, string>
     */
    public static function findings(string $section): array
    {
        $stored = json_decode((string) file_get_contents(self::FINDINGS), true, flags: JSON_THROW_ON_ERROR);
        $listed = [];

        foreach ($stored['disagreements'][$section] ?? [] as $entry) {
            if (! isset($stored['findings'][$entry['finding']])) {
                throw new RuntimeException('Disagreement '.$entry['case'].' names the unknown finding '.$entry['finding'].'.');
            }
            $listed[$entry['case']] = $entry['observed'];
        }
        ksort($listed);

        return $listed;
    }

    /** @return array{agreed: int, allow: int, deny: int, unsupported: int} */
    public function counts(): array
    {
        $counts = ['agreed' => 0, 'allow' => 0, 'deny' => 0, 'unsupported' => 0];

        foreach ($this->cases as $paths) {
            $answers = array_values(array_filter($paths, static fn (Verdict|Unsupported $verdict): bool => $verdict instanceof Verdict));

            if (count($answers) < count($paths)) {
                $counts['unsupported']++;

                continue;
            }

            if (count(array_unique(array_map(static fn (Verdict $verdict): bool => $verdict->allowed, $answers))) === 1) {
                $counts['agreed']++;
                $counts[$answers[0]->allowed ? 'allow' : 'deny']++;
            }
        }

        return $counts;
    }

    /** @return array<string, array<string, list<string>>> per reason, per group: "paths=… seeds=… members" lines */
    public function unsupported(): array
    {
        $facts = [];

        foreach ($this->cases as $id => $paths) {
            $case = $this->questions[$id];
            $reasons = [];

            foreach ($paths as $path => $verdict) {
                if ($verdict instanceof Unsupported) {
                    $reasons[$verdict->reason][] = $path;
                }
            }
            foreach ($reasons as $reason => $unsupported) {
                sort($unsupported);
                $facts[$reason][$case->group()][implode(',', $unsupported)][$case->seed][] = $case->member();
            }
        }
        $set = [];

        foreach ($facts as $reason => $groups) {
            foreach ($groups as $group => $byPaths) {
                foreach ($byPaths as $paths => $seeds) {
                    $lines = [];

                    foreach ($seeds as $seed => $members) {
                        sort($members);
                        $lines[implode(' ', $members)][] = $seed;
                    }
                    foreach ($lines as $members => $seedList) {
                        $set[$reason][$group][] = 'paths='.$paths.' seeds='.self::ranges($seedList).' '.$members;
                    }
                }
                sort($set[$reason][$group]);
            }
            ksort($set[$reason]);
        }
        ksort($set);

        return $set;
    }

    /** @param list<int> $values */
    private static function ranges(array $values): string
    {
        sort($values);
        $ranges = [];

        foreach ($values as $value) {
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][1] === $value - 1) {
                $ranges[$last][1] = $value;
            } else {
                $ranges[] = [$value, $value];
            }
        }

        return implode(',', array_map(static fn (array $r): string => $r[0] === $r[1] ? (string) $r[0] : $r[0].'-'.$r[1], $ranges));
    }

    /**
     * The stored text of the snapshot section next to the text of this ledger; the writer run stores it first.
     *
     * @return array{string, string}
     */
    public function compare(string $section): array
    {
        $stored = is_file(self::SNAPSHOT) ? json_decode((string) file_get_contents(self::SNAPSHOT), true, flags: JSON_THROW_ON_ERROR) : null;
        $actual = FormatSnapshot::encode($this->unsupported());

        if (FormatSnapshot::writing()) {
            $stored = ['baseline' => '0.7.0', ...$stored ?? [], $section => $this->unsupported()];
            file_put_contents(self::SNAPSHOT, FormatSnapshot::encode($stored));
        }

        return [$actual, isset($stored[$section]) ? FormatSnapshot::encode($stored[$section]) : 'missing section '.$section.' of '.self::SNAPSHOT];
    }
}
