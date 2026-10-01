<?php

declare(strict_types=1);

it('runs the install command to success', function () {
    $this->artisan('guard:install')
        ->expectsConfirmation('Run database migrations now?', 'no')
        ->expectsConfirmation('Star axioma-studio/azguard on GitHub to support the project?', 'no')
        ->assertSuccessful();
});

it('runs migrations when confirmed', function () {
    $this->artisan('guard:install')
        ->expectsConfirmation('Run database migrations now?', 'yes')
        ->expectsConfirmation('Star axioma-studio/azguard on GitHub to support the project?', 'no')
        ->assertSuccessful();
});
