<?php

// Boots the application of the relocated-audit fixture. `grant` makes one audited change; `rows`, `commands` and
// `autoload` print, as JSON, the facts the rehearsal compares before and after the Audit module moves to its own archive.
declare(strict_types=1);

use App\Guards\Audited\Permissions\ReportPermission;
use App\Models\User;
use AzGuard\Audit\AuditPlugin;
use AzGuard\Audit\AuditServiceProvider;
use AzGuard\Facades\AzGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "error: relocated-audit fixture: {$message}\n");
    exit(1);
};
$print = static function (array $value): void {
    fwrite(STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
};

switch ($argv[1] ?? '') {
    case 'grant':
        $user = User::factory()->create();
        Artisan::call('azguard:roles:grant', ['subject' => 'user:'.$user->getKey(), 'role' => 'audited:reviewer']) === 0
            || $fail('azguard:roles:grant failed: '.Artisan::output());
        AzGuard::check($user, ReportPermission::Export) || $fail('the granted role does not pass the check');

        break;
    case 'rows':
        // One hash per journal row over its event id and stored payload, in insertion order.
        $rows = [];
        foreach (DB::table('azg_audit_log')->orderBy('id')->get(['event_id', 'type', 'payload']) as $row) {
            $rows[] = ['event_id' => $row->event_id, 'type' => $row->type, 'sha256' => hash('sha256', $row->event_id."\n".$row->payload)];
        }
        $print(['count' => count($rows), 'rows' => $rows]);

        break;
    case 'commands':
        $commands = [];
        foreach (Artisan::all() as $name => $command) {
            if (str_starts_with($name, 'azguard:audit:')) {
                $commands[$name] = $command->getDefinition()->getSynopsis();
            }
        }
        ksort($commands);
        $print($commands);

        break;
    case 'autoload':
        $print([
            'AuditPlugin' => (string) realpath((string) (new ReflectionClass(AuditPlugin::class))->getFileName()),
            'AuditServiceProvider' => (string) realpath((string) (new ReflectionClass(AuditServiceProvider::class))->getFileName()),
        ]);

        break;
    default:
        $fail('usage: php journal.php grant|rows|commands|autoload');
}
