<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use InvalidArgumentException;
use RuntimeException;

/** Reads bench/profiles.json, the single list of profiles and of their order. */
final class ProfileCatalog
{
    /**
     * @return list<array{profile: Profile, primary_op: string}>
     */
    public static function select(string $selection): array
    {
        $file = json_decode((string) file_get_contents(__DIR__.'/../profiles.json'), true, flags: JSON_THROW_ON_ERROR);
        $entries = is_array($file) && is_array($file['profiles'] ?? null) ? $file['profiles'] : throw new RuntimeException('bench/profiles.json has no profiles.');
        $wanted = $selection === 'all' ? null : explode(',', $selection);
        $selected = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['class'] ?? null) || ! is_string($entry['primary_op'] ?? null)) {
                throw new RuntimeException('Every profile of bench/profiles.json has an id, a class and a primary_op.');
            }

            if ($wanted !== null && ! in_array($entry['id'], $wanted, true)) {
                continue;
            }
            $profile = new $entry['class'];

            if (! $profile instanceof Profile || $profile->id() !== $entry['id']) {
                throw new RuntimeException("Profile [{$entry['id']}] does not match its class.");
            }
            $selected[] = ['profile' => $profile, 'primary_op' => $entry['primary_op']];
        }

        if ($selected === [] || ($wanted !== null && count($selected) !== count($wanted))) {
            throw new InvalidArgumentException("Unknown profile in [{$selection}].");
        }

        return $selected;
    }
}
