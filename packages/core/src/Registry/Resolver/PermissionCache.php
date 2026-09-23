<?php

declare(strict_types=1);

namespace AzGuard\Registry\Resolver;

use AzGuard\Configuration\Config;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Runtime\RequestState;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-request (and optional cross-request) cache for PermissionSet.
 *
 * Supports two layers:
 * 1. In-memory (always): $requestCache 2D array — lives for one HTTP request.
 * 2. Cross-request (optional): Laravel cache store (Redis / file / etc.).
 *
 * Octane-safe ONLY when bound as `scoped` (see AzGuardServiceProvider): the
 * in-memory $requestCache must not survive across requests on a reused worker,
 * or one user's resolved permissions would bleed into the next request.
 * Concurrent in-process calls compute the value twice and last writer wins
 * in the array — harmless and far safer than any locking strategy.
 *
 * Durable payloads are a strict v2 envelope `{version, keys, valid_until}`.
 * Hit-time `now >= validUntil` is a miss; backend TTL is eviction only.
 *
 * @internal
 */
class PermissionCache
{
    private const ENVELOPE_VERSION = 2;

    public function __construct(
        private readonly RequestState $requestState = new RequestState,
        private readonly PermissionStateRevision $permissionState = new PermissionStateRevision,
    ) {}

    /**
     * @var array<string, array<string, array<string, array<int, array<int, PermissionSet>>>>>
     *                                                                                         subjectDigest => panelId => discriminator => generation => revision => PermissionSet
     */
    private array $requestCache = [];

    /**
     * The optional $discriminator distinguishes entries that depend on
     * out-of-band state (e.g. the active workspace context) so two contexts on
     * the same panel never share a cached set. Supplied by a PermissionLayer.
     */
    public function rememberForRequest(SubjectIdentity $subject, string $panelId, Closure $callback, string $discriminator = ''): PermissionSet
    {
        if ($this->permissionState->inTransaction()) {
            $set = $this->materialize($callback);

            return $set instanceof PermissionSet ? $set : PermissionSet::empty();
        }

        $revision = $this->permissionState->current();
        $generation = Config::cacheGeneration();
        $subjectKey = $subject->digest();
        $cached = $this->requestCache[$subjectKey][$panelId][$discriminator][$generation][$revision] ?? null;

        if ($cached instanceof PermissionSet && $this->isLive($cached)) {
            return $cached;
        }

        if ($cached instanceof PermissionSet) {
            unset($this->requestCache[$subjectKey][$panelId][$discriminator][$generation][$revision]);
        }

        $store = Config::cacheStore();
        $epoch = $this->currentEpoch($subject, $panelId);
        $set = $store !== 'array' && $epoch !== null
            ? $this->loadFromStore($this->keyFor($subject, $panelId, $discriminator, $revision), $store, $callback, $revision)
            : $this->materialize($callback);

        if (! $set instanceof PermissionSet) {
            return PermissionSet::empty();
        }

        if ($this->permissionState->current() !== $revision || Config::cacheGeneration() !== $generation) {
            return $set;
        }

        return $this->requestCache[$subjectKey][$panelId][$discriminator][$generation][$revision] = $set;
    }

