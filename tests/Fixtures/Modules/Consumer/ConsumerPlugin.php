<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * The plugin of the consumer package: contributes a before hook, a restriction, a change pipe, a transactional
 * participant and a scope evaluator through the same slots the built-in modules use.
 */
final class ConsumerPlugin implements Plugin
{
    public function id(): string
    {
        return 'acme/consumer';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->permissions([DatabaseSource::make()])
            ->before([ConsumerBeforeHook::class])
            ->restrictions([new ConsumerRestriction])
            ->changing([ConsumerPipe::class])
            ->participants([new ConsumerParticipant])
            ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new ConsumerScope], evaluator: ConsumerEvaluator::class));
    }
}
