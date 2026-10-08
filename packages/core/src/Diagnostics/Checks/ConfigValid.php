<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Panels\GateMode;
use AzGuard\Panels\PanelSettings;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use BackedEnum;
use Illuminate\Contracts\Config\Repository;

/**
 * `config.valid`: the configuration as it is now loads, its enumerated defaults are in their lists and every storage
 * names a configured database connection.
 *
 * @internal
 */
final readonly class ConfigValid implements DoctorCheck
{
    /** @var array<string, class-string<BackedEnum>> */
    private const array ENUMS = [
        PanelSettings::GATE_MODE => GateMode::class,
        PanelSettings::READS => Reads::class,
        PanelSettings::STATE_REFRESH => StateRefresh::class,
    ];

    public function __construct(private Repository $repository) {}

    /**
     * @param  array<string, array{connection: ?string, table_prefix: string, host_keys: string}>  $storages
     * @param  array<mixed>  $connections  `database.connections`
     * @return list<DoctorFinding>
     */
    public static function connections(array $storages, ?string $default, array $connections): array
    {
        $findings = [];

        foreach ($storages as $id => $storage) {
            $connection = $storage['connection'] ?? $default;

            if ($connection === null || ! is_array($connections[$connection] ?? null)) {
                $findings[] = DoctorFinding::error('config.valid', 'azguard.storages.'.$id.' uses the database connection "'.($connection ?? '')
                    .'", which database.connections does not configure.', details: ['code' => 'invalid_configuration.storage', 'storage' => $id]);
            }
        }

        return $findings;
    }

    public function key(): string
    {
        return 'config.valid';
    }

    public function run(DoctorContext $context): iterable
    {
        try {
            $config = AzGuardConfig::fromRepository($this->repository);
        } catch (ConfigurationException $error) {
            yield DoctorFinding::error($this->key(), 'The AzGuard configuration does not load: '.$error->getMessage(), details: ['code' => $error->code()]);

            return;
        }

        yield from self::defaults($config->defaults());
        $default = $this->repository->get('database.default');
        $connections = $this->repository->get('database.connections');

        yield from self::connections($config->storages(), is_string($default) ? $default : null, is_array($connections) ? $connections : []);
    }

    /**
     * @param  array<string, bool|int|string|null>  $defaults
     * @return list<DoctorFinding>
     */
    public static function defaults(array $defaults): array
    {
        $findings = [];

        foreach (self::ENUMS as $setting => $enum) {
            $value = $defaults[$setting] ?? null;

            if (is_string($value) && $enum::tryFrom($value) === null) {
                $findings[] = DoctorFinding::error('config.valid', 'azguard.defaults.'.$setting.' is "'.$value.'", which is not one of: '
                    .implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases())).'.',
                    details: ['code' => 'invalid_configuration.enum', 'setting' => $setting]);
            }
        }

        return $findings;
    }
}
