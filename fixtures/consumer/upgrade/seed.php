<?php

// Seeds the upgrade fixture through the public surface: three users, role grants (one with an expiry) and one user
// without a grant. Copied into the fixture application by bin/upgrade-fixture.sh and run there with `php seed.php`.
declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "error: upgrade fixture seed: {$message}\n");
    exit(1);
};

$users = [];

foreach (['one', 'two', 'three'] as $name) {
    $users[$name] = User::factory()->create(['name' => $name]);
}

// user 1: permanent editor; user 2: editor until far in the future; user 3: no grant.
Artisan::call('azguard:roles:grant', ['subject' => 'user:'.$users['one']->getKey(), 'role' => 'core:editor']) === 0 || $fail('grant 1: '.Artisan::output());
Artisan::call('azguard:roles:grant', ['subject' => 'user:'.$users['two']->getKey(), 'role' => 'core:editor', '--until' => '2099-01-01T00:00:00Z']) === 0 || $fail('grant 2: '.Artisan::output());

fwrite(STDOUT, 'seeded users '.implode(',', array_map(static fn (User $user) => $user->getKey(), $users))."\n");
