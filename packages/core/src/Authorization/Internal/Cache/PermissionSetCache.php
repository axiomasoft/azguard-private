<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Cache;

use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory;
use Throwable;

/** @internal Request/job memo and revision-keyed store of raw authority; never a decision cache. */
final class PermissionSetCache
{
    /** @var array<string, array{items: list<Grant|RoleContribution>, validUntil: DateTimeImmutable}> */
    private array $sets = [];

    /** @var array<string, StateToken> */
    private array $states = [];

    /** @var array<string, string> */
    private array $panels = [];

    private readonly int $maxMemoEntries;

    private readonly int $maxStateEntries;

    public function __construct(private readonly Factory $stores)
    {
        $this->maxMemoEntries = AzGuardConfig::memoLimit('max_memo_entries', config('azguard.cache.max_memo_entries', 1024));
        $this->maxStateEntries = AzGuardConfig::memoLimit('max_state_entries', config('azguard.cache.max_state_entries', 1024));
    }

    public function forgetPanel(string $panel): void
    {
        foreach ($this->panels as $key => $id) {
            if ($id === $panel) {
                unset($this->sets[$key], $this->panels[$key]);
            }
        }
        foreach ($this->states as $key => $state) {
            if ($state->panel() === $panel) {
                unset($this->states[$key]);
            }
        }
    }

    /** @param list<AccessScope> $scopes */
    public static function key(StateToken|CodeStateToken $state, SubjectRef $subject, TenantRef $tenant, array $scopes, string $source, string $readMode, string $authority, int $generation): string
    {
        $contexts = array_map(static fn (AccessScope $scope): string => IdentityCodec::compose([$scope]), $scopes);
        sort($contexts, SORT_STRING);
        // ContributionKey (audits/2026-10-09-consistency-design.md, step 4): with the subject's revision the key names
        // what the contributions depend on (incarnation, epoch, revision) and never the panel version, so a write of
        // another subject keeps it. A state without a revision (an opaque source state) keys by its version.
        $revision = match (true) {
            $state instanceof StateToken && $state->subjectRevision() !== null => ['subject', $state->storageId(), $state->panel(), $state->incarnation(),
                $state->epoch(), $state->subjectRevision(), $state->generation(), $state->fingerprint()],
            $state instanceof StateToken => ['storage', $state->storageId(), $state->panel(), $state->incarnation(), $state->version(), $state->epoch(),
                $state->generation(), $state->fingerprint()],
            default => ['code', $state->panel(), $state->buildId(), $state->fingerprint()],
        };

        return 'azguard:sets:v2:'.IdentityCodec::digest([$revision, $generation, $subject, $tenant, array_values(array_unique($contexts)), $source, $readMode, $authority]);
    }

    /** @return list<Grant|RoleContribution>|null */
    public function get(string $key, Panel $panel, Volatility $volatility, bool $fenced, DateTimeImmutable $now): ?array
    {
        if ($volatility === Volatility::Volatile) {
            return null;
        }
        $entry = $this->sets[$key] ?? null;
        $store = $panel->settings()->cacheStore();

        $signer = SignedEnvelope::fromConfig(app('config'));

        if ($entry === null && $volatility === Volatility::Stable && $fenced && $store !== null && $signer !== null) {
            $raw = null;

            try {
                $repository = $this->stores->store($store);
                $raw = $repository->get($key);
                $envelope = $signer->decode($raw, $key, $now);
                $stored = $envelope === null ? null : $this->decode($envelope['payload']);

                if ($stored !== null && count($stored) === 2 && isset($stored['items'], $stored['validUntil']) && is_array($stored['items'])
                && array_is_list($stored['items']) && $stored['validUntil'] instanceof DateTimeImmutable
                && $stored['validUntil'] == $envelope['validUntil']) {
                    // Every item must be a contribution; one of another type discards the whole entry.
                    $items = [];
                    foreach ($stored['items'] as $item) {
                        if (! $item instanceof Grant && ! $item instanceof RoleContribution) {
                            $items = null;

                            break;
                        }
                        // Re-run constructor invariants; serialized objects can bypass their factories.
                        IdentityCodec::decode(IdentityCodec::encode($item->scope));

                        if ($item->role !== null) {
                            IdentityCodec::decode(IdentityCodec::encode($item->role));
                        }

                        if ($item instanceof Grant) {
                            IdentityCodec::decode(IdentityCodec::encode($item->pattern));
                            Grant::of($item->pattern, $item->source, $item->scope, $item->role, $item->origin, $item->expiresAt, $item->fields());
                        } elseif ($item->role instanceof RoleKey) {
                            RoleContribution::of($item->role, $item->scope, $item->source, $item->origin, $item->expiresAt, $item->fields());
                        } else {
                            $items = null;

                            break;
                        }
                        $items[] = $item;
                    }
                    $entry = $items === null ? null : ['items' => $items, 'validUntil' => $stored['validUntil']];
                }

                if ($raw !== null && $entry === null) {
                    $repository->forget($key);
                }
            } catch (Throwable) {
                // A cache failure is a miss; the authoritative source still decides the outcome.
                $entry = null;

                if ($raw !== null) {
                    try {
                        $this->stores->store($store)->forget($key);
                    } catch (Throwable) {
                        // Best effort only.
                    }
                }
            }
        }

        if ($entry === null || $entry['validUntil'] <= $now) {
            unset($this->sets[$key], $this->panels[$key]);

            return null;
        }
        $this->rememberSet($key, $entry, $panel->id());

        return array_values(array_filter($entry['items'], static fn (Grant|RoleContribution $item): bool => $item->activeAt($now)));
    }

