<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use AzGuard\Filament\AzGuardPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Throwable;

/**
 * Read-only evaluator answering "may the current panel user reach this custom
 * Page or Widget?" — the enforcement counterpart to {@see ResourceGate}.
 *
 * Filament routes custom Pages via a `static::canAccess()` check (called from
 * `mountCanAuthorizeAccess()`/`hydrateCanAuthorizeAccess()`, i.e. on every
 * mount and every Livewire round-trip — not just navigation) and Widgets via
 * `static::canView()`. Neither goes through the Gate, so {@see ResourceGate}
 * structurally cannot reach them. {@see HasAzGuardPage} and
 * {@see HasAzGuardWidget} call this evaluator from those static hooks to
 * close that gap.
 *
 * Degrades to "allow" (rather than throwing) whenever it cannot resolve a
 * linked AzGuard panel, a user, or a `hasPermission()`-capable user — so a
 * page/widget outside an AzGuard-linked panel, or reached outside an
 * authenticated Filament request (e.g. artisan/tests), behaves exactly as
 * Filament's own un-enforced default. This mirrors the `enforce`/`source`
 * escape hatches {@see AzGuardFilamentServiceProvider} already grants
 * {@see ResourceGate}.
 */
final readonly class PageWidgetAccessEvaluator
{
    public function __construct(
        private PermissionSchema $schema,
    ) {}

    public function authorize(string $subjectClass, string $ability): bool
    {
        $panel = $this->currentPanel();

        if (! $panel instanceof Panel) {
            return true;
        }

        $plugin = $this->azGuardPlugin($panel);

        if (! $plugin instanceof AzGuardPlugin || ! $this->enforced()) {
            return true;
        }

        $user = $this->currentUser($panel);

        if ($user === null || ! method_exists($user, 'hasPermission')) {
            return true;
        }

        $panelId = $plugin->getPanelId();
        $key = $this->schema->key($panelId, class_basename($subjectClass), $ability);

        return (bool) $user->hasPermission($key, $panelId);
    }

    private function enforced(): bool
    {
        if (! config('az-guard-filament.enforce', true)) {
            return false;
        }

        return config('az-guard-filament.source', 'database') !== 'policy';
    }

    private function currentPanel(): ?Panel
    {
        try {
            return Filament::getCurrentPanel();
        } catch (Throwable) {
            return null;
        }
    }

    private function azGuardPlugin(Panel $panel): ?AzGuardPlugin
    {
        try {
            $plugin = $panel->getPlugin('az-guard');

            return $plugin instanceof AzGuardPlugin ? $plugin : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function currentUser(Panel $panel): ?object
    {
        try {
            $user = $panel->auth()->user();

            return is_object($user) ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }
}
