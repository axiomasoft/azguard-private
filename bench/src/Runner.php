<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Forks the workers of one stage (closed model, as the direct runner of chatom bench). Workers wait for one shared
 * start instant, run their warm-up, then their measured iterations, and write raw samples to `w{n}.ndjson`; the parent
 * only reaps them. A worker ends with SIGKILL after it wrote its `.ok` marker, so no shutdown handler of the framework
 * runs twice; a worker without the marker crashed.
 */
final class Runner
{
    private const int ERROR_LOG_LIMIT = 5;

    /**
     * @return array{samples: list<array{op: string, us: float, sql: int, ok: bool, t: int}>, window_s: float}
     */
    public function run(Stand $stand, Tier $tier, Profile $profile, Stage $stage, string $directory): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The bench runner needs the pcntl extension.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create [{$directory}].");
        }
        array_map(unlink(...), glob($directory.'/w*') ?: []);

        $stand->disconnect();
        // Forking and opening connections takes time: every worker starts measuring at the same instant.
        $start = hrtime(true) + 300_000_000 + $stage->workers * 30_000_000;
        $pids = [];

        for ($worker = 0; $worker < $stage->workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork failed.');
            }

            if ($pid === 0) {
                $this->work($stand, $tier, $profile, $stage, $worker, $directory, $start);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[$pid] = $worker;
        }

        $crashed = 0;
        foreach ($pids as $pid => $worker) {
            pcntl_waitpid($pid, $status);
            $crashed += is_file("{$directory}/w{$worker}.ok") ? 0 : 1;
        }

        if ($crashed > 0) {
            throw new RuntimeException("{$crashed} worker(s) of [{$profile->id()}/{$stage->name}] crashed; see {$directory}/w*.err.");
        }

        $samples = [];
        $first = PHP_INT_MAX;
        $last = 0;
        for ($worker = 0; $worker < $stage->workers; $worker++) {
            $window = json_decode((string) file_get_contents("{$directory}/w{$worker}.ok"), true);

            if (is_array($window) && is_int($window['from'] ?? null) && is_int($window['to'] ?? null)) {
                $first = min($first, $window['from']);
                $last = max($last, $window['to']);
            }
            foreach (file("{$directory}/w{$worker}.ndjson", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $sample = json_decode($line, true);

                if (is_array($sample) && is_string($sample['op'] ?? null) && is_numeric($sample['us'] ?? null) && is_int($sample['sql'] ?? null) && is_bool($sample['ok'] ?? null) && is_int($sample['t'] ?? null)) {
                    $samples[] = ['op' => $sample['op'], 'us' => (float) $sample['us'], 'sql' => $sample['sql'], 'ok' => $sample['ok'], 't' => $sample['t']];
                }
            }
        }

        return ['samples' => $samples, 'window_s' => $last > $first ? ($last - $first) / 1e9 : 0.0];
    }

    private function work(Stand $stand, Tier $tier, Profile $profile, Stage $stage, int $worker, string $directory, int $start): void
    {
        $errors = 0;

        try {
            mt_srand(20261009 + $worker);
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $profile->boot($stand, $tier, $stage, $worker);
            $out = fopen("{$directory}/w{$worker}.ndjson", 'wb') ?: throw new RuntimeException('Cannot write samples.');

            for ($i = 0; $i < $stage->warmup; $i++) {
                try {
                    $profile->iteration($stand, $tier, $stage, $worker, $i);
                } catch (Throwable $e) {
                    if ($errors++ < self::ERROR_LOG_LIMIT) {
                        file_put_contents("{$directory}/w{$worker}.err", 'warm-up: '.$e::class.': '.$e->getMessage()."\n", FILE_APPEND);
                    }
                }
            }

            while (hrtime(true) < $start) {
                usleep(1_000);
            }
            $from = hrtime(true);

            for ($i = 0; $i < $stage->iterations; $i++) {
                $before = $queries;
                $t0 = hrtime(true);

                try {
                    $op = $profile->iteration($stand, $tier, $stage, $worker, $stage->warmup + $i);
                    $ok = true;
                } catch (Throwable $e) {
                    $op = 'error';
                    $ok = false;

                    if ($errors++ < self::ERROR_LOG_LIMIT) {
                        file_put_contents("{$directory}/w{$worker}.err", $e::class.': '.$e->getMessage()."\n", FILE_APPEND);
                    }
                }
                $t1 = hrtime(true);
                fwrite($out, json_encode(['op' => $op, 'us' => round(($t1 - $t0) / 1e3, 1), 'sql' => $queries - $before, 'ok' => $ok, 't' => $t1 - $from], JSON_THROW_ON_ERROR)."\n");
            }
            fclose($out);
            file_put_contents("{$directory}/w{$worker}.ok", json_encode(['from' => $from, 'to' => hrtime(true)], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            file_put_contents("{$directory}/w{$worker}.err", $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n", FILE_APPEND);
        }
    }
}
