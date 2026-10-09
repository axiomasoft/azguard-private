<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use DateTimeImmutable;

/** An immutable, scalar diagnostic snapshot of one evaluation.
 * @api
 */
final readonly class Explanation
{
    /** @var list<array<string, mixed>> */
    private array $trace;

    /** @var array<string, mixed> */
    private array $snapshot;

    /** @param list<array<string, mixed>> $steps
     * @param  array{class: string, id: int|string|null}|null  $resource
     */
    public function __construct(private Decision $result, array $steps, private DateTimeImmutable $evaluatedAt, SubjectRef $subject, ?array $resource = null)
    {
        $data = [
            'decision' => ['effect' => $result->effect->value, 'reason' => $result->reason->value, 'component' => $result->component,
                'message' => $result->message, 'status' => $result->status, 'code' => $result->code],
            'panel' => $result->state->panel,
            'subject' => ['type' => $subject->type(), 'id' => $subject->id()],
            'scope' => ['tenant' => $result->scope->tenant->key(), 'context' => $result->scope->context->key()],
            'resource' => $resource, 'now' => $evaluatedAt->format('Y-m-d\TH:i:s.uP'),
            'state' => get_object_vars($result->state), 'steps' => $steps,
        ];
        $secrets = [];
        self::secrets($data, $secrets);
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->snapshot = self::redact($data, array_values(array_unique($secrets)));
        $this->trace = array_map(static fn (array $step): array => self::redact($step, array_values(array_unique($secrets))), $steps);
    }

    public function decision(): Decision
    {
        return $this->result;
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->trace;
    }

    public function scope(): AccessScope
    {
        return $this->result->scope;
    }

    public function now(): DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    public function state(): CodeStateToken|StateToken
    {
        return $this->result->state;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->snapshot;
    }

    private static function sensitive(string|int $name): bool
    {
        return is_string($name) && preg_match('/password|secret|token|credential|key/i', $name) === 1;
    }

    /** @param array<mixed> $data
     * @param  list<string>  $secrets
     */
    private static function secrets(array $data, array &$secrets, bool $sensitive = false): void
    {
        foreach ($data as $name => $value) {
            $hidden = $sensitive || self::sensitive($name);

            if (is_array($value)) {
                self::secrets($value, $secrets, $hidden);
            } elseif ($hidden && is_scalar($value) && (string) $value !== '') {
                $secrets[] = (string) $value;
            }
        }
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @param  list<string>  $secrets
     * @return array<TKey, mixed> the same keys
     */
    private static function redact(array $data, array $secrets): array
    {
        $plain = [];
        foreach ($data as $name => $value) {
            $plain[$name] = match (true) {
                self::sensitive($name) => '[redacted]',
                is_array($value) => self::redact($value, $secrets),
                is_string($value) => preg_replace('/\b((?:password|secret|token|credential|api_key)\s*[:=]\s*)[^\s,;]+/i', '$1[redacted]', $secrets === [] ? $value : str_replace($secrets, '[redacted]', $value)),
                $value === null || is_scalar($value) => $value,
                default => '[redacted]',
            };
        }

        return $plain;
    }
}
