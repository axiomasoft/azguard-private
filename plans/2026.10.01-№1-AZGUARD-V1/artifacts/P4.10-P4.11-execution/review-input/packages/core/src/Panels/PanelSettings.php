<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Exceptions\DefinitionException;

/**
 * Effective settings of a compiled panel and where each value came from.
 *
 * A value is taken from the panel provider, otherwise from a plugin, otherwise from `configure` for all panels,
 * otherwise from the `defaults` section of the configuration.
 *
 * @api
 */
final readonly class PanelSettings
{
    public const string RESOURCE_PREFIX = 'resource_prefix';

    public const string GATE_MODE = 'gate.mode';

    public const string CACHE_STORE = 'cache.store';

    public const string CACHE_TTL = 'cache.ttl';

    public const string CACHE_GENERATION = 'cache.generation';

    public const string READS = 'consistency.reads';

    public const string STATE_REFRESH = 'consistency.state_refresh';

    public const string TRACE_DECISIONS = 'trace_decisions';

    /** Values used when neither the panel nor the configuration sets a setting. */
    public const array DEFAULTS = [
        self::RESOURCE_PREFIX => true,
        self::GATE_MODE => 'authoritative',
        self::CACHE_STORE => null,
        self::CACHE_TTL => 3600,
        self::CACHE_GENERATION => 1,
        self::READS => 'primary',
        self::STATE_REFRESH => 'request',
        self::TRACE_DECISIONS => false,
    ];

    /**
     * @internal built by the panel compiler
     *
     * @param  array<string, string>  $origins  setting name => `provider`, `plugin:<id>`, `configure` or `default`
     */
    public function __construct(
        private ?string $resourcePrefix,
        private GateMode $gateMode,
        private ?string $cacheStore,
        private ?int $cacheTtl,
        private int $cacheGeneration,
        private Reads $reads,
        private StateRefresh $stateRefresh,
        private bool $traceDecisions,
        private array $origins,
    ) {}

    /**
     * The segment that qualifies permission names of the panel, or null when the prefix is turned off.
     */
    public function resourcePrefix(): ?string
    {
        return $this->resourcePrefix;
    }

    public function gateMode(): GateMode
    {
        return $this->gateMode;
    }

    /**
     * The cache store of permission sets; null keeps them for the request only.
     */
    public function cacheStore(): ?string
    {
        return $this->cacheStore;
    }

    /**
     * Seconds a permission set stays in the cache store.
     */
    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    public function cacheGeneration(): int
    {
        return $this->cacheGeneration;
    }

    public function reads(): Reads
    {
        return $this->reads;
    }

    public function stateRefresh(): StateRefresh
    {
        return $this->stateRefresh;
    }

    /**
     * Whether every check dispatches a decision event for diagnostics.
     */
    public function traceDecisions(): bool
    {
        return $this->traceDecisions;
    }

    /**
     * Where the effective value of a setting came from.
     *
     * @param  string  $setting  a setting name such as `cache.ttl`, one of the constants of this class
     * @return string `provider`, `plugin:<id>`, `configure` or `default`
     *
     * @throws DefinitionException when the setting does not exist
     */
    public function origin(string $setting): string
    {
        return $this->origins[$setting] ?? throw new DefinitionException(
            'Unknown panel setting "'.$setting.'". Settings: '.implode(', ', array_keys(self::DEFAULTS)).'.',
        );
    }

    /**
     * @return array<string, array{value: bool|int|string|null, origin: string}> every setting by name
     */
    public function toArray(): array
    {
        $values = [
            self::RESOURCE_PREFIX => $this->resourcePrefix,
            self::GATE_MODE => $this->gateMode->value,
            self::CACHE_STORE => $this->cacheStore,
            self::CACHE_TTL => $this->cacheTtl,
            self::CACHE_GENERATION => $this->cacheGeneration,
            self::READS => $this->reads->value,
            self::STATE_REFRESH => $this->stateRefresh->value,
            self::TRACE_DECISIONS => $this->traceDecisions,
        ];

        $settings = [];

        foreach ($values as $setting => $value) {
            $settings[$setting] = ['value' => $value, 'origin' => $this->origins[$setting]];
        }

        return $settings;
    }
}