    /** @param list<Grant|RoleContribution> $items */
    public function put(string $key, Panel $panel, Volatility $volatility, bool $fenced, array $items, DateTimeImmutable $now): void
    {
        if ($volatility === Volatility::Volatile) {
            return;
        }
        $ttl = $panel->settings()->cacheTtl();
        $validUntil = $ttl === null ? new DateTimeImmutable('9999-12-31T23:59:59Z') : $now->modify('+'.$ttl.' seconds');
        $items = array_values(array_filter($items, static fn (Grant|RoleContribution $item): bool => $item->activeAt($now)));
        foreach ($items as $item) {
            if ($item->expiresAt !== null && $item->expiresAt < $validUntil) {
                $validUntil = $item->expiresAt;
            }
        }
        $entry = ['items' => $items, 'validUntil' => $validUntil];
        $this->rememberSet($key, $entry, $panel->id());
        $store = $panel->settings()->cacheStore();

        $signer = SignedEnvelope::fromConfig(app('config'));

        if ($volatility === Volatility::Stable && $fenced && $store !== null && $signer !== null) {
            $sharedUntil = min($validUntil, $now->modify('+'.min($ttl ?? 300, SignedEnvelope::MAX_TTL).' seconds'));
            $sharedEntry = ['items' => $items, 'validUntil' => $sharedUntil];
            $envelope = $signer->encode($key, serialize($sharedEntry), $now, $sharedUntil);

            if ($envelope !== null) {
                try {
                    $this->stores->store($store)->put($key, $envelope, $sharedUntil);
                } catch (Throwable) {
                    // Shared storage is optional; the local entry remains valid.
                }
            }
        }
    }

    /**
     * The shared store keeps the entry as a string, because Laravel 13 unserializes cached values with
     * `cache.serializable_classes` false by default and would turn every object of an array entry into an
     * incomplete class; only the kernel value objects of a set are allowed back.
     *
     * @return array<mixed>|null
     */
    private function decode(mixed $stored): ?array
    {
        if (! is_string($stored)) {
            return null;
        }
        $entry = @unserialize($stored, ['max_depth' => 64, 'allowed_classes' => [
            Grant::class, RoleContribution::class, PermissionPattern::class, RoleKey::class, AccessScope::class,
            TenantRef::class, AssignmentScopeRef::class, DateTimeImmutable::class,
        ]]);

        return is_array($entry) ? $entry : null;
    }

    public function state(string $key): ?StateToken
    {
        $state = $this->states[$key] ?? null;

        if ($state !== null) {
            unset($this->states[$key]);
            $this->states[$key] = $state;
        }

        return $state;
    }

    public function rememberState(string $key, StateToken $state): void
    {
        unset($this->states[$key]);
        $this->states[$key] = $state;

        if (count($this->states) > $this->maxStateEntries) {
            unset($this->states[array_key_first($this->states)]);
        }
    }

    public function forgetState(string $key): void
    {
        unset($this->states[$key]);
    }

    /** @param array{items: list<Grant|RoleContribution>, validUntil: DateTimeImmutable} $entry */
    private function rememberSet(string $key, array $entry, string $panel): void
    {
        unset($this->sets[$key], $this->panels[$key]);
        $this->sets[$key] = $entry;
        $this->panels[$key] = $panel;

        if (count($this->sets) > $this->maxMemoEntries) {
            $oldest = array_key_first($this->sets);
            unset($this->sets[$oldest], $this->panels[$oldest]);
        }
    }
}
