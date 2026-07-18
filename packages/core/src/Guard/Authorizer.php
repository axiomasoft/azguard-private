<?php

declare(strict_types=1);

namespace AzGuard\Guard;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Events\AccessDecision;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Support\Config;
use AzGuard\Support\Panel;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Core authorization component.
 *
 * Registered via Gate::before() and:
 * 1) Delegates permission resolution to EffectivePermissionResolver.
 * 2) Returns true for superadmin (wildcard '*').
 * 3) Checks the specific $ability via PermissionSet::grants() (exact + wildcard).
 * 4) Returns null (pass-through) if the user does not implement Authenticatable.
 *
 * Panel resolution order: the current request panel (SetCurrentPanel
 * middleware), else az-guard.default_panel, else the sole registered panel.
 * With no active panel AND more than one registered panel it returns null
 * (deny pass-through) rather than evaluate against an arbitrary panel.
 */
final readonly class Authorizer
{
    public function __construct(
        private EffectivePermissionResolver $resolver,
        private AzGuardManagerInterface $manager,
    ) {}

    public function check(Authorizable $user, string $ability): ?bool
    {
        if (! $user instanceof Authenticatable) {
            return null;
        }

        $panelId = $this->resolvePanelId();

        if ($panelId === null) {
            return null;
        }

        $set = $this->resolver->forUser($user, $panelId);

        if ($set->grants($ability)) {
            return true;
        }

        return null;
    }

    /**
     * Off-hot-path inspection: re-run the decision and describe WHY it landed,
     * returning an {@see AccessDecision}. When `az-guard.audit_log` is enabled
     * the same object is dispatched as an event for auditors/listeners. The hot
     * {@see check()} path is never touched — no event work happens there.
     */
    public function explain(Authenticatable $user, string $ability): AccessDecision
    {
        $identifier = $user->getAuthIdentifier();
        $userId = is_int($identifier) ? $identifier : (string) $identifier;

        $panelId = $this->resolvePanelId();

        if ($panelId === null) {
            return $this->record(new AccessDecision(
                userId: $userId,
                panelId: '',
                ability: $ability,
                allowed: false,
                reasonCode: AccessDecision::NO_ACTIVE_PANEL,
            ));
        }

        $set = $this->resolver->forUser($user, $panelId);

        $decision = match (true) {
            $set->isWildcard() => new AccessDecision(
                userId: $userId, panelId: $panelId, ability: $ability,
                allowed: true, reasonCode: AccessDecision::WILDCARD,
                winningSource: $this->resolveWinningSource($user, $panelId, $ability, AccessDecision::WILDCARD),
            ),
            $set->has($ability) => new AccessDecision(
                userId: $userId, panelId: $panelId, ability: $ability,
                allowed: true, reasonCode: AccessDecision::SOURCE_GRANT,
                winningSource: $this->resolveWinningSource($user, $panelId, $ability, AccessDecision::SOURCE_GRANT),
            ),
            $set->matchesWildcard($ability) => new AccessDecision(
                userId: $userId, panelId: $panelId, ability: $ability,
                allowed: true, reasonCode: AccessDecision::PATTERN_MATCH,
                winningSource: $this->resolveWinningSource($user, $panelId, $ability, AccessDecision::PATTERN_MATCH),
            ),
            default => new AccessDecision(
                userId: $userId, panelId: $panelId, ability: $ability,
                allowed: false, reasonCode: AccessDecision::NO_GRANT,
            ),
        };

        return $this->record($decision);
    }

    /**
     * Off-hot-path: re-queries each GrantSource individually, highest priority
     * first, to attribute which one produced the winning grant (C-15). Mirrors
     * EffectivePermissionResolver::resolve()'s short-circuit order — the first
     * source whose own set already satisfies $reasonCode is the winner.
     */
    private function resolveWinningSource(Authenticatable $user, string $panelId, string $ability, string $reasonCode): ?string
    {
        foreach ($this->resolver->sources() as $source) {
            $sourceSet = $source->permissionsFor($user, $panelId);

            $satisfied = match ($reasonCode) {
                AccessDecision::WILDCARD => $sourceSet->isWildcard(),
                AccessDecision::SOURCE_GRANT => $sourceSet->has($ability),
                AccessDecision::PATTERN_MATCH => $sourceSet->matchesWildcard($ability),
                default => false,
            };

            if ($satisfied) {
                return $source::class;
            }
        }

        return null;
    }

    /**
     * Dispatch the decision only when auditing is opted in (default off), so
     * inspection stays free of side effects and the flag has an honest reader.
     */
    private function record(AccessDecision $decision): AccessDecision
    {
        if (Config::auditLogEnabled()) {
            event($decision);
        }

        return $decision;
    }

    private function resolvePanelId(): ?string
    {
        $current = $this->manager->currentPanel();

        if ($current instanceof Panel) {
            return $current->getId();
        }

        $panels = $this->manager->getPanels();

        // Explicit default wins when it is actually registered.
        $default = Config::defaultPanel();

        if ($default !== null && isset($panels[$default])) {
            return $default;
        }

        // A single registered panel is unambiguous; with several, refuse to
        // guess — returning null lets the Gate deny instead of evaluating the
        // ability against an arbitrary panel.
        return count($panels) === 1 ? array_key_first($panels) : null;
    }
}
