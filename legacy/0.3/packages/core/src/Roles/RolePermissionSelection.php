<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

/**
 * Normalized role-permission edit: desired tuples, the managed universe, and
 * the fingerprint of that universe when the editor opened the snapshot.
 */
final readonly class RolePermissionSelection
{
    /**
     * @param  list<array{0: string, 1: string}>  $desired
     * @param  list<array{0: string, 1: string}>|null  $managed  null replaces every current row on $panels
     * @param  list<string>  $panels
     */
    private function __construct(
        public array $desired,
        public ?array $managed,
        public array $panels,
        public ?string $expectedFingerprint,
    ) {}

    /** @param  list<string>  $keys */
    public static function panelReplacement(string $panel, array $keys, ?string $expectedFingerprint = null): self
    {
        return new self(
            desired: self::normalize(array_map(
                static fn (string $key): array => [$panel, $key],
                $keys,
            )),
            managed: null,
            panels: [$panel],
            expectedFingerprint: $expectedFingerprint,
        );
    }

    public static function singleKey(string $panel, string $key, bool $present, ?string $expectedFingerprint = null): self
    {
        $tuple = self::normalize([[$panel, $key]])[0];

        return new self(
            desired: $present ? [$tuple] : [],
            managed: [$tuple],
            panels: [$panel],
            expectedFingerprint: $expectedFingerprint,
        );
    }

    /**
     * @param  list<array{0: string, 1: string}>  $managed
     * @param  list<array{0: string, 1: string}>  $desired
     */
    public static function managedSubset(array $managed, array $desired, ?string $expectedFingerprint = null): self
    {
        $managed = self::normalize($managed);
        $desired = self::normalize($desired);
        $allowed = [];

        foreach ($managed as $tuple) {
            $allowed[$tuple[0]."\t".$tuple[1]] = true;
        }

        foreach ($desired as $tuple) {
            if (! isset($allowed[$tuple[0]."\t".$tuple[1]])) {
                throw InvalidPermissionKeyException::forKey($tuple[1], $tuple[0]);
            }
        }

        $panels = array_values(array_unique(array_map(static fn (array $tuple): string => $tuple[0], $managed)));
        sort($panels);

        return new self(
            desired: $desired,
            managed: $managed,
            panels: $panels,
            expectedFingerprint: $expectedFingerprint,
        );
    }

    /** @param  list<array{0: string, 1: string}>  $tuples */
    public static function fingerprint(array $tuples): string
    {
        $lines = array_map(
            static fn (array $tuple): string => $tuple[0]."\t".$tuple[1],
            self::normalize($tuples),
        );

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tuples
     * @return list<array{0: string, 1: string}>
     */
    private static function normalize(array $tuples): array
    {
        $unique = [];

        foreach ($tuples as $tuple) {
            $unique[$tuple[0]."\t".$tuple[1]] = [$tuple[0], $tuple[1]];
        }

        $rows = array_values($unique);
        usort($rows, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        return $rows;
    }
}
