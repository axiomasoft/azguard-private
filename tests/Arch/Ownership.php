<?php

declare(strict_types=1);

namespace AzGuard\Tests\Arch;

use RuntimeException;

/**
 * Ownership of every production file and type, read from the generated oracle `Fixtures/target-map.json` (a byte copy
 * of the plan artifact, D14). A file is owned by the row whose `current_path` or `target_path` is that file, a type by
 * the row whose `current` or `target` name is that FQCN, so the answer is the same before and after a move.
 *
 * Owners: `core`, `scopes`, `audit`, `devtools`, `filament`; `split` (the parts of one type go to several owners) and
 * `retired` (no target) take part in no boundary rule until the type is split or removed.
 */
final class Ownership
{
    /** @var list<string> */
    public const MODULES = ['scopes', 'audit', 'devtools'];

    /** The plugin ids of the internal modules; a literal outside the module names a module. */
    public const PLUGIN_IDS = ['azguard/scopes' => 'scopes', 'azguard/audit' => 'audit', 'azguard/devtools' => 'devtools'];

    /** The tables a module owns; a query or a schema statement outside the module names a module. */
    public const TABLES = ['audit_log' => 'audit'];

    /** The vendor and sibling-package namespaces that are Filament code. */
    public const FILAMENT_NAMESPACES = ['AzGuard\\Filament', 'Filament'];

    private static ?self $oracle = null;

    /** @var array<string, array<string, mixed>> */
    private array $byPath = [];

    /** @var array<string, array<string, mixed>> */
    private array $byType = [];

    /** @param list<array<string, mixed>> $rows */
    private function __construct(private readonly array $rows)
    {
        // A split row names the parts of one type, which have rows of their own (the module part, for one): the
        // explicit row of a target wins over the `split` row that mentions it, wherever the rows stand.
        foreach ([false, true] as $splitRows) {
            foreach ($rows as $row) {
                if ((($row['owner'] ?? null) === 'split') !== $splitRows) {
                    continue;
                }
                foreach (self::parts($row['current_path'] ?? null) as $path) {
                    $this->byPath[$path] ??= $row;
                }
                foreach (self::parts($row['target_path'] ?? null) as $path) {
                    $this->byPath[$path] ??= $row;
                }
                foreach (self::parts($row['current'] ?? null) as $type) {
                    $this->byType[strtolower($type)] ??= $row;
                }
                foreach (self::parts($row['target'] ?? null) as $type) {
                    $this->byType[strtolower($type)] ??= $row;
                }
            }
        }
    }

    public static function fromOracle(): self
    {
        if (self::$oracle === null) {
            $file = __DIR__.'/Fixtures/target-map.json';
            $map = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($map) || ! is_array($map['rows'] ?? null)) {
                throw new RuntimeException('The ownership oracle has no rows: '.$file);
            }
            self::$oracle = new self($map['rows']);
        }

        return self::$oracle;
    }

    /** @param list<array<string, mixed>> $rows a synthetic oracle for the non-vacuity tests */
    public static function of(array $rows): self
    {
        return new self($rows);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The row of a file: by its path, or, for a file at an interim path the oracle does not list (D17: a type P2 gives
     * its target FQCN before P3 moves the file), by the FQCN the file declares.
     *
     * @param  string  $path  path relative to the repository root
     * @param  string|null  $root  repository root the path is read from; this repository when null
     */
    public function fileRow(string $path, ?string $root = null): ?array
    {
        if (isset($this->byPath[$path])) {
            return $this->byPath[$path];
        }
        $file = rtrim($root ?? dirname(__DIR__, 2), '/').'/'.$path;
        $declared = is_file($file) ? self::declaredType((string) file_get_contents($file)) : null;

        return $declared === null ? null : $this->typeRow($declared);
    }

    public function fileOwner(string $path, ?string $root = null): ?string
    {
        return $this->fileRow($path, $root)['owner'] ?? null;
    }

    /** The FQCN of the first class, interface, trait or enum a file declares, or null when it declares none. */
    public static function declaredType(string $code): ?string
    {
        $tokens = array_values(array_filter(token_get_all($code), static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $name = $tokens[$index + 1] ?? null;

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && is_array($name) && $name[0] === T_STRING
                && ! (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true))) {
                $namespace = SourceScan::namespace($code);

                return ($namespace === '' ? '' : $namespace.'\\').$name[1];
            }
        }

        return null;
    }

    public function typeRow(string $fqcn): ?array
    {
        return $this->byType[strtolower(ltrim($fqcn, '\\'))] ?? null;
    }

    /**
     * The owner of what a reference names: a type of the oracle, a module plugin id or a module table. Null for what
     * the oracle does not know (vendor code, PHP, a name the file only resolves relative to its namespace).
     *
     * @param  string  $kind  a kind of `SourceScan::ownershipReferences()`
     */
    public function referenceOwner(string $kind, string $value): ?string
    {
        return match ($kind) {
            'plugin-id' => self::PLUGIN_IDS[$value] ?? null,
            'table' => self::TABLES[$value] ?? null,
            default => $this->typeRow($value)['owner'] ?? (self::isFilament($value) ? 'filament' : null),
        };
    }

    /**
     * The phase that removes a violation: the latest phase of the given rows, where `P2/P3` is P3 and `—` is none.
     *
     * @param  array<string, mixed>|null  ...$rows
     */
    public static function removingPhase(?array ...$rows): ?string
    {
        $phases = [];

        foreach ($rows as $row) {
            if (preg_match('/P\d+\z/', (string) ($row['phase'] ?? '—'), $last) === 1) {
                $phases[] = $last[0];
            }
        }
        rsort($phases);

        return $phases[0] ?? null;
    }

    /** The phase that completes the relocation of a module: the latest phase of the rows it owns. */
    public function relocationPhase(string $module): ?string
    {
        return self::removingPhase(...array_values(array_filter($this->rows, static fn (array $row): bool => ($row['owner'] ?? null) === $module)));
    }

    public static function isFilament(string $fqcn): bool
    {
        foreach (self::FILAMENT_NAMESPACES as $namespace) {
            if (SourceScan::inZone(ltrim($fqcn, '\\'), [$namespace])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the parts of a `+`-separated oracle cell; none for an empty cell */
    private static function parts(?string $cell): array
    {
        if ($cell === null || $cell === '' || $cell === '—') {
            return [];
        }

        return array_map('trim', explode(' + ', $cell));
    }
}
