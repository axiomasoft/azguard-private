<?php

use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Panels\TestAdminPanelProvider;

test('panel provider boot не падает без каталога Policies', function () {
    $provider = new TestAdminPanelProvider(app());
    $provider->register();
    $provider->boot();

    expect($provider->panel(Panel::make())->getId())->toBe('admin');
})->group('panel');
