<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Support\Facades\Cache;
use Redis;
use ReflectionProperty;
use RuntimeException;

/** Revision-key churn, bounded request maps and finite Redis expiry. */
final class CacheChurn extends BaseProfile
{
    private array $baseline = [];

    private int $redisBefore = 0;

    private int $rssBefore = 0;

    private int $rssPeak = 0;

    private int $maxSets = 0;

    private int $maxStates = 0;

    public function id(): string
    {
        return 'cache-churn';
    }

    public function description(): string
    {
        return 'Grant/revoke churn with bounded request maps and Redis expiry';
    }

    public function stages(Tier $tier): array
    {
        return [new Stage('cycles', 1, $tier->name === 'smoke' ? 10 : 10000, 0)];
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        AzGuard::actingAs('benchmark', fn () => $this->subject(2, 'churn')->revokePermission('g50.p20'));
        $stand->newRequest();
        $this->baseline = $stand->cache === 'redis' ? $this->keys() : [];
        $this->redisBefore = $stand->cache === 'redis' ? $this->redisMemory() : 0;
    }

    public function boot(Stand $stand, Tier $tier, Stage $stage, int $worker): void
    {
        $this->rssBefore = $this->rss();
        $this->rssPeak = $this->rssBefore;
        $this->maxSets = $this->maxStates = 0;
        // Keep one request alive past both configured limits. Subjects are model identities, not database writes.
        $limit = max((int) config('azguard.cache.max_memo_entries'), (int) config('azguard.cache.max_state_entries'));
        for ($i = 0; $i <= $limit; $i++) {
            if ($this->check($this->subject(100000000 + $i, 'churn'), 'g50.p20')) {
                throw new RuntimeException('An ungranted subject was allowed.');
            }
        }
        $this->observeBounds();
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $subject = $this->subject(2, 'churn');
        AzGuard::actingAs('benchmark', fn () => $subject->grantPermission('g50.p20'));

        if (! $this->check($subject, 'g50.p20')) {
            throw new RuntimeException('Grant was not visible.');
        }
        AzGuard::actingAs('benchmark', fn () => $subject->revokePermission('g50.p20'));

        if ($this->check($subject, 'g50.p20')) {
            throw new RuntimeException('Revoke was not visible.');
        }
        $this->observeBounds();

        return 'cycle';
    }

    private function observeBounds(): void
    {
        $memo = app(PermissionSetCache::class);
        $this->maxSets = max($this->maxSets, count((new ReflectionProperty($memo, 'sets'))->getValue($memo)));
        $this->maxStates = max($this->maxStates, count((new ReflectionProperty($memo, 'states'))->getValue($memo)));
        $this->rssPeak = max($this->rssPeak, $this->rss());
        self::assertBounds($this->maxSets, $this->maxStates, $this->rssPeak - $this->rssBefore,
            (int) config('azguard.cache.max_memo_entries'), (int) config('azguard.cache.max_state_entries'));
    }

    private static function assertBounds(int $sets, int $states, int $growth, int $setLimit, int $stateLimit): void
    {
        if ($sets > $setLimit || $states > $stateLimit || $growth > 134217728) {
            throw new RuntimeException('Request memo or RSS bound exceeded.');
        }
    }

    public function measurements(): array
    {
        return ['rss_start_bytes' => $this->rssBefore, 'rss_end_bytes' => $this->rss(), 'rss_peak_bytes' => $this->rssPeak,
            'max_request_sets' => $this->maxSets, 'max_request_states' => $this->maxStates];
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $stand->newRequest();
        $denied = ! $this->check($this->subject(2, 'churn'), 'g50.p20');
        $checks = [['name' => 'last revoke visible', 'ok' => $denied, 'detail' => 'last permission denied']];

        if ($stand->cache !== 'redis') {
            return $checks;
        }
        $new = array_diff($this->keys(), $this->baseline);
        $client = Cache::store('redis')->getStore()->connection()->client();
        $finite = $new !== [];
        foreach ($new as $key) {
            $ttl = $client->rawCommand('TTL', $key);
            $finite = $finite && is_int($ttl) && self::finiteTtl($ttl);
        }
        $memoryAfter = $this->redisMemory();
        sleep(3);
        $resident = array_diff($this->keys(), $this->baseline);
        $checks[] = ['name' => 'churn keys expire', 'ok' => $finite && $resident === [],
            'detail' => 'new keys '.count($new).', remaining '.count($resident).', Redis used_memory bytes '.$this->redisBefore.' -> '.$memoryAfter.' -> '.$this->redisMemory()];

        return $checks;
    }

    private function keys(): array
    {
        $store = Cache::store('redis')->getStore();
        $client = $store->connection()->client();
        $prefix = (string) $client->getOption(Redis::OPT_PREFIX).$store->getPrefix();

        if (! str_contains($prefix, 'azguard_bench_')) {
            throw new RuntimeException('Refusing unisolated Redis.');
        }
        $cursor = '0';
        $found = [];
        do {
            [$cursor, $keys] = $client->rawCommand('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '1000');
            foreach ($keys as $key) {
                if (str_starts_with($key, $prefix)) {
                    $found[] = $key;
                }
            }
        } while ((string) $cursor !== '0');

        return $found;
    }

    private static function finiteTtl(int $ttl): bool
    {
        // -2 means the key expired after SCAN; -1 means that it has no expiry.
        return $ttl === -2 || ($ttl >= 0 && $ttl <= 2);
    }

    private function redisMemory(): int
    {
        $info = Cache::store('redis')->getStore()->connection()->client()->info('memory');

        return (int) ($info['used_memory'] ?? 0);
    }

    private function rss(): int
    {
        $status = @file_get_contents('/proc/self/status');

        if (! is_string($status) || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $match) !== 1) {
            throw new RuntimeException('RSS is unavailable.');
        }

        return (int) $match[1] * 1024;
    }
}
