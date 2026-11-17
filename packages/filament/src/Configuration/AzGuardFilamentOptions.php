<?php

declare(strict_types=1);

namespace AzGuard\Filament\Configuration;

use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Kernel\Decision\PermissionAuthority;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Validated configuration options for `azguard-filament`.
 */
#[Api(since: '1.0')]
final readonly class AzGuardFilamentOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array ROOT_KEYS = [
        'guard_panel',
        'manages',
        'enforce',
        'definitions',
        'authority',
        'abilities',
        'exclude',
    ];

    /** @var list<string> */
    private const array EXCLUDE_KEYS = ['resources', 'pages', 'widgets'];

    /**
     * @param  list<string>|null  $manages
     * @param  list<string>  $abilities
     * @param  array{resources: list<string>, pages: list<string>, widgets: list<string>}  $exclude
     */
    public function __construct(
        public ?string $guardPanel,
        public ?array $manages,
        public bool $enforce,
        public FilamentDefinitions $definitions,
        public PermissionAuthority $authority,
        public array $abilities,
        public array $exclude,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) $config->get('azguard-filament', []);

        return self::fromArray($raw, 'azguard-filament');
    }

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $config, string $path = 'azguard-filament'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::ROOT_KEYS, $path);

        $rawEnforce = array_key_exists('enforce', $config) ? $config['enforce'] : true;

        if (! is_bool($rawEnforce)) {
            $validator->add("{$path}.enforce", 'must be a boolean, got '.get_debug_type($rawEnforce));
        }

        $rawExclude = $config['exclude'] ?? [];

        if (! is_array($rawExclude)) {
            $validator->add("{$path}.exclude", 'must be an array, got '.get_debug_type($rawExclude));
            $validator->failIfAny();

            throw self::invalid('exclude must be an array.');
        }

        $validator->rejectUnknownKeys($rawExclude, self::EXCLUDE_KEYS, "{$path}.exclude");
        $validator->failIfAny();

        /** @var bool $enforce */
        $enforce = $rawEnforce;
        $guardPanel = self::optionalString($config['guard_panel'] ?? null, 'guard_panel');
        $manages = isset($config['manages']) ? self::strings($config['manages'], 'manages') : null;

        $rawDefinitions = self::optionalString($config['definitions'] ?? null, 'definitions') ?? 'enums';
        $definitions = FilamentDefinitions::tryFrom($rawDefinitions)
            ?? throw self::invalid('definitions must be "enums" or "resources".');

        $rawAuthority = self::optionalString($config['authority'] ?? null, 'authority') ?? 'grants';
        $authority = PermissionAuthority::tryFrom($rawAuthority)
            ?? throw self::invalid('authority must be "grants" or "policy".');

        $abilities = self::abilityNames($config['abilities'] ?? []);

        $exclude = [
            'resources' => self::strings($rawExclude['resources'] ?? [], 'exclude.resources'),
            'pages' => self::strings($rawExclude['pages'] ?? [], 'exclude.pages'),
            'widgets' => self::strings($rawExclude['widgets'] ?? [], 'exclude.widgets'),
        ];

        return new self(
            guardPanel: $guardPanel,
            manages: $manages,
            enforce: $enforce,
            definitions: $definitions,
            authority: $authority,
            abilities: $abilities,
            exclude: $exclude,
        );
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     */
    public static function abilityNames(mixed $abilities): array
    {
        $names = self::strings($abilities, 'abilities');

        foreach ($names as $name) {
            if (preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $name) !== 1) {
                throw self::invalid('The ability "'.$name.'" is not snake_case.');
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     */
    public static function strings(mixed $values, string $name): array
    {
        if (! is_array($values)) {
            throw self::invalid($name.' must be a list of strings.');
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw self::invalid($name.' must be a list of non-empty strings.');
            }
            $strings[] = $value;
        }

        return array_values(array_unique($strings));
    }

    /** @throws InvalidConfigurationException */
    private static function optionalString(mixed $value, string $name): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) && $value !== '' => $value,
            default => throw self::invalid($name.' must be a non-empty string or null.'),
        };
    }

    private static function invalid(string $message): InvalidConfigurationException
    {
        return InvalidConfigurationException::failing('filament', $message);
    }
}
