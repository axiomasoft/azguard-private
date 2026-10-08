<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\SkipPermissionCheck;

#[SkipPermissionCheck]
abstract class SkippableBaseController {}
