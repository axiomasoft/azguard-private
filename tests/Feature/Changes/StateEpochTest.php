<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Support\Carbon;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
    Carbon::setTestNow();
});

it('returns the committed epoch for panel touches resets and dynamic permission changes', function (): void {
    $panel = W::dynamicPanel();
    EventWorld::listen();
    $epoch = CrmWorld::storage()->state('crm')?->epoch ?? 0;
    $operations = [
        static fn () => W::pipeline()->touch($panel, 'refresh'),
        static fn () => W::pipeline()->reset($panel),
        static fn () => W::createAction($panel, 'campaigns.view')->state,
        static fn () => W::pipeline()->updatePermission($panel, W::tenant(), 'campaigns.view', new PermissionDetails('Updated'))->state,
        static fn () => W::deleteAction($panel, 'campaigns.view')->state,
    ];

    foreach ($operations as $operate) {
        $token = $operate();
        $committed = $panel->writer()->state($panel, TenantRef::global());
        expect($token->epoch)->toBe(++$epoch)->and($token->equals($committed))->toBeTrue();
        $events = EventWorld::events();
        expect($events[array_key_last($events)]->state->equals($committed))->toBeTrue();
    }
});

it('preserves a nonzero epoch in changed and unchanged grant results', function (): void {
    $panel = W::panel();
    W::pipeline()->touch($panel, 'refresh');
    $epoch = CrmWorld::storage()->state('crm')->epoch;

    foreach ([ChangeStatus::Applied, ChangeStatus::Unchanged] as $status) {
        $result = W::grant($panel, 'analyst', 2, 1);
        $committed = $panel->writer()->state($panel, TenantRef::global());
        expect($result->status)->toBe($status)->and($result->state->epoch)->toBe($epoch)
            ->and($result->state->equals($committed))->toBeTrue();
    }
});
