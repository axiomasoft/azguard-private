<?php

declare(strict_types=1);

// Audit evidence only. These probes instantiate value/registry objects and never access a DB.
require dirname(__DIR__, 3).'/vendor/autoload.php';

$acceptedIds = [];
foreach (['', 'a.b', '*', "a\nb"] as $id) {
    $acceptedIds[] = AzGuard\Panels\Panel::make()->id($id)->getId();
}

$manager = new AzGuard\AzGuardManager;
$first = AzGuard\Panels\Panel::make()->id('app')->label('first');
$second = AzGuard\Panels\Panel::make()->id('app')->label('second');
$manager->registerPanel($first);
$manager->registerPanel($second);
$duplicateReplaced = $manager->panel('app') === $second;
$second->id('changed');

$contexts = new AzGuard\Context\AuthorizationContextManager;
$layer = new AzGuard\Context\ContextPermissionLayer(
    $contexts,
    new AzGuard\Context\Strategies\GlobalPlusContextStrategy,
);
$left = new AzGuard\Context\AuthorizationContext('app', 'workspace:a', 7);
$right = new AzGuard\Context\AuthorizationContext('app', 'workspace', 'a:7');
$contexts->set($left);
$leftKey = $layer->cacheDiscriminator('app');
$contexts->set($right);
$rightKey = $layer->cacheDiscriminator('app');

$previous = new AzGuard\Context\AuthorizationContext('app', 'workspace', 'original');
$contexts->set($previous);
$resolver = new class implements AzGuard\Contracts\PermissionResolverInterface
{
    public function forUser(Illuminate\Contracts\Auth\Authenticatable $user, string $panelId): AzGuard\Registry\Values\PermissionSet
    {
        return AzGuard\Registry\Values\PermissionSet::empty();
    }

    public function forgetForUser(Illuminate\Contracts\Auth\Authenticatable $user, string $panelId): void {}

    public function forgetRequestCache(Illuminate\Contracts\Auth\Authenticatable $user, string $panelId): void
    {
        throw new RuntimeException('Controlled extension invalidation failure');
    }
};
$guard = new AzGuard\Context\ContextGuard($contexts, $resolver);
$cleanupError = null;
try {
    $guard->checkInContext(new Illuminate\Foundation\Auth\User, 'workspace', 'temporary', 'app.documents.view', 'app');
} catch (RuntimeException $error) {
    $cleanupError = $error->getMessage();
}

$result = [
    'php_version' => PHP_VERSION,
    'scope' => 'No DB/cache backend access; demonstrates registry/value/discriminator behavior only, not an end-to-end privilege escalation.',
    'accepted_panel_ids' => $acceptedIds,
    'duplicate_panel_replaced' => $duplicateReplaced,
    'registry_keys_after_mutation' => array_keys($manager->getPanels()),
    'registered_object_id_after_mutation' => $manager->panel('app')?->getId(),
    'distinct_contexts' => ! $left->equals($right),
    'left_context' => ['type' => $left->contextType, 'id' => $left->contextId],
    'right_context' => ['type' => $right->contextType, 'id' => $right->contextId],
    'left_discriminator' => $leftKey,
    'right_discriminator' => $rightKey,
    'discriminator_collision' => $leftKey === $rightKey,
    'context_guard_injected_error' => $cleanupError,
    'context_guard_previous_restored' => $contexts->current('app')?->equals($previous),
    'context_guard_after_error_id' => $contexts->current('app')?->contextId,
];
$output = __DIR__.'/behavior-probes.json';
if (in_array('--check', $argv, true)) {
    $recorded = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
    $result['php_version'] = $recorded['php_version'];
    if ($result !== $recorded) {
        throw new RuntimeException('Probe evidence differs; review the new baseline.');
    }
} else {
    file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
