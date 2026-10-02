<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The package configuration fails a load-time check.
 *
 * The code names the failed check after the dot (`invalid_configuration.enum`); a failure without a narrower
 * check keeps the base code.
 */
final class InvalidConfigurationException extends ConfigurationException
{
    private ?string $check = null;

    /**
     * @param  string  $check  the failed check, the part of the code after the dot
     */
    public static function failing(string $check, string $message): self
    {
        $exception = new self($message);
        $exception->check = $check;

        return $exception;
    }

    public function code(): string
    {
        return $this->check === null ? 'invalid_configuration' : 'invalid_configuration.'.$this->check;
    }
}
