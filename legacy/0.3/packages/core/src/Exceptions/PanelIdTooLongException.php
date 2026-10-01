<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when a panel ID exceeds the 128-character storage limit (D14).
 * Enforced regardless of `strict_panels`.
 */
final class PanelIdTooLongException extends AzGuardException
{
    public const int MAX_LENGTH = 128;

    public function __construct(public readonly string $panelId)
    {
        $length = mb_strlen($panelId, 'UTF-8');

        parent::__construct(
            "AzGuard panel id is {$length} characters; the maximum is ".self::MAX_LENGTH.'.',
        );
    }
}
