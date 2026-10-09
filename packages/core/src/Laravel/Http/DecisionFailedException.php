<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Http;

use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\FailureKind;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A decision that could not be computed, as an HTTP error of the reference adapter: 503 for a transient failure
 * (with `Retry-After`), 500 for a broken host contract. The message is generic and the response carries no reason,
 * component or exception; the reason stays on the exception for logs.
 *
 * @api
 */
final class DecisionFailedException extends HttpException
{
    public function __construct(public readonly FailureKind $failure, public readonly DecisionReason $reason, int $retryAfter = 5)
    {
        $transient = $failure === FailureKind::Transient;
        parent::__construct($transient ? 503 : 500, $transient ? 'Authorization is temporarily unavailable.' : 'Authorization could not be decided.',
            headers: $transient ? ['Retry-After' => (string) $retryAfter] : []);
    }

    public static function of(Decision $decision): ?self
    {
        $failure = $decision->failure();

        return $failure === null ? null : new self($failure, $decision->reason);
    }
}
