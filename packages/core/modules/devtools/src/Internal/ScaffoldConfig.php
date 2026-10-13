<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal;

use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Config\Repository;

/**
 * @internal What the generators read from `config/azguard.php`: the `scaffold` section DevTools owns (the namespace
 * and the path of the panel directories) and the folder names of `discovery.*`, which core owns and validates when it
 * reads the configuration; a folder name core would refuse is refused here too.
 */
final readonly class ScaffoldConfig
{
    /** Keys of the `scaffold` section. */
    public const array KEYS = ['namespace', 'path'];

    /** The folder names of `discovery.*` when the configuration does not set them (core's defaults). */
    public const array FOLDERS = [
        'permissions' => 'Permissions',
        'policies' => 'Policies',
        'roles' => 'Roles',
        'scopes' => 'Scopes',
        'abilities' => 'Abilities',
        'queries' => 'Queries',
        'shared' => 'Shared',
    ];

    /** @param array<string, string> $folders */
    public function __construct(public string $namespace, public string $path, private array $folders) {}

    /** @throws InvalidConfigurationException */
    public static function fromRepository(Repository $config): self
    {
        $section = $config->get('azguard.scaffold', []);
        $section = is_array($section) ? $section : throw new InvalidConfigurationException('azguard.scaffold must be an array, got '.get_debug_type($section).'.');
        self::validate($section);
        $discovery = $config->get('azguard.discovery', []);
        $folders = self::FOLDERS;

        foreach (is_array($discovery) ? $discovery : [] as $kind => $name) {
            if (! is_string($kind) || ! array_key_exists($kind, $folders)) {
                continue;
            }

            if (! is_string($name) || $name === '' || str_contains($name, "\0")) {
                throw new InvalidConfigurationException('azguard.discovery.'.$kind.' must be a folder name, got '.get_debug_type($name).'.');
            }
            $folders[$kind] = trim(str_replace('\\', '/', $name), '/');
        }

        return new self(self::namespaceFrom($section['namespace'] ?? 'App\\Guards'), self::pathFrom($section['path'] ?? 'app/Guards'), $folders);
    }

    /**
     * Validates the `scaffold` section; DevTools registers it in `ConfigSections`.
     *
     * @param  array<mixed>  $section
     *
     * @throws InvalidConfigurationException
     */
    public static function validate(array $section): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($section)), self::KEYS);

        if ($unknown !== []) {
            throw new InvalidConfigurationException('Unknown key'.(count($unknown) === 1 ? '' : 's').' in azguard.scaffold: '.implode(', ', $unknown)
                .'. Known keys: '.implode(', ', self::KEYS).'.');
        }
        self::namespaceFrom($section['namespace'] ?? 'App\\Guards');
        self::pathFrom($section['path'] ?? 'app/Guards');
    }

    /** The folder name of a kind of `discovery.*`, such as `Permissions`; null for an unknown kind. */
    public function folder(string $kind): ?string
    {
        return $this->folders[$kind] ?? null;
    }

    /** @throws InvalidConfigurationException */
    private static function namespaceFrom(mixed $namespace): string
    {
        $namespace = is_string($namespace) ? trim($namespace, '\\') : null;

        return $namespace !== null && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $namespace) === 1
            ? $namespace
            : throw new InvalidConfigurationException('azguard.scaffold.namespace must be a PHP namespace, got '.get_debug_type($namespace).'.');
    }

    /** @throws InvalidConfigurationException */
    private static function pathFrom(mixed $path): string
    {
        $path = is_string($path) ? rtrim(str_replace('\\', '/', $path), '/') : null;

        return $path !== null && $path !== '' && ! str_contains($path, "\0") && ! str_starts_with($path, '/')
            && preg_match('/\A[a-z][a-z0-9+.-]*:/i', $path) !== 1 && ! in_array('..', explode('/', $path), true)
            ? $path
            : throw new InvalidConfigurationException('azguard.scaffold.path must be a directory relative to the application, got '.get_debug_type($path).'.');
    }
}
