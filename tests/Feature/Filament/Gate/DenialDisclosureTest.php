<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

it('keeps the bulk denial notification generic', function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));
    $component = Livewire::test(ListOrders::class)->selectTableRecords([1, 4])
        ->callAction(TestAction::make('delete')->table()->bulk());
    app(NotificationMailbox::class)->flush();
    $notifications = new Notifications;
    $notifications->mount();
    $notifications->pullNotificationsFromSession();
    $payload = json_encode($notifications->notifications->toArray(), JSON_THROW_ON_ERROR);
    expect($notifications->notifications)->toHaveCount(1)
        ->and($payload)->toContain('delete')->not->toContain('not_granted', 'source', 'trace', 'grant', 'scope', 'exception');
});
