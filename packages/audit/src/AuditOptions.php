<?php

declare(strict_types=1);

namespace AzGuard\Audit;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Typed options the audit plugin contributes to a panel: read them with `Panel::options(AuditOptions::class)`.
 */
#[Api(since: '1.0')]
final readonly class AuditOptions implements OptionsFactory
{
    public const int DEFAULT_RETENTION_DAYS = 90;

    /** @var list<string> */
    private const array ROOT_KEYS = ['retention_days'];

    /** @throws InvalidArgumentException when the retention is shorter than one day */
    public function __construct(public int $retentionDays = self::DEFAULT_RETENTION_DAYS)
    {
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('retentionDays must be at least 1.');
        }
    }

    /** @param array<mixed> $config */
    public static function fromArray(array $config, string $path = 'azguard-audit'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::ROOT_KEYS, $path);

        $retention = array_key_exists('retention_days', $config)
            ? $config['retention_days']
            : self::DEFAULT_RETENTION_DAYS;

        if (! is_int($retention) || $retention < 1) {
            $validator->add("{$path}.retention_days", 'must be an integer greater than or equal to 1');
            $validator->failIfAny();

            throw new InvalidArgumentException('retentionDays must be at least 1.');
        }

        $validator->failIfAny();

        return new self($retention);
    }
}
