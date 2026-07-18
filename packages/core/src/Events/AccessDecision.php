<?php

declare(strict_types=1);

namespace AzGuard\Events;

/**
 * Emitted by Authorizer::explain() when auditing is enabled (config
 * `az-guard.audit_log`, default off). Carries the full authorization verdict —
 * the moment auditors care about — WITHOUT touching the hot check() path.
 *
 * It doubles as the return value of explain(), so an inspection command can
 * read the same object a listener receives.
 */
final readonly class AccessDecision
{
    /** Actor holds the global wildcard '*' (super-admin). */
    public const string WILDCARD = 'WILDCARD';

    /** An exact grant for the ability was present in the resolved set. */
    public const string SOURCE_GRANT = 'SOURCE_GRANT';

    /** A wildcard pattern grant (e.g. 'app.documents.*') covered the ability. */
    public const string PATTERN_MATCH = 'PATTERN_MATCH';

    /** No grant covered the ability. */
    public const string NO_GRANT = 'NO_GRANT';

    /** No panel could be resolved, so the decision was a pass-through deny. */
    public const string NO_ACTIVE_PANEL = 'NO_ACTIVE_PANEL';

    /**
     * $winningSource is best-effort diagnostics (C-15), not part of the
     * authorization result: it is attributed by re-querying each GrantSource
     * in priority order, so an ability granted exclusively by a context
     * PermissionLayer (no GrantSource produced it) yields null even when
     * $allowed is true, and a higher-priority source whose pattern textually
     * matches the ability may be named although the effective set filtered
     * that pattern out (P1.4 review).
     */
    public function __construct(
        public int|string $userId,
        public string $panelId,
        public string $ability,
        public bool $allowed,
        public string $reasonCode,
        public ?string $winningSource = null,
        public ?string $correlationId = null,
    ) {}
}
