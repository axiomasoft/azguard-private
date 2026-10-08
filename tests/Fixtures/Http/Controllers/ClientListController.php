<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\CheckPermission;

/** A check on the class for one action only; the other action is checked by nothing. */
#[CheckPermission('clients.view_any', only: ['index'])]
final class ClientListController
{
    public function index(): string
    {
        return 'list';
    }

    public function export(): string
    {
        return 'export';
    }
}
