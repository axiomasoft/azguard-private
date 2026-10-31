<?php

declare(strict_types=1);
use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use Illuminate\Cache\RedisStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;

// This process has Redis access but deliberately receives no signing key.
[$script, $port, $prefix, $target, $bait, $attack] = $argv;

if (! preg_match('/\Aazguard-qualification-[0-9a-f]{24}:\z/', $prefix)
    || ! str_starts_with($target, $prefix.'sets:') || ! str_starts_with($bait, $prefix.'sets:')) {
    throw new RuntimeException('Refusing to access a key outside the disposable namespace.');
}
$redis = new Redis;
$redis->connect('127.0.0.1', (int) $port, 1);
$redis->select(15);

if ($attack === 'produce') {
    require dirname(__DIR__, 4).'/vendor/autoload.php';
    $now = new DateTimeImmutable($argv[6]);
    $config = new Repository(['azguard' => ['cache' => [
        'namespace' => 'redis-qualification', 'signing_key' => stream_get_contents(STDIN), 'key_id' => 'active', 'previous_keys' => [],
    ]]]);
    $signer = SignedEnvelope::fromConfig($config);
    $payload = serialize(['items' => [AuthorizationWorld::grant()], 'validUntil' => $now->modify('+60 seconds')]);
    $envelope = $signer->encode('attacker-bait', $payload, $now, $now->modify('+60 seconds'));
    $manager = new RedisManager(new Container, 'phpredis', [
        'default' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 15],
        'options' => ['prefix' => $prefix],
    ]);
    $store = new RedisStore($manager, 'sets:');
    $store->put('attacker-bait', $envelope, 60);
    exit(0);
}
$envelope = unserialize($redis->get($target), ['allowed_classes' => false]);
$grantEnvelope = unserialize($redis->get($bait), ['allowed_classes' => false]);

if (! is_string($envelope) || ! is_string($grantEnvelope)) {
    throw new RuntimeException('Expected Laravel-serialized string envelopes.');
}
$entry = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
$grant = json_decode($grantEnvelope, true, flags: JSON_THROW_ON_ERROR);
switch ($attack) {
    case 'payload': $entry['payloadBase64'] = $grant['payloadBase64'];

        break;
    case 'key': $entry = $grant;

        break;
    case 'time': $entry['validUntilUTC'] = (new DateTimeImmutable($entry['validUntilUTC']))->modify('-1 second')->format('Y-m-d\TH:i:s.u\Z');

        break;
    case 'namespace': $entry['namespace'] = 'attacker';

        break;
    case 'unsigned':
        $redis->setex($target, 60, serialize(base64_decode($grant['payloadBase64'], true)));
        exit(0);
    default: throw new RuntimeException('Unknown attack.');
}
$redis->setex($target, 60, serialize(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
