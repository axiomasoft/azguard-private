<?php

declare(strict_types=1);

namespace AzGuard\Configuration;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Exceptions\InvalidConfigurationException;
use Closure;

/**
 * The root sections of `config/azguard.php` that an owner other than core validates. A module registers its section
 * from its provider's `register()`; the configuration then accepts `azguard.<root>` and hands its value to the
 * owner's closure, and still rejects every root key nobody owns.
 *
 * Registration closes when the configuration is read for the first time: a section registered later would never be
 * validated.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigSections
{
    /** @var array<string, Closure(array<string, mixed>): void> */
    private array $owners = [];

    private bool $frozen = false;

    /**
     * Called from a module provider's register(); the owner validates azguard.<root>.
     *
     * @param  Closure(array<string, mixed>): void  $validate  throws `InvalidConfigurationException` for an invalid section
     *
     * @throws InvalidConfigurationException after the configuration was read, for a malformed root or a root that
     *                                       already has an owner (a root of core is refused when the configuration is read)
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
        $this->owners[$root] = $validate;
    }

    /**
     * @internal The registered sections with their validators; reading them closes the registration.
     *
     * @return array<string, Closure(array<string, mixed>): void>
     */
    public function owned(): array
    {
        $this->frozen = true;

        return $this->owners;
    }
}
