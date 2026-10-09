<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Http;

use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\FailureKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;

/**
 * The reference HTTP mapping of a decision (audits/2026-10-09-consistency-design.md, step 6): 403 for a policy denial,
 * 503 when authority could not be read now, 500 when host code broke its contract. It is opt-in: `authorize()`,
 * `azguard.can` and the Gate keep answering 403 for every denial, and the final HTTP policy belongs to the host.
 *
 * @api
 */
final class DecisionResponder
{
    /** The status a response should have for the decision; null when it allows. */
    public static function status(Decision $decision): ?int
    {
        if ($decision->allowed()) {
            return null;
        }

        return match ($decision->failure()) {
            FailureKind::Transient => 503,
            FailureKind::Contract => 500,
            null => 403,
        };
    }

    /**
     * Throws for a decision that does not allow: an `AuthorizationException` (403, the reason code only) for a
     * denial, a `DecisionFailedException` (503 or 500, no internals) for a failure.
     *
     * @throws AuthorizationException
     * @throws DecisionFailedException
     */
    public static function authorize(Decision $decision): void
    {
        if ($decision->allowed()) {
            return;
        }

        $failed = DecisionFailedException::of($decision);

        if ($failed !== null) {
            throw $failed;
        }
        Response::deny(code: $decision->reason->value)->authorize();
    }
}
