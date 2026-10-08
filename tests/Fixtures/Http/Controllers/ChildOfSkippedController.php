<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

/** A child does not inherit the opt-out of a parent controller. */
final class ChildOfSkippedController extends SkippableBaseController
{
    public function open(): string
    {
        return 'open';
    }
}
