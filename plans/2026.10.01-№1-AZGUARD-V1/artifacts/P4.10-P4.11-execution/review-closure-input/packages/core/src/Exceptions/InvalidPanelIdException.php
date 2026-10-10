<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class InvalidPanelIdException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_panel_id';
    }
}
