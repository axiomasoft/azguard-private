<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use Illuminate\Config\Repository;

function envelopeConfig(array $overrides = []): Repository
{
    return new Repository(['azguard' => ['cache' => array_replace([
        'namespace' => 'semantic-test', 'signing_key' => str_repeat('s', 32), 'key_id' => 'active', 'previous_keys' => [],
    ], $overrides)]]);
}

/** Sign deliberately invalid semantic fields, so rejection cannot be explained by a damaged MAC. */
function resignEnvelope(array $entry): string
{
    $parts = ['1', $entry['kid'], $entry['namespace'], $entry['keyDigest'], $entry['issuedAtUTC'], $entry['validUntilUTC'], base64_decode($entry['payloadBase64'], true)];
    $framed = '';
    foreach ($parts as $part) {
        $framed .= pack('N', strlen($part)).$part;
    }
    $secret = hash_hkdf('sha256', str_repeat('s', 32), 32, 'azguard.permission-sets.v1', $entry['namespace']);
    $entry['macHex'] = hash_hmac('sha256', $framed, $secret);

    return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('rejects authenticated envelopes with invalid semantic fields', function (string $field, mixed $value): void {
    $now = new DateTimeImmutable('2026-10-31T12:00:00Z');
    $signer = SignedEnvelope::fromConfig(envelopeConfig());
    $raw = $signer->encode('key', 'payload', $now, $now->modify('+60 seconds'));
    expect($signer->decode($raw, 'key', $now)['payload'])->toBe('payload');
    $entry = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $entry[$field] = $value;
    expect($signer->decode(resignEnvelope($entry), 'key', $now))->toBeNull();
})->with([
    ['v', 2], ['extra', 'unexpected'], ['namespace', 'foreign'], ['keyDigest', hash('sha256', 'another-key')],
    ['issuedAtUTC', '2026-10-31T12:00:01.000000Z'], ['validUntilUTC', '2026-10-31T12:00:00.000000Z'],
    ['validUntilUTC', '2026-10-31T13:00:01.000000Z'],
]);

it('rejects expired signed data and accepts a valid replay until its exact boundary', function (): void {
    $now = new DateTimeImmutable('2026-10-31T12:00:00.123456Z');
    $signer = SignedEnvelope::fromConfig(envelopeConfig());
    $raw = $signer->encode('key', 'payload', $now, $now->modify('+1 second'));
    expect($signer->decode($raw, 'key', $now->modify('+999999 microseconds'))['payload'])->toBe('payload')
        ->and($signer->decode($raw, 'key', $now->modify('+1 second')))->toBeNull();
    $expired = $signer->encode('key', 'payload', $now->modify('-2 seconds'), $now->modify('-1 second'));
    expect($expired)->toBeString()->and($signer->decode($expired, 'key', $now))->toBeNull();
});

it('enforces the byte limit on a syntactically valid authenticated JSON envelope', function (): void {
    $now = new DateTimeImmutable('2026-10-31T12:00:00Z');
    $signer = SignedEnvelope::fromConfig(envelopeConfig());
    $raw = $signer->encode('key', 'payload', $now, $now->modify('+60 seconds'));
    $entry = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $entry['payloadBase64'] = base64_encode(str_repeat('x', SignedEnvelope::MAX_BYTES));
    $oversized = resignEnvelope($entry);
    expect(strlen($oversized))->toBeGreaterThan(SignedEnvelope::MAX_BYTES)
        ->and(json_decode($oversized, true, flags: JSON_THROW_ON_ERROR))->toBeArray()
        ->and($signer->decode($oversized, 'key', $now))->toBeNull();
});

it('requires an explicit valid verification deadline for previous keys', function (array $previous): void {
    expect(SignedEnvelope::fromConfig(envelopeConfig(['previous_keys' => ['old' => $previous]])))->toBeNull();
})->with([
    [['key' => str_repeat('o', 32)]],
    [['key' => str_repeat('o', 32), 'valid_until' => 'invalid']],
    [['key' => str_repeat('o', 31), 'valid_until' => '2026-10-31T12:00:00.000000Z']],
]);
