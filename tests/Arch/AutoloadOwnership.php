<?php

declare(strict_types=1);

namespace AzGuard\Tests\Arch;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * I5: every production FQCN has exactly one autoload owner. The owners are the PSR-4 entries of `packages/<name>/composer.json`;
 * an entry owns a type when composer would load it from that file: the FQCN starts with the prefix and the rest of it,
 * as a path under the entry's directory, is the file. The types are read from the sources, so a file in the wrong
 * directory, a type no entry reaches and a type declared twice are all found, and so are the directories the transition
 * removes: `src/Scopes`, `src/Audit`, `src/DevTools` and `src/Plugins/Audit`.
 *
 * Violation kinds: `no-owner`, `several-owners`, `duplicate-class` (the authoritative classmap would hold one file of
 * two) and `directory`.
 */
final class AutoloadOwnership
{
    /** @var list<string> directories of a package source root that belong to a module, not to core */
    public const MODULE_DIRECTORIES = ['Scopes', 'Audit', 'DevTools', 'Plugins/Audit'];

    /** @return list<array{prefix: string, directory: string}> PSR-4 entries, directories relative to the repository root */
    public static function entries(?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/');
        $entries = [];

        foreach (glob($root.'/packages/*/composer.json') ?: [] as $manifest) {
            $composer = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $package = basename(dirname($manifest));

            foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    $entries[] = ['prefix' => (string) $prefix, 'directory' => 'packages/'.$package.'/'.trim((string) $directory, '/')];
                }
            }
        }

        return $entries;
    }

    /** @return list<string> the classes, interfaces, traits and enums the code declares, each in the namespace in force at its declaration */
    public static function declaredTypes(string $code): array
    {
        $namespace = '';
        $tokens = array_values(array_filter(token_get_all($code), static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $types = [];

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $name = $tokens[$index + 1] ?? null;

            if (is_array($token) && $token[0] === T_NAMESPACE && is_array($name) && in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $name[1];
            } elseif (is_array($token) && $token[0] === T_NAMESPACE && $name === '{') {
                $namespace = '';
            }

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ! (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true))
                && is_array($name) && $name[0] === T_STRING) {
                $types[] = ($namespace === '' ? '' : $namespace.'\\').$name[1];
            }
        }

        return $types;
    }

    /**
     * The authoritative classmap of the production files.
     *
     * @param  list<string>  $files  absolute paths
     * @return array<string, list<string>> FQCN => the files, relative to the root, that declare it
     */
    public static function classMap(array $files, string $root): array
    {
        $map = [];

        foreach ($files as $file) {
            foreach (self::declaredTypes((string) file_get_contents($file)) as $type) {
                $map[$type][] = str_replace(rtrim($root, '/').'/', '', $file);
            }
        }
        ksort($map);

        // A conditional declaration (`if (…) { class A } else { class A }`) is one class of one file.
        return array_map(static fn (array $paths): array => array_values(array_unique($paths)), $map);
    }

    /**
     * The classmap composer itself writes for `--classmap-authoritative --strict-ambiguous` over the PSR-4 entries of the
     * packages: built in a throw-away project that links `packages/`, with its own vendor directory, so nothing of the
     * repository is written. A type no entry reaches, a file in the wrong directory (composer skips it) and a type two files
     * declare (strict-ambiguous fails) are exactly what an authoritative consumer would not autoload.
     *
     * @return array<string, string> FQCN => file relative to the repository root
     *
     * @throws RuntimeException when composer is missing or fails, with its output
     */
    public static function authoritativeClassMap(?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/');
        $project = sys_get_temp_dir().'/azguard-classmap-'.bin2hex(random_bytes(4));
        $psr4 = [];

        foreach (self::entries($root) as $entry) {
            $psr4[$entry['prefix']][] = $entry['directory'].'/';
        }

        try {
            mkdir($project.'/vendor/composer', 0777, true);
            symlink($root.'/packages', $project.'/packages');
            file_put_contents($project.'/composer.json', json_encode(['autoload' => ['psr-4' => $psr4]], JSON_THROW_ON_ERROR));
            file_put_contents($project.'/vendor/composer/installed.json', '{"packages":[],"dev":true,"dev-package-names":[]}');
            file_put_contents($project.'/vendor/composer/installed.php', "<?php return ['root' => ['name' => '__root__', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'library', 'install_path' => __DIR__.'/../../', 'aliases' => [], 'dev' => true], 'versions' => []];");

            $process = proc_open(
                ['composer', 'dump-autoload', '--no-plugins', '--no-scripts', '--no-interaction', '--classmap-authoritative', '--strict-ambiguous'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $project,
                ['COMPOSER_VENDOR_DIR' => $project.'/vendor', 'COMPOSER_HOME' => $project.'/home', 'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('composer could not be started');
            }
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

            if (proc_close($process) !== 0) {
                throw new RuntimeException('composer dump-autoload failed: '.trim($output));
            }

            $map = [];

            foreach ((require $project.'/vendor/composer/autoload_classmap.php') as $type => $file) {
                if (str_starts_with((string) $file, $project.'/packages/')) {
                    $map[$type] = substr((string) $file, strlen($project) + 1);
                }
            }
            ksort($map);

            return $map;
        } finally {
            if (is_dir($project)) {
                if (is_link($project.'/packages')) {
                    unlink($project.'/packages');
                }
                $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

                foreach ($tree as $item) {
                    $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($project);
            }
        }
    }

    /**
     * @param  list<string>|null  $files  absolute paths of the production files; every production root when null
     * @return list<array{file: string, reference: string, kind: string, invariant: string, owner_phase: string|null}>
     */
    public static function violations(Ownership $ownership, ?array $files = null, ?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/');
        $files ??= SourceScan::files(root: $root);
        $entries = self::entries($root);
        $violations = [];

        foreach (self::classMap($files, $root) as $type => $declaredIn) {
            if (count($declaredIn) > 1) {
                $violations[] = self::violation($declaredIn[0], $type, 'duplicate-class', $ownership->fileRow($declaredIn[0]));
            }

            foreach ($declaredIn as $path) {
                $owners = array_filter($entries, static fn (array $entry): bool => str_starts_with($type, $entry['prefix'])
                    && $path === $entry['directory'].'/'.str_replace('\\', '/', substr($type, strlen($entry['prefix']))).'.php');

                if (count($owners) !== 1) {
                    $violations[] = self::violation($path, $type, $owners === [] ? 'no-owner' : 'several-owners', $ownership->fileRow($path));
                }
            }
        }

        foreach (SourceScan::productionRoots($root) as $sourceRoot) {
            foreach (self::MODULE_DIRECTORIES as $directory) {
                $path = str_replace($root.'/', '', $sourceRoot).'/'.$directory;
                $inside = array_values(array_filter($files, static fn (string $file): bool => str_starts_with($file, $root.'/'.$path.'/')));

                if ($inside !== []) {
                    $phase = Ownership::removingPhase(...array_map(static fn (string $file): ?array => $ownership->fileRow(str_replace($root.'/', '', $file)), $inside));
                    $violations[] = ['file' => $path, 'reference' => $directory, 'kind' => 'directory', 'invariant' => 'I5', 'owner_phase' => $phase];
                }
            }
        }
        usort($violations, static fn (array $a, array $b): int => [$a['kind'], $a['file'], $a['reference']] <=> [$b['kind'], $b['file'], $b['reference']]);

        return $violations;
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array{file: string, reference: string, kind: string, invariant: string, owner_phase: string|null}
     */
    private static function violation(string $path, string $type, string $kind, ?array $row): array
    {
        return ['file' => $path, 'reference' => $type, 'kind' => $kind, 'invariant' => 'I5', 'owner_phase' => Ownership::removingPhase($row)];
    }
}
