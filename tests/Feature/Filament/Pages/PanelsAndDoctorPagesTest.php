<?php

declare(strict_types=1);

use AzGuard\Diagnostics\Doctor;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\PanelOverview;
use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Filament\Pages\PanelsPage;
use AzGuard\Tests\Fixtures\Diagnostics\ProbeCheck;
use AzGuard\Tests\Fixtures\Filament\EditorWorld;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Livewire\Livewire;

/*
 * V63: the panels page lists a panel without a database source that no editor offers; the doctor page shows the findings
 * of `azguard:doctor` by severity without secrets and runs the doctor on opening and on request, not on a render.
 * Both pages open with their page permission only and show data that the snapshots in Fixtures/Filament/Snapshots hold
 * (set AZGUARD_UPDATE_SNAPSHOTS=1 to write them again).
 */

const SECRET = 'fixture password';

beforeEach(function (): void {
    GateWorld::prepare();
    EditorWorld::prepare();
    FilamentFixture::$packagePages = ['panels' => true, 'doctor' => true];
    FilamentFixture::$manages = null;
    FilamentFixture::$memberPermissions = [];
    FilamentFixture::$doctorChecks = [
        new ProbeCheck('probe.page', static fn (): array => [
            DoctorFinding::error('probe.page', 'Cannot connect with password '.SECRET.'.', 'core', ['note' => 'plain']),
            DoctorFinding::warning('probe.page.slow', 'A slow thing.'),
        ]),
    ];
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
    config(['database.connections.leaky' => ['driver' => 'sqlite', 'database' => ':memory:', 'password' => SECRET]]);
    ProbeCheck::$panels = [];
});

/** @param array<mixed> $data */
function pageSnapshot(string $name, array $data): void
{
    $path = __DIR__.'/../../../Fixtures/Filament/Snapshots/'.$name.'.json';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

    if (getenv('AZGUARD_UPDATE_SNAPSHOTS') === '1') {
        file_put_contents($path, $json);
    }

    expect($json)->toBe((string) file_get_contents($path));
}

it('V63 lists a panel without a database source that the editors do not offer', function (): void {
    EditorWorld::editor(['pages.azguard-panels']);

    $component = Livewire::test(PanelsPage::class);
    $ids = array_column($component->get('panels'), 'id');
    $readonly = array_values(array_filter($component->get('panels'), static fn (array $panel): bool => $panel['id'] === 'readonly'))[0];

    expect($ids)->toContain('readonly', 'admin', 'seller')
        ->and($readonly['writer'])->toBeNull()
        ->and(TargetSelector::current()->panels())->not->toHaveKey('readonly')
        ->and(TargetSelector::current()->panels())->toHaveKeys(['admin', 'seller']);
    $component->assertSee('readonly')->assertSee('Settings')->assertSee('Sources');
});

it('shows the data of PanelOverview and nothing else', function (): void {
    EditorWorld::editor(['pages.azguard-panels']);

    $panels = Livewire::test(PanelsPage::class)->get('panels');

    expect($panels)->toBe(app(PanelOverview::class)->all(true, true, true));
    pageSnapshot('panels', $panels);
});

it('opens the panels page and the doctor page with their permissions only', function (): void {
    $this->actingAs(User::query()->findOrFail(1));
    $this->get('/admin/azguard-panels')->assertForbidden();
    $this->get('/admin/azguard-doctor')->assertForbidden();

    GateWorld::grant(['pages.azguard-panels']);
    $this->get('/admin/azguard-panels')->assertOk();
    $this->get('/admin/azguard-doctor')->assertForbidden();

    GateWorld::grant(['pages.azguard-doctor']);
    $this->get('/admin/azguard-doctor')->assertOk()->assertSee('probe.page');
    $this->actingAs(User::query()->findOrFail(2));
    $this->get('/admin/azguard-panels')->assertForbidden();
});

it('shows the findings of the doctor by severity', function (): void {
    EditorWorld::editor(['pages.azguard-doctor']);

    $component = Livewire::test(DoctorPage::class);
    $keys = array_column($component->get('findings'), 'key');

    expect($keys)->toContain('probe.page', 'probe.page.slow')
        ->and($component->instance()->severities()[0])->toBe('error')
        ->and(array_column($component->instance()->severity('warning'), 'key'))->toContain('probe.page.slow')
        ->and(array_column($component->instance()->severity('error'), 'key'))->toContain('probe.page')->not->toContain('probe.page.slow');
    $component->assertSee('A slow thing.');
    $doctor = app(Doctor::class);
    expect($component->get('findings'))->toBe(array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $doctor->run($doctor->context())));
});

it('keeps secrets out of the data and the HTML of the doctor page', function (): void {
    EditorWorld::editor(['pages.azguard-doctor']);

    $component = Livewire::test(DoctorPage::class)->assertSee('Cannot connect with password');

    expect(json_encode($component->get('findings')))->not->toContain(SECRET)
        ->and($component->html())->not->toContain(SECRET)
        ->and(json_encode($component->get('findings')))->toContain(DoctorFinding::REDACTED);
});

it('runs the doctor when the page opens and on request, not on a render', function (): void {
    EditorWorld::editor(['pages.azguard-doctor']);

    $component = Livewire::test(DoctorPage::class);
    $opened = count(ProbeCheck::$panels);
    expect($opened)->toBeGreaterThan(0);

    $component->call('$refresh')->call('$refresh');
    expect(count(ProbeCheck::$panels))->toBe($opened);

    $component->callAction('check');
    expect(count(ProbeCheck::$panels))->toBe($opened * 2);
});

it('does not take the findings from the payload of the component', function (): void {
    EditorWorld::editor(['pages.azguard-doctor']);

    expect(fn () => Livewire::test(DoctorPage::class)->set('findings', [['key' => 'x', 'severity' => 'error', 'scope' => 'core', 'message' => 'forged', 'details' => []]]))
        ->toThrow(Exception::class);
});

it('snapshots the findings of the doctor page', function (): void {
    EditorWorld::editor(['pages.azguard-doctor']);

    pageSnapshot('doctor', Livewire::test(DoctorPage::class)->get('findings'));
});
