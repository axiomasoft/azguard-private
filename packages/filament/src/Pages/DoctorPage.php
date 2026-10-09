<?php

declare(strict_types=1);

namespace AzGuard\Filament\Pages;

use AzGuard\Diagnostics\Doctor;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\Severity;
use AzGuard\Filament\Concerns\AuthorizesPage;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Foundation\Application;
use Livewire\Attributes\Locked;
use Throwable;
use UnitEnum;

/**
 * The findings of `azguard:doctor` for every panel, grouped by severity. The doctor runs when the page opens and when
 * "Check again" is called, never on a render; the findings are the ones the doctor has already cleared of secrets.
 *
 * Its permission is `pages.azguard-doctor` in the guard panel.
 *
 * @api
 */
final class DoctorPage extends Page
{
    use AuthorizesPage;

    protected static ?string $slug = 'azguard-doctor';

    protected static ?string $title = 'Doctor';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected string $view = 'azguard-filament::pages.doctor';

    /** @var list<array{key: string, severity: string, scope: string, message: string, details: array<string, mixed>}> */
    #[Locked]
    public array $findings = [];

    public function mount(): void
    {
        $this->check();
    }

    public function check(): void
    {
        $doctor = app(Doctor::class);

        try {
            $findings = $doctor->run($doctor->context(production: app(Application::class)->environment('production')));
        } catch (Throwable $error) {
            // The message of the exception may carry a driver message or connection details.
            $findings = [DoctorFinding::error('doctor.page.failed', 'The doctor failed with '.$error::class.'.')];
        }
        $this->findings = array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $findings);
    }

    /**
     * The findings of one severity.
     *
     * @return list<array{key: string, severity: string, scope: string, message: string, details: array<string, mixed>}>
     */
    public function severity(string $severity): array
    {
        return array_values(array_filter($this->findings, static fn (array $finding): bool => $finding['severity'] === $severity));
    }

    /** @return list<string> the severities that have findings, the heaviest first */
    public function severities(): array
    {
        $present = array_unique(array_column($this->findings, 'severity'));

        return array_values(array_filter(array_map(static fn (Severity $severity): string => $severity->value, [Severity::Error, Severity::Warning]),
            static fn (string $severity): bool => in_array($severity, $present, true)));
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [Action::make('check')->label('Check again')->action($this->check(...))];
    }
}
