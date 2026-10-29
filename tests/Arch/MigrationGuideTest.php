<?php

declare(strict_types=1);

/*
 * The AI-agent migration instruction guard (PLAN1 P2.14, widened in P3.14): every supported symbol the type map renames, moves, retires
 * or splits in P2 or P3 — a type published at 0.7.0 (`api`, `spi`, also by directory) — is named by its old FQCN in the
 * `## From 0.7 to 1.0 (instructions for AI agents)` section of UPGRADING.md, so a consumer can grep its code for it.
 * Internal and unmarked types carry no promise and need no line (knowledge/p2-execution#conventions, rule 8).
 */

/** @return list<array{current: string, action: string, phase: string, was: string}> the rows of the plan's type map */
function typeMapRows(): array
{
    $root = dirname(__DIR__, 2);
    $documents = [$root.'/tests/Arch/Fixtures/PlanKnowledge/type-map.json'];
    $rows = [];

    foreach ($documents as $document) {
        $map = json_decode((string) file_get_contents($document), true, flags: JSON_THROW_ON_ERROR);

        foreach ($map['sections'] ?? [] as $section) {
            foreach (explode("\n", (string) ($section['text'] ?? '')) as $line) {
                $cells = array_map(trim(...), explode('|', $line));

                // | `current` | `target` | owner | marker | enum | action / phase | was | reason |
                if (count($cells) < 9 || ! str_starts_with($cells[1], '`') || preg_match('/\A(\w[\w+]*) \/ (.+)\z/', $cells[6], $actionPhase) !== 1) {
                    continue;
                }

                $rows[] = [
                    'current' => trim($cells[1], '`'),
                    'action' => $actionPhase[1],
                    'phase' => $actionPhase[2],
                    'was' => $cells[7],
                ];
            }
        }
    }

    return $rows;
}

/** The 0.7 → 1.0 section of UPGRADING.md. */
function migrationInstruction(): string
{
    $guide = (string) file_get_contents(dirname(__DIR__, 2).'/UPGRADING.md');
    $start = strpos($guide, '## From 0.7 to 1.0');

    expect($start)->not->toBeFalse('UPGRADING.md has no 0.7 to 1.0 section');

    $end = strpos($guide, "\n## ", (int) $start + 1);

    return substr($guide, (int) $start, $end === false ? null : $end - (int) $start);
}

it('reads the type map of the plan', function (): void {
    expect(typeMapRows())->not->toBe([], 'the plan holds no type map: its knowledge/type-map.json is the source of this guard');
});

it('names the old FQCN of every published type that P2 or P3 renames, moves, retires or splits', function (): void {
    $instruction = migrationInstruction();
    $required = [];
    $missing = [];

    foreach (typeMapRows() as $row) {
        $published = str_starts_with($row['was'], 'api') || str_starts_with($row['was'], 'spi');

        if (! $published || ! in_array($row['action'], ['rename', 'move', 'retire', 'split'], true) || ! (str_contains($row['phase'], 'P2') || str_contains($row['phase'], 'P3'))) {
            continue;
        }

        $fqcn = 'AzGuard\\'.$row['current'];
        $required[] = $fqcn;

        if (! str_contains($instruction, $fqcn)) {
            $missing[] = $fqcn;
        }
    }

    expect($required)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('writes every bullet as a Find/Replace line', function (): void {
    $bullets = preg_split('/^- /m', migrationInstruction());
    array_shift($bullets);
    $malformed = [];

    foreach ($bullets as $bullet) {
        if (preg_match('/\AFind: .+→\s+Replace:\s/s', $bullet) !== 1) {
            $malformed[] = strtok($bullet, "\n");
        }
    }

    expect($malformed)->toBe([]);
});
