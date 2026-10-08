<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use InvalidArgumentException;

/**
 * One problem `azguard:doctor` found: a stable key, its severity, what it belongs to and a message for a person.
 *
 * The scope is `core`, `panel:<id>`, `storage:<id>` or `plugin:<id>`. Details are scalars or lists of scalars; a
 * detail whose name looks like a secret (a password, a token, a DSN or a URL) never keeps its value.
 *
 * @api
 */
final readonly class DoctorFinding
{
    public const string REDACTED = '[redacted]';

    /** The grammar of a key: lowercase letters, digits, `.`, `_`, `-` and `/`. */
    public const string KEY = '/\A[a-z0-9][a-z0-9_.\/-]{0,127}\z/';

    private const string SECRET_NAME = '/pass(word)?|secret|token|dsn|url|credential|api[_-]?key|private/i';

    /** @var array<string, scalar|list<scalar>> */
    public array $details;

    /**
     * @param  array<mixed>  $details  names and values, checked as they arrive
     *
     * @throws InvalidArgumentException when the key, the scope, the message or a detail is malformed
     */
    public function __construct(
        public string $key,
        public Severity $severity,
        public string $message,
        public string $scope = 'core',
        array $details = [],
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('A doctor finding key is lowercase letters, digits, ".", "_", "-" and "/", got '.json_encode($key).'.');
        }

        if ($scope !== 'core' && preg_match('/\A(panel|storage|plugin):\S+\z/', $scope) !== 1) {
            throw new InvalidArgumentException('A doctor finding scope is core, panel:<id>, storage:<id> or plugin:<id>, got '.json_encode($scope).'.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('A doctor finding needs a message.');
        }
        $this->details = self::details($details);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $key, string $message, string $scope = 'core', array $details = []): self
    {
        return new self($key, Severity::Error, $message, $scope, $details);
    }

    /** @param array<string, mixed> $details */
    public static function warning(string $key, string $message, string $scope = 'core', array $details = []): self
    {
        return new self($key, Severity::Warning, $message, $scope, $details);
    }

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }

    /**
     * The same finding attributed to another scope, with extra details.
     *
     * @param  array<string, mixed>  $details
     */
    public function in(string $scope, array $details = []): self
    {
        return new self($this->key, $this->severity, $this->message, $scope, [...$this->details, ...$details]);
    }

    /**
     * The finding with the given secret values replaced in its message and details. A secret of four characters or
     * more is replaced wherever it occurs; a shorter one only where it stands alone between non-alphanumeric
     * characters, so it is removed from `user:pw@host` but not from every word that contains it.
     *
     * @internal
     *
     * @param  list<string>  $secrets
     */
    public function redacted(array $secrets): self
    {
        if ($secrets === []) {
            return $this;
        }
        $patterns = array_map(static fn (string $secret): string => strlen($secret) >= 4
            ? '/'.preg_quote($secret, '/').'/'
            : '/(?<![[:alnum:]])'.preg_quote($secret, '/').'(?![[:alnum:]])/', $secrets);
        $redact = static fn (string $text): string => (string) preg_replace($patterns, self::REDACTED, $text);
        $details = [];

        foreach ($this->details as $name => $value) {
            $details[$name] = is_array($value)
                ? array_map(static fn (bool|float|int|string $item): bool|float|int|string => is_string($item) ? $redact($item) : $item, $value)
                : (is_string($value) ? $redact($value) : $value);
        }

        return new self($this->key, $this->severity, $redact($this->message), $this->scope, $details);
    }

    /**
     * @return array{key: string, severity: string, scope: string, message: string, details: array<string, scalar|list<scalar>>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'severity' => $this->severity->value, 'scope' => $this->scope,
            'message' => $this->message, 'details' => $this->details];
    }

    /**
     * @param  array<mixed>  $details
     * @return array<string, scalar|list<scalar>>
     */
    private static function details(array $details): array
    {
        $checked = [];

        foreach ($details as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Doctor finding details are named.');
            }

            if (is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => ! is_scalar($item)) === []) {
                /** @var list<scalar> $value */
                $checked[$name] = preg_match(self::SECRET_NAME, $name) === 1 ? [self::REDACTED] : $value;

                continue;
            }

            if (! is_scalar($value)) {
                throw new InvalidArgumentException('Doctor finding detail '.$name.' must be a scalar or a list of scalars, got '.get_debug_type($value).'.');
            }
            $checked[$name] = preg_match(self::SECRET_NAME, $name) === 1 ? self::REDACTED : $value;
        }
        ksort($checked, SORT_STRING);

        return $checked;
    }
}
