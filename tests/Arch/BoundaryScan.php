<?php

declare(strict_types=1);

namespace AzGuard\Tests\Arch;

use RuntimeException;

/**
 * The boundary scans: core names no module; modules name neither each other nor Filament.
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
            $owner === 'core' && in_array($referenced, $modules, true) => 'core-module',
            in_array($owner, $modules, true) && (in_array($referenced, $modules, true) || $referenced === 'filament') => 'module-isolation',
            $owner === 'filament' && in_array($referenced, $modules, true) => 'module-isolation',
            default => null,
        };
    }
}
