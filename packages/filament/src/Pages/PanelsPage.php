<?php

declare(strict_types=1);

namespace AzGuard\Filament\Pages;

use AzGuard\Diagnostics\PanelOverview;
use AzGuard\Filament\Concerns\AuthorizesPage;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Every AzGuard panel with its effective settings and where each value came from, its sources and plugins, and the
 * schema of its permissions. It shows what `azguard:panels:list --settings --sources --schema` prints and changes
 * nothing; a panel without a database source is listed here though no editor offers it.
 *
 * Its permission is `pages.azguard-panels` in the guard panel.
 *
 * @api
 */
final class PanelsPage extends Page
{
    use AuthorizesPage;

    protected static ?string $slug = 'azguard-panels';

    protected static ?string $title = 'Panels';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected string $view = 'azguard-filament::pages.panels';

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $panels = [];

    public function mount(): void
    {
        $this->panels = app(PanelOverview::class)->all(settings: true, sources: true, schema: true);
    }
}
