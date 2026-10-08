<?php

declare(strict_types=1);

use AzGuard\Events\RoleGranted;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CrmWorld::seed();
    W::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('grants a role through the pipeline as the system actor named after the command', function (): void {
    Event::fake([RoleGranted::class]);
    $version = W::version();

    expect(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'seller', '--panel' => 'crm', '--tenant' => 'crm.organization:1',
        '--on' => 'crm.project:2', '--until' => '2026-12-31T18:00:00+03:00', '--field' => ['region=R1'], '--origin' => 'import']))->toBe(0)
        ->and(Artisan::output())->toContain('applied', '1 effect(s)');
    $row = collect(W::rows())->firstWhere('origin', 'import');

    expect($row)->toMatchArray(['role' => 'seller', 'subject_id' => '2', 'context_key' => 'crm.project:2', 'expires_at' => '2026-12-31 15:00:00',
        'actor_type' => ActorRef::SYSTEM_TYPE, 'actor_id' => null, 'actor_reason' => 'azguard:roles:grant'])
        ->and(json_decode((string) $row['meta'], true))->toMatchArray(['region' => 'R1'])
        ->and(W::version())->toBe($version + 1);
    Event::assertDispatched(RoleGranted::class, static fn (RoleGranted $event): bool => $event->actor?->reason === 'azguard:roles:grant');
});

it('V64 takes the panel from a full role name or a role class and refuses a conflict', function (): void {
    expect(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'crm:auditor', '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:3', 'role' => AuditorRole::class, '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(W::keys())->toContain('crm.organization:1|auditor|2|global|manual', 'crm.organization:1|auditor|3|global|manual')
        ->and(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'crm:auditor', '--panel' => 'backoffice', '--tenant' => 'crm.organization:1']))->toBe(2)
        ->and(Artisan::output())->toContain('disagree')
        ->and(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'auditor', '--tenant' => 'crm.organization:1']))->toBe(2);
});

it('revokes in the given scope and origin only', function (): void {
    expect(Artisan::call('azguard:roles:revoke', ['subject' => 'crm.user:1', 'role' => 'seller', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--on' => 'crm.project:1']))->toBe(0)
        ->and(W::keys())->not->toContain('crm.organization:1|seller|1|crm.project:1|manual')
        ->and(W::keys())->toContain('crm.organization:1|analyst|1|crm.project:2|manual', 'crm.organization:1|seller|2|crm.project:2|manual')
        ->and(Artisan::call('azguard:roles:revoke', ['subject' => 'crm.user:1', 'role' => 'analyst', '--panel' => 'crm', '--tenant' => 'crm.organization:1',
            '--on' => 'crm.project:2', '--origin' => 'import']))->toBe(0)
        ->and(Artisan::output())->toContain('unchanged')
        ->and(W::keys())->toContain('crm.organization:1|analyst|1|crm.project:2|manual');
});

it('refuses a missing tenant, a bad date or a bad field before anything is written', function (array $arguments, string $message): void {
    $version = W::version();
    $rows = W::rows();

    expect(Artisan::call('azguard:roles:grant', [...['subject' => 'crm.user:2', 'role' => 'auditor', '--panel' => 'crm', '--tenant' => 'crm.organization:1'], ...$arguments]))->toBe(2)
        ->and(Artisan::output())->toContain($message)
        ->and(W::version())->toBe($version)
        ->and(W::rows())->toBe($rows);
})->with([
    'no tenant' => [['--tenant' => null], '--tenant'],
    'date words' => [['--until' => 'tomorrow'], 'ISO-8601'],
    'impossible date' => [['--until' => '2026-02-31T10:00:00Z'], 'ISO-8601'],
    'field without value' => [['--field' => ['region']], 'key=value'],
]);

it('reports a refused change with exit code 1 and writes nothing', function (): void {
    $version = W::version();

    expect(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'root', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(1)
        ->and(Artisan::output())->toContain('RoleNotGrantableException')
        ->and(Artisan::call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'ghost', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(1)
        ->and(Artisan::output())->toContain('UnknownRoleException')
        ->and(W::version())->toBe($version);
});

it('V113 cleans up a grant of a removed role through the CLI', function (): void {
    CrmWorld::assign('ghost', 2, 2);

    expect(Artisan::call('azguard:roles:revoke', ['subject' => 'crm.user:2', 'role' => 'ghost', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--on' => 'crm.project:2']))->toBe(0)
        ->and(W::keys())->not->toContain('crm.organization:1|ghost|2|crm.project:2|manual');
});