    public function forgetForUser(SubjectIdentity $subject, string $panelId): void
    {
        unset($this->requestCache[$subject->digest()][$panelId]);

        if ($this->permissionState->inTransaction()) {
            return;
        }

        $store = Config::cacheStore();

        if ($store === 'array') {
            return;
        }

        $epochStore = cache()->store($store);
        $epochKey = $this->epochStorageKey($subject, $panelId);
        $bump = function () use ($epochStore, $epochKey): void {
            $epochStore->add($epochKey, 1, Config::cacheTtl());
            $epoch = $epochStore->increment($epochKey);
            $epochStore->put($epochKey, $epoch, Config::cacheTtl());
        };

        try {
            $lockStore = $epochStore->getStore();

            if ($lockStore instanceof LockProvider) {
                $lockStore->lock($epochKey.':lock', 5)->block(2, $bump);
            } else {
                $this->requestState->once(
                    'azguard.epoch-bump-without-lock.'.$store,
                    fn () => Log::warning('AzGuard: bumping the permission cache epoch without a lock — store does not implement LockProvider', [
                        'store' => $store,
                    ]),
                );

                $bump();
            }
        } catch (Throwable $e) {
            Log::warning('AzGuard: permission cache epoch bump failed', [
                'store' => $store,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * In-process-only invalidation: drops the cached PermissionSet(s) for this
     * user+panel from the request-local array WITHOUT bumping the durable
     * per-user epoch.
     */
    public function forgetRequestCache(SubjectIdentity $subject, string $panelId): void
    {
        unset($this->requestCache[$subject->digest()][$panelId]);
    }

    public function forgetAll(): void
    {
        $this->requestCache = [];
    }

    public function keyFor(SubjectIdentity $subject, string $panelId, string $discriminator = '', ?int $revision = null): string
    {
        $epoch = $this->currentEpoch($subject, $panelId) ?? 1;
        $revision ??= $this->permissionState->inTransaction()
            ? 0
            : $this->permissionState->current();

        return 'azg:v2:perm:'.SubjectIdentity::digestPayload([
            2,
            'perm',
            $subject->digest(),
            $panelId,
            $discriminator,
            $epoch,
            $revision,
            Config::cacheGeneration(),
        ]);
    }

    public function epochStorageKey(SubjectIdentity $subject, string $panelId): string
    {
        return 'azg:v2:epoch:'.SubjectIdentity::digestPayload([
            2,
            'epoch',
            $subject->digest(),
            $panelId,
        ]);
    }

    private function currentEpoch(SubjectIdentity $subject, string $panelId): ?int
    {
        $store = Config::cacheStore();

        if ($store === 'array') {
            return 1;
        }

        try {
            return (int) cache()->store($store)->get($this->epochStorageKey($subject, $panelId), 1);
        } catch (Throwable $e) {
            Log::warning('AzGuard: permission cache epoch read failed', [
                'store' => $store,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function loadFromStore(string $cacheKey, string $store, Closure $callback, int $revision): ?PermissionSet
    {
        try {
            $raw = cache()->store($store)->get($cacheKey);
        } catch (Throwable $e) {
            Log::warning('AzGuard: permission cache get failed', [
                'store' => $store,
                'error' => $e->getMessage(),
            ]);

            return $this->materialize($callback);
        }

        $decoded = $this->decodeEnvelope($raw);

        if ($decoded instanceof PermissionSet && $this->isLive($decoded)) {
            return $decoded;
        }

        $set = $this->materialize($callback);

        if (! $set instanceof PermissionSet) {
            return null;
        }

        if ($this->permissionState->current() !== $revision) {
            return $set;
        }

        try {
            cache()->store($store)->put($cacheKey, $this->encodeEnvelope($set), $this->backendExpiry($set));
        } catch (Throwable $e) {
            Log::warning('AzGuard: permission cache put failed', [
                'store' => $store,
                'error' => $e->getMessage(),
            ]);
        }

        return $set;
    }

    /**
     * @return array{version: int, keys: list<string>, valid_until: ?string}
     */
    private function encodeEnvelope(PermissionSet $set): array
    {
        return [
            'version' => self::ENVELOPE_VERSION,
            'keys' => $set->keys(),
            'valid_until' => $set->validUntil()?->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    private function decodeEnvelope(mixed $raw): ?PermissionSet
    {
        if (! is_array($raw)) {
            return null;
        }

        $fields = array_keys($raw);
        sort($fields);

        if ($fields !== ['keys', 'valid_until', 'version']) {
            return null;
        }

        if ($raw['version'] !== self::ENVELOPE_VERSION) {
            return null;
        }

        if (! is_array($raw['keys'])) {
            return null;
        }

        $keys = [];

        foreach ($raw['keys'] as $key) {
            if (! is_string($key)) {
                return null;
            }

            $keys[] = $key;
        }

        $until = $raw['valid_until'];

        if ($until === null) {
            return PermissionSet::fromKeys($keys);
        }

        if (! is_string($until)) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $until, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable) {
            return null;
        }

        return PermissionSet::fromKeys($keys)->withValidUntil($parsed);
    }

    private function materialize(Closure $callback): ?PermissionSet
    {
        $set = $callback();

        if (! $set instanceof PermissionSet || ! $this->isLive($set)) {
            return null;
        }

        return $set;
    }

    private function isLive(PermissionSet $set): bool
    {
        $until = $set->validUntil();

        return ! $until instanceof CarbonImmutable || now()->lt($until);
    }

    private function backendExpiry(PermissionSet $set): DateTimeInterface|int|null
    {
        $configured = Config::cacheTtl();
        $deadline = $set->validUntil();

        if (! $deadline instanceof CarbonImmutable) {
            return $configured;
        }

        if ($configured === null) {
            return $deadline;
        }

        $configuredAt = now()->addSeconds($configured);

        return $configuredAt->lt($deadline) ? $configured : $deadline;
    }
}
