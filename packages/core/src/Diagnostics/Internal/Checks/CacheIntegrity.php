<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Kernel\Internal\Narrow;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/** @internal Inspects configuration only; it never opens or writes to a cache. */
final readonly class CacheIntegrity implements DoctorCheck
{
    public function __construct(private Repository $config) {}

    public function key(): string
    {
        return 'cache.integrity';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach (['max_memo_entries', 'max_state_entries'] as $name) {
            try {
                AzGuardConfig::memoLimit($name, $this->config->get('azguard.cache.'.$name, 1024));
            } catch (Throwable) {
                yield DoctorFinding::error('cache.memory', 'Set both cache memory limits to integers between 1 and 1024.');

                break;
            }
        }

        foreach ($context->panels() as $panel) {
            $store = $panel->settings()->cacheStore();

            if ($store === null) {
                continue;
            }
            $scope = 'panel:'.$panel->id();
            $driver = $this->config->get('cache.stores.'.$store.'.driver');

            if ($driver === 'array') {
                continue;
            }

            if ($driver !== 'redis') {
                yield DoctorFinding::warning('cache.transport', 'The configured cache transport is not supported for shared permission sets; the authoritative source is used.', $scope);

                continue;
            }

            if (! $context->config()->cacheSigningAvailable()) {
                $message = 'Configure a valid cache signing key and explicit namespace, including valid rotation keys; shared caching is disabled.';
                yield $context->isProduction()
                    ? DoctorFinding::error('cache.signing', $message, $scope)
                    : DoctorFinding::warning('cache.signing', $message, $scope);
            }

            $connection = Narrow::string($this->config->get('cache.stores.'.$store.'.connection') ?: 'default', 'Redis cache connection');
            $connectionConfig = $this->config->get('database.redis.'.$connection);
            $options = (array) $this->config->get('database.redis.options', []);

            // Follow RedisManager's standalone-before-cluster resolution and the connector's option order.
            if ($connectionConfig === null && $this->config->has('database.redis.clusters.'.$connection)) {
                $options = array_replace(
                    $options,
                    (array) $this->config->get('database.redis.clusters.options', []),
                    (array) $this->config->get('database.redis.clusters.'.$connection.'.options', []),
                );
            } else {
                $connectionConfig = (new ConfigurationUrlParser)->parseConfiguration(is_string($connectionConfig)
                    ? $connectionConfig : Narrow::map($connectionConfig ?? [], 'Redis connection configuration'));
                $options = array_replace($connectionConfig, $options, (array) ($connectionConfig['options'] ?? []));
            }

            if ($this->config->get('database.redis.client', 'phpredis') === 'phpredis'
                && (($options['serializer'] ?? 0) != 0 || ($options['compression'] ?? 0) != 0)) {
                yield DoctorFinding::warning('cache.transport', 'Configured Redis client serialization or compression disables shared permission sets; use a dedicated connection with both options disabled.', $scope);
            }

            if (($panel->settings()->cacheTtl() ?? 0) > SignedEnvelope::MAX_TTL) {
                yield DoctorFinding::warning('cache.ttl', 'Shared permission-set TTL is capped at 3600 seconds; set cache.ttl accordingly.', $scope);
            }

            if ($context->isProduction()) {
                yield DoctorFinding::warning('cache.redis', 'Verify Redis TLS, ACLs, application isolation and network controls using the deployment runbook; configuration alone cannot establish them.', $scope);
            }
        }
    }
}
