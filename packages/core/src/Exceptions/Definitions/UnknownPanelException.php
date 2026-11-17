<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Definitions;

use Packman\Attributes\Api;

/**
 * No panel is registered under the id.
 */
#[Api(since: '1.0')]
final class UnknownPanelException extends DefinitionException
{
    public function code(): string
    {
        return 'unknown_panel';
    }
}
