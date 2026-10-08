<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\CheckPermission;
use AzGuard\Tests\Fixtures\Http\EntryPermission;

/** A base controller whose check, an enum of this panel only, covers every action of its children. */
#[CheckPermission(EntryPermission::Enter)]
abstract class CrmController {}
