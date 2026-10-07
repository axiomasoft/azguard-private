<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Cache;

use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory;

/** @internal Request/job memo and revision-keyed store of raw authority; never a decision cache. */
final class PermissionSetCache
{
    /** @var array<string, array{items: list<Grant|RoleContribution>, validUntil: DateTimeImmutable}> */
    private array $sets = [];

    /** @var array<string, StateToken> */
    private array $states = [];

    /** @var array<string, string> */
    private array $panels = [];

    public function __construct(private readonly Factory $stores) {}

    public function forgetPanel(string $panel): void
    {
        foreach ($this->panels as $key => $id) {
            if ($id === $panel) {
                unset($this->sets[$key], $this->panels[$key]);
            }
        }
        foreach ($this->states as $key => $state) {
            if ($state->panel === $panel) {
                unset($this->states[$key]);
            }
        }
    }

    /** @param list<AccessScope> $scopes */
    public static function key(StateToken|CodeStateToken $state, SubjectRef $subject, TenantRef $tenant, array $scopes, string $source, string $readMode, string $authority, int $generation): string
    {
        $contexts = array_map(static fn (AccessScope $scope): string => IdentityCodec::compose([$scope]), $scopes);
        sort($contexts, SORT_STRING);
        $revision = $state instanceof StateToken
            ? ['storage', $state->storageId, $state->panel, $state->incarnation, $state->version, $state->generation, $state->fingerprint]
            : ['code', $state->panel, $state->buildId, $state->fingerprint];

        return 'azguard:sets:'.IdentityCodec::digest([$revision, $generation, $subject, $tenant, array_values(array_unique($contexts)), $source, $readMode, $authority]);
    }

    /** @return list<Grant|RoleContribution>|null */
    public function get(string $key, Panel $panel, Volatility $volatility, bool $fenced, DateTimeImmutable $now): ?array
    {
        if ($volatility === Volatility::Volatile) {
            return null;
        }
        $entry = $this->sets[$key] ?? null;
        $store = $panel->settings()->cacheStore();

        if ($entry === null && $volatility === Volatility::Stable && $fenced && $store !== null) {
            $stored = $this->stores->store($store)->get($key);

            if (is_array($stored) && isset($stored['items'], $stored['validUntil']) && is_array($stored['items'])
                && array_is_list($stored['items']) && $stored['validUntil'] instanceof DateTimeImmutable
                && count(array_filter($stored['items'], static fn (mixed $item): bool => $item instanceof Grant || $item instanceof RoleContribution)) === count($stored['items'])) {
                $entry = ['items' => $stored['items'], 'validUntil' => $stored['validUntil']];
            }
        }

        if ($entry === null || $entry['validUntil'] <= $now) {
            unset($this->sets[$key], $this->panels[$key]);

            return null;
        }
        $this->sets[$key] = $entry;
        $this->panels[$key] = $panel->id();

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
        $this->sets[$key] = $entry;
        $this->panels[$key] = $panel->id();
        $store = $panel->settings()->cacheStore();

        if ($volatility === Volatility::Stable && $fenced && $store !== null) {
            $this->stores->store($store)->put($key, $entry, $validUntil);
        }
    }

    public function state(string $key): ?StateToken
    {
        return $this->states[$key] ?? null;
    }

    public function rememberState(string $key, StateToken $state): void
    {
        if (isset($this->states[$key]) && ! $this->states[$key]->equals($state)) {
            $this->sets = [];
            $this->panels = [];
        }
        $this->states[$key] = $state;
    }

    public function forgetState(string $key): void
    {
        unset($this->states[$key]);
        $this->sets = [];
        $this->panels = [];
    }
}
