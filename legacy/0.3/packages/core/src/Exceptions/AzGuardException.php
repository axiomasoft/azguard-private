<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use RuntimeException;

/**
 * Base exception for all AzGuard domain errors.
 *
 * Catch this class to handle any AzGuard-specific exception
 * without catching generic PHP errors (TypeError, Error, etc.).
 */
class AzGuardException extends RuntimeException {}
