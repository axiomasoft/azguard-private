<?php

declare(strict_types=1);

use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Guard\AzGuardDiagnostics;
use AzGuard\Support\RequestState;
use AzGuard\Tests\Stubs\CountingDiagnostics;

/**
 * Proves DoctorPage memoizes AzGuardDiagnostics::diagnose() for the whole
 * request instead of re-running the (reflection-heavy) diagnostics once per
 * Filament render hook (navigation badge, badge colour, view data — up to 3×
 * per render). Memoization is via the scoped RequestState, so it is Octane-safe:
 * a fresh request gets a fresh RequestState and recomputes.
 */
it('runs diagnose() exactly once across the three render hooks', function (): void {
    $spy = new CountingDiagnostics;
    app()->instance(AzGuardDiagnostics::class, $spy);

    // The three Filament render hooks that each independently ask for the result.
    DoctorPage::getNavigationBadge();
    DoctorPage::getNavigationBadgeColor();
    (new DoctorPage)->getDiagnoseResult();

    expect($spy->calls)->toBe(1);
});

it('memoizes through the scoped RequestState, not a per-call recompute', function (): void {
    $spy = new CountingDiagnostics;
    app()->instance(AzGuardDiagnostics::class, $spy);

    // Hammer a single hook repeatedly — still one underlying diagnose().
    DoctorPage::getNavigationBadge();
    DoctorPage::getNavigationBadge();
    DoctorPage::getNavigationBadge();

    expect($spy->calls)->toBe(1)
        ->and(app(RequestState::class))->toBeInstanceOf(RequestState::class);
});

it('recomputes once per request lifecycle (memo does not survive a fresh RequestState)', function (): void {
    $spy = new CountingDiagnostics;
    app()->instance(AzGuardDiagnostics::class, $spy);

    (new DoctorPage)->getDiagnoseResult();
    expect($spy->calls)->toBe(1);

    // Simulate the next request on a reused (Octane) worker: the scoped
    // RequestState is flushed, so the memo is gone and diagnose() runs again.
    app()->forgetInstance(RequestState::class);
    app()->instance(RequestState::class, new RequestState);

    (new DoctorPage)->getDiagnoseResult();
    expect($spy->calls)->toBe(2);
});

it('surfaces the memoized diagnose payload unchanged to the view and badge', function (): void {
    $spy = new CountingDiagnostics([
        'errors' => ['boom'],
        'warnings' => ['careful'],
        'abilities' => [['panel' => 'admin', 'ability' => 'view', 'handler' => 'X::y']],
    ]);
    app()->instance(AzGuardDiagnostics::class, $spy);

    $result = (new DoctorPage)->getDiagnoseResult();

    expect($result['errors'])->toBe(['boom'])
        ->and($result['warnings'])->toBe(['careful'])
        ->and(DoctorPage::getNavigationBadge())->toBe('1')
        ->and(DoctorPage::getNavigationBadgeColor())->toBe('danger')
        ->and($spy->calls)->toBe(1);
});
