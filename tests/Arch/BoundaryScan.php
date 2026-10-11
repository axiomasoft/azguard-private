<?php

declare(strict_types=1);

namespace AzGuard\Tests\Arch;

use RuntimeException;

/**
 * The boundary scans I2 and I3 and the report-mode comparison against an allowlist.
 *
 * Report mode: the current violations must equal the allowlist exactly. A violation that is not listed is new and
 * fails now; a listed one that no longer occurs is stale and fails too, so the lists only shrink. The scans become
 * blocking, with empty lists, at P3 exit.
 */
final class BoundaryScan
{
    /** @var list<string> */
    public const OWNERLESS = ['split', 'retired'];

    /**
     * @param  list<string>  $files  absolute paths
     * @return list<array{file: string, reference: string, kind: string, invariant: string, owner_phase: string|null}>
     */
    public static function violations(Ownership $ownership, array $files, ?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/').'/';
        $violations = [];

        foreach ($files as $file) {
            $path = str_replace($root, '', $file);
            $row = $ownership->fileRow($path);
            $owner = $row['owner'] ?? null;

            if ($owner === null || in_array($owner, self::OWNERLESS, true)) {
                continue;
            }

            foreach (SourceScan::ownershipReferences((string) file_get_contents($file), array_keys(Ownership::PLUGIN_IDS), array_keys(Ownership::TABLES)) as $reference) {
                $referenced = $ownership->referenceOwner($reference['kind'], $reference['value']);
                $invariant = $referenced === null || $referenced === $owner || in_array($referenced, self::OWNERLESS, true)
                    ? null
                    : self::invariant($owner, $referenced);

                if ($invariant !== null) {
                    $violations[] = [
                        'file' => $path, 'reference' => $reference['value'], 'kind' => $reference['kind'], 'invariant' => $invariant,
                        'owner_phase' => Ownership::removingPhase($row, $ownership->typeRow($reference['value']))
                            ?? $ownership->relocationPhase($referenced),
                    ];
                }
            }
        }
        usort($violations, static fn (array $a, array $b): int => [$a['invariant'], $a['file'], $a['reference']] <=> [$b['invariant'], $b['file'], $b['reference']]);

        return $violations;
    }

    /** The invariant a reference from one owner to another breaks, or null when it is allowed. */
    private static function invariant(string $owner, string $referenced): ?string
    {
        $modules = Ownership::MODULES;

        return match (true) {
            $owner === 'core' && in_array($referenced, $modules, true) => 'I2',
            in_array($owner, $modules, true) && (in_array($referenced, $modules, true) || $referenced === 'filament') => 'I3',
            $owner === 'filament' && in_array($referenced, $modules, true) => 'I3',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $found
     * @param  list<array<string, mixed>>  $allowed
     * @return array{new: list<string>, stale: list<string>} the entries that differ, one line each
     */
    public static function compare(array $found, array $allowed): array
    {
        $keys = static fn (array $entries): array => array_values(array_unique(array_map(
            static fn (array $entry): string => implode(' | ', [$entry['invariant'], $entry['file'], $entry['reference'], $entry['kind'], $entry['owner_phase'] ?? '—']),
            $entries,
        )));
        [$foundKeys, $allowedKeys] = [$keys($found), $keys($allowed)];

        return ['new' => array_values(array_diff($foundKeys, $allowedKeys)), 'stale' => array_values(array_diff($allowedKeys, $foundKeys))];
    }

    /** @return list<array<string, mixed>> */
    public static function allowlist(string $name): array
    {
        $file = __DIR__.'/Allowlists/'.$name.'.json';
        $entries = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('An allowlist is a list of entries: '.$file);
        }

        return $entries;
    }
}
