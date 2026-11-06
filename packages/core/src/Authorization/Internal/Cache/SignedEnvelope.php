<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Cache;

use AzGuard\Configuration\Internal\CacheSigningConfig;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;

/** @internal Authenticates bounded, versioned raw contribution payloads before object construction. */
final readonly class SignedEnvelope
{
    public const int MAX_BYTES = 1048576;

    public const int MAX_TTL = 3600;

    /** @param array<string, array{key: string, until: ?DateTimeImmutable}> $keys */
    private function __construct(private string $namespace, private string $active, private array $keys) {}

    public static function fromConfig(Repository $config): ?self
    {
        $signing = CacheSigningConfig::fromConfig($config);

        return $signing === null ? null : new self($signing['namespace'], $signing['active'], $signing['keys']);
    }

    public function encode(string $key, string $payload, DateTimeImmutable $now, DateTimeImmutable $until): ?string
    {
        if (strlen($payload) > self::MAX_BYTES || $until <= $now || $until > $now->modify('+'.self::MAX_TTL.' seconds')) {
            return null;
        }
        $data = ['v' => 1, 'kid' => $this->active, 'namespace' => $this->namespace, 'keyDigest' => hash('sha256', $key),
            'issuedAtUTC' => self::time($now), 'validUntilUTC' => self::time($until), 'payloadBase64' => base64_encode($payload)];
        $data['macHex'] = $this->mac(['1', $data['kid'], $data['namespace'], $data['keyDigest'], $data['issuedAtUTC'], $data['validUntilUTC'], $payload], $this->keys[$this->active]['key']);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        return is_string($json) && strlen($json) <= self::MAX_BYTES ? $json : null;
    }

    /** @return array{payload: string, validUntil: DateTimeImmutable}|null */
    public function decode(mixed $stored, string $key, DateTimeImmutable $now): ?array
    {
        if (! is_string($stored) || strlen($stored) > self::MAX_BYTES) {
            return null;
        }
        $data = json_decode($stored, true, 4);

        if (! is_array($data) || count($data) !== 8 || ($data['v'] ?? null) !== 1
            || json_encode($data, JSON_UNESCAPED_SLASHES) !== $stored) {
            return null;
        }

        if (! is_string($data['kid'] ?? null) || ! is_string($data['namespace'] ?? null)
            || ! is_string($data['keyDigest'] ?? null) || ! is_string($data['issuedAtUTC'] ?? null)
            || ! is_string($data['validUntilUTC'] ?? null) || ! is_string($data['payloadBase64'] ?? null)
            || ! is_string($data['macHex'] ?? null)) {
            return null;
        }
        $kid = $data['kid'];
        $issued = CacheSigningConfig::parseTime($data['issuedAtUTC']);
        $until = CacheSigningConfig::parseTime($data['validUntilUTC']);

        if (! isset($this->keys[$kid]) || $data['namespace'] !== $this->namespace
            || ! hash_equals(hash('sha256', $key), $data['keyDigest']) || $issued === null || $until === null
            || $issued > $now || $until <= $now || $until <= $issued || $until > $issued->modify('+'.self::MAX_TTL.' seconds')
            || ($this->keys[$kid]['until'] !== null && $now >= $this->keys[$kid]['until'])
            || preg_match('/\A[0-9a-f]{64}\z/', $data['macHex']) !== 1) {
            return null;
        }
        $payload = base64_decode($data['payloadBase64'], true);

        if (! is_string($payload) || strlen($payload) > self::MAX_BYTES || base64_encode($payload) !== $data['payloadBase64']
            || ! hash_equals($this->mac(['1', $kid, $data['namespace'], $data['keyDigest'], $data['issuedAtUTC'], $data['validUntilUTC'], $payload], $this->keys[$kid]['key']), $data['macHex'])) {
            return null;
        }

        return ['payload' => $payload, 'validUntil' => $until];
    }

    /** @param list<string> $parts */
    private function mac(array $parts, string $secret): string
    {
        $framed = '';
        foreach ($parts as $part) {
            $framed .= pack('N', strlen($part)).$part;
        }
        $derived = hash_hkdf('sha256', $secret, 32, 'azguard.permission-sets.v1', $this->namespace);

        return hash_hmac('sha256', $framed, $derived);
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
