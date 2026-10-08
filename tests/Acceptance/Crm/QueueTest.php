<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Queue\ExportClientsJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * CRM over a real queue: the `database` connection and `queue:work`, one worker process serving several jobs.
 * R53: an export job carries references and is authorized again when it runs. R54 (queue part): jobs one after another
 * in one worker share no panel, scope or authorizer.
 */

beforeEach(function (): void {
    config(['queue.default' => 'database', 'queue.failed' => ['driver' => 'null'], 'queue.connections.database' => [
        'driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
    ]]);
    Schema::create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    HttpWorld::authenticate();
    HttpWorld::panel();
    ExportClientsJob::reset();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/clients/{client}/export', static function (int $client): string {
            ExportClientsJob::dispatch(user: (int) HttpWorld::$user, client: $client, tenant: 1);

            return 'queued';
        });
    });
});
afterEach(function (): void {
    ExportClientsJob::reset();
});

/** Runs the jobs that wait, in one worker, to the end of the queue. */
function work(): void
{
    expect(Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 4096]))->toBe(0);
}

it('R53 authorizes an export job as the subject it carries when it runs, not as the user logged in then', function (): void {
    HttpWorld::actingAs(1);
    $this->get('/clients/1/export', ['X-Tenant' => '1'])->assertOk();
    expect(DB::table('jobs')->count())->toBe(1);

    // The job is dequeued while the application serves another user.
    HttpWorld::actingAs(2);
    work();

    expect(ExportClientsJob::$runs)->toHaveCount(1)
        ->and(ExportClientsJob::$runs[0]['user'])->toBe(1)
        ->and(ExportClientsJob::$runs[0]['auth'])->toBe(2)
        ->and(ExportClientsJob::$runs[0]['allowed'])->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0);

    ExportClientsJob::reset();
    $this->get('/clients/1/export', ['X-Tenant' => '1'])->assertOk();
    work();

    // Boris is the user of that request: his own export of the same client is refused.
    expect(ExportClientsJob::$runs)->toHaveCount(1)
        ->and(ExportClientsJob::$runs[0]['user'])->toBe(2)
        ->and(ExportClientsJob::$runs[0]['allowed'])->toBeFalse();
});

it('R53 forbids an export queued before the right was taken away', function (): void {
    HttpWorld::actingAs(1);
    $this->get('/clients/1/export', ['X-Tenant' => '1'])->assertOk();
    HttpWorld::expire(1);
    HttpWorld::actingAs(2);

    work();

    expect(ExportClientsJob::$runs)->toHaveCount(1)
        ->and(ExportClientsJob::$runs[0]['user'])->toBe(1)
        ->and(ExportClientsJob::$runs[0]['allowed'])->toBeFalse();
});

it('R54 keeps the panel, the scope and the authorizer of one job away from the next job of the worker', function (): void {
    HttpWorld::actingAs(1);
    $this->get('/clients/1/export', ['X-Tenant' => '1'])->assertOk();
    ExportClientsJob::dispatch(user: 1, client: 1, tenant: 1);

    work();

    expect(ExportClientsJob::$runs)->toHaveCount(2)
        ->and(ExportClientsJob::$runs[0]['panel'])->toBe('crm')
        ->and(ExportClientsJob::$runs[0]['hint'])->toBe('crm')
        ->and(ExportClientsJob::$runs[1]['panel'])->toBeNull()
        ->and(ExportClientsJob::$runs[1]['hint'])->toBeNull()
        ->and(ExportClientsJob::$runs[0]['scope'])->toBeNull()
        ->and(ExportClientsJob::$runs[1]['scope'])->toBeNull()
        ->and(ExportClientsJob::$runs[0]['authorizer'])->not->toBe(ExportClientsJob::$runs[1]['authorizer'])
        ->and(array_column(ExportClientsJob::$runs, 'allowed'))->toBe([true, true]);
});
