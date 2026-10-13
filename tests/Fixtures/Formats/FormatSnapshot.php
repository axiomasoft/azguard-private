<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Formats;

/**
 * The JSON fixtures of the machine formats (I17), regression guards of the current behavior (D16). A fixture is written
 * only in writer mode, `AZGUARD_FORMATS_WRITE=1`, on the current tree; a normal run compares the stored text exactly
 * and fails when the fixture is missing. A change that alters a pinned format regenerates the fixture in the same commit.
 */
final class FormatSnapshot
{
    public const string WRITE = 'AZGUARD_FORMATS_WRITE';

    /** A generated ULID (event, correlation and incarnation ids) as it stands in a fixture. */
    public const string ULID = '<ulid>';

    /**
     * The PSR-4 roots of the core package (module roots included) and of Filament, relative to `packages/`.
     *
     * @return array<string, string> directory => namespace
     */
    public static function productionRoots(string $packages): array
    {
        $roots = [];

        foreach (['core', 'filament'] as $package) {
            /** @var array{autoload: array{psr-4: array<string, string>}} $composer */
            $composer = json_decode((string) file_get_contents("{$packages}/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);

            foreach ($composer['autoload']['psr-4'] as $namespace => $directory) {
                $roots[$package.'/'.rtrim($directory, '/')] = $namespace;
            }
        }

        return $roots;
    }

    public static function path(string $name): string
    {
        return __DIR__.'/'.$name.'.json';
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** The fixture text of `$name` next to the text of `$value`; the writer run stores `$value` first. */
    public static function compare(string $name, mixed $value): array
    {
        $actual = self::encode($value);
        $path = self::path($name);

        if (self::writing()) {
            file_put_contents($path, $actual);
        }

        return [$actual, is_file($path) ? (string) file_get_contents($path) : 'missing fixture '.$path];
    }

    /** Whether this run is the explicit writer run that regenerates the fixtures it passes through. */
    public static function writing(): bool
    {
        return getenv(self::WRITE) === '1';
    }

    /** The only placeholder of the fixtures: a ULID becomes `<ulid>`, at any depth, in keys and values alike. */
    public static function normalised(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[is_string($key) ? self::ulid($key) : $key] = self::normalised($item);
            }

            return $result;
        }

        return is_string($value) ? self::ulid($value) : $value;
    }

    private static function ulid(string $text): string
    {
        return preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/i', $text) === 1 ? self::ULID : $text;
    }
}
