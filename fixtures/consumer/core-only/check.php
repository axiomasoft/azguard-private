<?php

// Boots the application of the core-only fixture and walks one grant and one check through core alone.
declare(strict_types=1);

use App\Guards\Core\Permissions\NotePermission;
use App\Models\User;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "error: core-only fixture: {$message}\n");
    exit(1);
};

foreach (['AzGuard\\Audit\\AuditPlugin', 'AzGuard\\Scopes\\ScopesPlugin', 'AzGuard\\DevTools\\DevToolsServiceProvider'] as $module) {
    class_exists($module) && $fail("{$module} autoloads although the archive has no modules/ directory");
}

$user = User::factory()->create();

AzGuard::check($user, NotePermission::View) && $fail('a user without a grant passes the check');
AzGuard::panel('core')->for($user)->decide(NotePermission::View)->reason === DecisionReason::NotGranted || $fail('the denial is not "not granted"');

Artisan::call('azguard:roles:grant', ['subject' => 'user:'.$user->getKey(), 'role' => 'core:editor']) === 0 || $fail('azguard:roles:grant failed: '.Artisan::output());

AzGuard::check($user, NotePermission::Edit) || $fail('the granted role does not pass the check');

$commands = array_keys(Artisan::all());
foreach ($commands as $name) {
    (str_starts_with($name, 'azguard:make:') || in_array($name, ['azguard:install', 'azguard:stubs', 'azguard:audit:migration', 'azguard:audit:prune'], true)) && $fail("the command {$name} is registered without its module");
}

fwrite(STDOUT, "core-only: grant and check OK\n");
