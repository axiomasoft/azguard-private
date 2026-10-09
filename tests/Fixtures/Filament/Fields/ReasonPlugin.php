<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Fields;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;

/** An AzGuard plugin that adds the reason of a grant to both kinds of grants of a panel. */
final class ReasonPlugin extends BasePlugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'fixtures/reason';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        foreach ([FieldTarget::RoleGrant, FieldTarget::PermissionGrant] as $target) {
            $panel->fields($target, [Field::string('reason')->label('Reason')->rules(['max:20'])->inMeta()]);
        }
    }
}
