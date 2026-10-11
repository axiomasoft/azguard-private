<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;
use RuntimeException;

#[Api(since: '0.7')]
final class RecursionDetectedException extends RuntimeException {}
