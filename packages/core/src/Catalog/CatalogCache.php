<?php

declare(strict_types=1);

namespace AzGuard\Catalog;

use AzGuard\Exceptions\InvalidConfigurationException;

/**
 * The file `azguard:catalog:cache` writes: a PHP array of scalars with the static catalogs of all panels.
 *
 * A panel takes its catalog from the file only when the build id of the file and the fingerprint of the panel recipe
 * are the current ones; anything else is ignored and the catalog is built from the sources.
 *
 * @phpstan-import-type Snapshot from PanelCatalog
 *
 * @phpstan-type PanelEntry array{fingerprint: string, catalog: Snapshot}
 */
final readonly class CatalogCache
{
    public const int VERSION = 2;

    public function __construct(private string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The catalog snapshot of a panel when the file was written by this build for this recipe, otherwise null.
     *
     * @param  array<mixed>  $file  what read() returned
     * @return array<mixed>|null
     */
    public static function entry(array $file, string $buildId, string $panel, string $fingerprint): ?array
    {
        if (($file['version'] ?? null) !== self::VERSION || ($file['build_id'] ?? null) !== $buildId) {
            return null;
        }

        $entry = $file['panels'][$panel] ?? null;

        return is_array($entry) && ($entry['fingerprint'] ?? null) === $fingerprint && is_array($entry['catalog'] ?? null)
            ? $entry['catalog']
            : null;
    }

    /**
     * @return array<mixed> the content of the file, or an empty array when there is no readable file
     */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $content = (static fn (string $path): mixed => require $path)($this->path);

        return is_array($content) ? $content : [];
    }

    /**
     * @param  array<string, PanelEntry>  $panels
     *
     * @throws InvalidConfigurationException when the file cannot be written
     */
    public function write(string $buildId, array $panels): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new InvalidConfigurationException('Cannot create the directory '.$directory.' for the AzGuard catalog cache.');
        }

        $content = '<?php return '.var_export(['version' => self::VERSION, 'build_id' => $buildId, 'panels' => $panels], true).';'.PHP_EOL;
        $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $content) === false || ! rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new InvalidConfigurationException('Cannot write the AzGuard catalog cache to '.$this->path.'.');
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }

    /**
     * @return bool whether a file was removed
     */
    public function clear(): bool
    {
        return is_file($this->path) && unlink($this->path);
    }
}
