<?php

declare(strict_types=1);

function apiManifestRoot(): string
{
    return dirname(__DIR__, 2);
}

it('keeps both api manifests current', function (): void {
    $process = proc_open(
        [PHP_BINARY, apiManifestRoot().'/bin/api-manifest.php', '--check'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        apiManifestRoot(),
    );

    expect($process)->toBeResource();

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $output);
});

it('lets the filament package use only core classes from the manifest', function (): void {
    /** @var array{classes: list<array{name: string}>} $manifest */
    $manifest = json_decode((string) file_get_contents(apiManifestRoot().'/packages/core/api-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $public = array_column($manifest['classes'], 'name');
    $used = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(apiManifestRoot().'/packages/filament/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($token[1], '\\');

                if (str_starts_with($name, 'AzGuard\\') && ! str_starts_with($name.'\\', 'AzGuard\\Filament\\')) {
                    $used[$name] = $file->getFilename();
                }
            }
        }
    }

    expect(array_keys(array_diff_key($used, array_flip($public))))->toBe([]);
});

it('excludes internal classes from publication by their namespace', function (): void {
    $manifest = json_decode((string) file_get_contents(apiManifestRoot().'/packages/core/api-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $internal = [];

    foreach ($manifest['classes'] as $entry) {
        if (preg_match('/^\s*\*\s*@internal\b/m', (string) (new ReflectionClass($entry['name']))->getDocComment()) === 1) {
            $internal[] = $entry['name'];
        }
    }

    expect($internal)->toBe([]);
});
