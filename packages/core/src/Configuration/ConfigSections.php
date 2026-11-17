<?php

declare(strict_types=1);

namespace AzGuard\Configuration;

use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;

/**
 * The root sections of `config/azguard.php` that an owner other than core validates; delegates section
 * registration to {@see ConfigSectionRegistration} when bound to an application container.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigSections
{
    /** @var array<string, Closure(array<string, mixed>): void> */
    private array $owners = [];

    private bool $frozen = false;

    public function __construct(private readonly ?Application $app = null) {}

    /**
     * Called from a module provider's register(); the owner validates azguard.<root>.
     *
     * @param  Closure(array<string, mixed>): void  $validate  throws `InvalidConfigurationException` for an invalid section
     *
     * @throws InvalidConfigurationException after the configuration was read, for a malformed root or a root that
     *                                       already has an owner
     */
    public function own(string $root, Closure $validate): void
    {
        if ($this->frozen) {
            throw new InvalidConfigurationException('The section azguard.'.$root.' is registered after the configuration was read: register it in the register() method of a service provider.');
        }

        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $root) !== 1) {
            throw new InvalidConfigurationException('A configuration section is a lowercase root key, got "'.$root.'".');
        }

        if (isset($this->owners[$root])) {
            throw new InvalidConfigurationException('The section azguard.'.$root.' already has an owner.');
        }

        if ($this->app !== null) {
            try {
                ConfigSectionRegistration::own($this->app, $root, $validate);
            } catch (InvalidConfiguration $error) {
                throw new InvalidConfigurationException($error->getMessage(), previous: $error);
            }
        }

        $this->owners[$root] = $validate;
    }

    /**
     * @return array<string, Closure(array<string, mixed>): void>
     */
    #[Internal('Inspects registered module section owners without sealing registration.')]
    public function peekOwned(): array
    {
        return $this->owners;
    }

    /**
     * @return array<string, Closure(array<string, mixed>): void>
     */
    #[Internal('The registered sections with their validators; reading them closes the registration.')]
    public function owned(): array
    {
        $this->frozen = true;

        return $this->owners;
    }
}
