<?php

declare(strict_types=1);

namespace AzGuard\Tests\Engines\Support;

use RuntimeException;

final class Processes
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<array<int, resource>> */
    private array $pipes = [];

    /** @param array<string, mixed> $options */
    public function start(array $options): int
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/mutate-worker.php', json_encode($options, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start worker.');
        }
        fclose($pipes[0]);
        $this->processes[] = $process;
        $this->pipes[] = $pipes;

        return count($this->processes) - 1;
    }

    /** @return array{attempts: int} */
    public function finish(int $index): array
    {
        $stdout = stream_get_contents($this->pipes[$index][1]);
        $stderr = stream_get_contents($this->pipes[$index][2]);
        fclose($this->pipes[$index][1]);
        fclose($this->pipes[$index][2]);
        $exit = proc_close($this->processes[$index]);

        if ($exit !== 0) {
            throw new RuntimeException('Worker exited '.$exit.': '.$stderr.' '.$stdout);
        }

        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function wait(string $file): void
    {
        $deadline = microtime(true) + 20;
        while (! file_exists($file)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout: '.$file);
            }
            usleep(1000);
        }
    }
}
