<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal\Scaffold;

use AzGuard\Laravel\Console\InvalidCommandInput;
use Packman\Attributes\Internal;
use Packman\DevTools\Scaffold\GeneratedFile as PackmanGeneratedFile;
use RuntimeException;
use Throwable;

/**
 * Atomically writes planned scaffold files via temp-file staging and POSIX rename with rollback on failure.
 *
 * @internal
 */
#[Internal]
final readonly class AtomicFileWriter
{
    /**
     * @param  list<array{target: string, file: PackmanGeneratedFile}>  $planned
     * @return list<array{path: string, overwritten: bool}>
     */
    public function write(array $planned): array
    {
        $results = [];
        $staged = [];
        $committed = [];

        try {
            foreach ($planned as $entry) {
                $target = $entry['target'];
                $file = $entry['file'];
                $dir = dirname($target);

                if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                    throw new RuntimeException('Unable to create directory '.$dir.'.');
                }

                $overwritten = is_file($target);

                if ($overwritten && ! $file->overwrite) {
                    throw new InvalidCommandInput($target.' already exists.');
                }

                $tmp = tempnam($dir, '.azguard-scaffold-');

                if ($tmp === false) {
                    throw new RuntimeException('Unable to create temporary file in '.$dir.'.');
                }

                if (file_put_contents($tmp, $file->contents) === false) {
                    @unlink($tmp);

                    throw new RuntimeException('Unable to write staged scaffold file for '.$target.'.');
                }

                $mode = $overwritten ? (fileperms($target) & 0o777) : (0o666 & ~umask());
                @chmod($tmp, $mode);

                $backup = null;

                if ($overwritten) {
                    $raw = file_get_contents($target);

                    if ($raw !== false) {
                        $backup = $raw;
                    }
                }

                $staged[] = ['tmp' => $tmp, 'target' => $target, 'overwritten' => $overwritten, 'backup' => $backup];
            }

            foreach ($staged as $index => $item) {
                if (! @rename($item['tmp'], $item['target'])) {
                    throw new RuntimeException('Unable to move scaffold file into place: '.$item['target'].'.');
                }

                unset($staged[$index]);
                $committed[] = $item;
                $results[] = ['path' => $item['target'], 'overwritten' => $item['overwritten']];
            }

            return $results;
        } catch (Throwable $error) {
            foreach (array_reverse($committed) as $item) {
                if ($item['overwritten'] && $item['backup'] !== null) {
                    @file_put_contents($item['target'], $item['backup']);
                } elseif (! $item['overwritten'] && is_file($item['target'])) {
                    @unlink($item['target']);
                }
            }

            throw $error;
        } finally {
            foreach ($staged as $item) {
                if (is_file($item['tmp'])) {
                    @unlink($item['tmp']);
                }
            }
        }
    }
}
