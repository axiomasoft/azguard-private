<?php

declare(strict_types=1);

namespace AzGuard\Tests\Engines\Support;

use RuntimeException;

/** An independent application/DB handle with line-delimited, acknowledged commands. */
final class AuthorityProcess
{
    public readonly int $connectionId;

    private mixed $process;

    private array $pipes = [];

    public function __construct()
    {
        $this->process = proc_open([PHP_BINARY, __DIR__.'/authority-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);

        if (! is_resource($this->process)) {
            throw new RuntimeException('Cannot start authority worker.');
        }
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->connectionId = (int) $this->receive()['connection_id'];
    }

    public function send(array $command): void
    {
        fwrite($this->pipes[0], json_encode($command, JSON_THROW_ON_ERROR)."\n");
        fflush($this->pipes[0]);
    }

    public function receive(): array
    {
        $deadline = microtime(true) + 20;
        $line = '';
        do {
            $line .= (string) fgets($this->pipes[1]);

            if (str_ends_with($line, "\n")) {
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (isset($result['error'])) {
                    throw new RuntimeException($result['error']);
                }

                return $result;
            }

            if (! proc_get_status($this->process)['running'] || microtime(true) >= $deadline) {
                throw new RuntimeException('Authority worker failed/timed out: '.stream_get_contents($this->pipes[2]).' '.$line);
            }
            usleep(1000);
        } while (true);
    }

    public function command(array $command): array
    {
        $this->send($command);

        return $this->receive();
    }

    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }
        fclose($this->pipes[0]);

        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
