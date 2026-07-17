<?php

declare(strict_types=1);

namespace AzGuard\Filament\Pages;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Guard\AzGuardDiagnostics;
use AzGuard\Support\RequestState;
use BackedEnum;
use Filament\Pages\Page;
use Override;
use Throwable;
use UnitEnum;

/**
 * AzGuard diagnostics page in the Filament UI.
 *
 * Displays the result of AzGuardDiagnostics::diagnose() in three sections:
 *  - Abilities  — registered Gate abilities (table)
 *  - Warnings   — warnings (yellow)
 *  - Errors     — consistency errors (red)
 *
 * Filters by the panelId specified in AzGuardPlugin::forPanel().
 */
final class DoctorPage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-stethoscope';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static ?string $navigationLabel = 'Doctor';

    protected static ?string $title = 'AzGuard Doctor';

    protected string $view = 'az-guard::pages.doctor';

    private const string MEMO_KEY = 'doctor-page.diagnose';

    // ─── Badge: number of errors in navigation ────────────────────────────

    #[Override]
    public static function getNavigationBadge(): ?string
    {
        $result = self::runDiagnose();
        $errorCount = count($result['errors']);

        return $errorCount > 0 ? (string) $errorCount : null;
    }

    #[Override]
    public static function getNavigationBadgeColor(): string
    {
        $result = self::runDiagnose();

        if (count($result['errors']) > 0) {
            return 'danger';
        }

        if (count($result['warnings']) > 0) {
            return 'warning';
        }

        return 'success';
    }

    // ─── View data ─────────────────────────────────────────────────────────────

    public function getDiagnoseResult(): array
    {
        return self::runDiagnose();
    }

    // ─── Internal ───────────────────────────────────────────────────────────

    /**
     * Memoized per request via the scoped RequestState (Octane-safe — a plain
     * static property would bleed a stale result into the next request on a
     * reused worker). Filament calls this up to 3× per render (navigation
     * badge, badge color, view data).
     *
     * @return array{errors: list<string>, warnings: list<string>, abilities: list<array{panel: string, ability: string, handler: string}>}
     */
    private static function runDiagnose(): array
    {
        /** @var array{errors: list<string>, warnings: list<string>, abilities: list<array{panel: string, ability: string, handler: string}>} */
        return app(RequestState::class)->remember(self::MEMO_KEY, static function (): array {
            /** @var AzGuardDiagnostics $doctor */
            $doctor = app(AzGuardDiagnostics::class);

            // Filter by the plugin's panelId if it is registered
            $panelFilter = null;

            try {
                /** @var AzGuardPlugin $plugin */
                $plugin = filament()->getCurrentPanel()?->getPlugin('az-guard');
                $panelFilter = $plugin->getPanelId();
            } catch (Throwable) {
                // Plugin unavailable — diagnose all panels
            }

            return $doctor->diagnose(panelFilter: $panelFilter);
        });
    }
}
