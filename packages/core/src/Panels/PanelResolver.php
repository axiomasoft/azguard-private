<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Configuration\Config;
use AzGuard\Exceptions\PanelIdTooLongException;
use AzGuard\Exceptions\PanelNotFoundException;
use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\Facades\AzGuard;
use AzGuard\Permissions\PermissionKey;
use BackedEnum;
use Illuminate\Support\Facades\Log;

/**
 * Centralises the recurring pattern:
 *   $panelId ?? AzGuard::currentPanel()?->getId()
 *
 * Usage:
 *   PanelResolver::resolve($panelId)          // returns string|null
 *   PanelResolver::resolveOrFail($panelId)    // throws PanelNotSetException
 *   PanelResolver::resolveDefault($panelId)   // explicit ?? config default ?? 'app'
 */
final class PanelResolver
{
    /**
     * Default panel for the model permission APIs ($user->hasPermission(), …).
     *
     * The explicit panel wins; otherwise az-guard.default_panel, otherwise the
     * built-in 'app' fallback. The single place the 'app' literal lives — set
     * az-guard.default_panel to change it project-wide. Does not consult the
     * current request panel (that is the Authorizer's job).
     *
     * The final id is always width-checked (128). With `strict_panels`, it must
     * also be registered — an empty registry is not a bypass.
     */
    public static function resolveDefault(?string $panelId): string
    {
        $resolved = $panelId ?? Config::defaultPanel() ?? 'app';

        self::assertFinal($resolved);

        return $resolved;
    }

    /**
     * Handle a final resolved panel id. Width is always enforced (D14).
     * Default (lenient): debug-only, fail-soft log — resolution is best-effort
     * by design. Opt-in strict mode (config `az-guard.strict_panels`): throw
     * PanelNotFoundException even when no panels are registered yet.
     *
     * @throws PanelIdTooLongException
     * @throws PanelNotFoundException
     */
    private static function assertFinal(string $panelId): void
    {
        if (mb_strlen($panelId, 'UTF-8') > PanelIdTooLongException::MAX_LENGTH) {
            throw new PanelIdTooLongException($panelId);
        }

        $strict = Config::strictPanelsEnabled();

        // Fast path: nothing to inspect when lenient and not debugging.
        if (! $strict && ! config('app.debug')) {
            return;
        }

        $panels = AzGuard::getPanels();

        if (isset($panels[$panelId])) {
            return;
        }

        if ($strict) {
            throw new PanelNotFoundException($panelId);
        }

        if ($panels === []) {
            return;
        }

        Log::debug("AzGuard: permission check against unregistered panel [{$panelId}].", [
            'registered' => array_keys($panels),
        ]);
    }

    /**
     * Normalise a panel identifier to its string id. Accepts a backed enum
     * (e.g. PanelId::Admin) or a plain string, so the whole panel API can be
     * called type-safely with enums.
     */
    public static function normalizeId(string|BackedEnum $panelId): string
    {
        return PermissionKey::normalize($panelId);
    }

    /**
     * Return the explicit panel ID, or fall back to the current AzGuard panel.
     * Returns null when neither is available.
     */
    public static function resolve(?string $panelId): ?string
    {
        $resolved = $panelId ?? AzGuard::currentPanel()?->getId();

        if ($resolved === null) {
            return null;
        }

        self::assertFinal($resolved);

        return $resolved;
    }

    /**
     * Same as resolve(), but throws when no panel can be determined.
     *
     * @throws PanelNotSetException
     */
    public static function resolveOrFail(?string $panelId): string
    {
        $resolved = $panelId
            ?? AzGuard::currentPanel()?->getId()
            ?? throw new PanelNotSetException;

        self::assertFinal($resolved);

        return $resolved;
    }
}
