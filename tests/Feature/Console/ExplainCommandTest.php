<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => GateWorld::seed());
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('prints stable allow and policy denial JSON snapshots', function (bool $policy): void {
    GateWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    RuntimePolicy::$result = Response::denyAsNotFound('hidden', 'policy-code');
    $exit = Artisan::call('azguard:explain', ['subject' => 'user:1', 'permission' => $policy ? 'orders.policy' : 'orders.view', '--panel' => 'admin', '--json' => true]);
    expect($exit)->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $snapshot = ['decision' => $json['decision'], 'panel' => $json['panel'], 'subject' => $json['subject'], 'scope' => $json['scope'], 'now' => $json['now']];
    $expected = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Explain/'.($policy ? 'policy-deny' : 'allow').'.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot)->toBe($expected);
})->with([false, true]);

it('prints redacted steps as JSON and as a table', function (bool $json): void {
    GateWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant(fields: ['api_token' => 'operator-secret'])]));
    expect(Artisan::call('azguard:explain', ['subject' => 'user:1', 'permission' => 'admin:orders.view', '--json' => $json]))->toBe(0);
    expect(Artisan::output())->toContain('[redacted]', 'contribution')->not->toContain('operator-secret');
})->with([false, true]);

it('fails an invalid or missing identity with exit 2 and an unknown permission with exit 1, naming the problem', function (array $arguments, int $exit, string $message): void {
    GateWorld::compile(new GeneratedSource);
    expect(Artisan::call('azguard:explain', [...['subject' => 'user:1', 'permission' => 'admin:orders.view'], ...$arguments]))->toBe($exit);
    expect(Artisan::output())->toContain($message)->not->toContain('Stack trace');
})->with([
    'missing subject' => [['subject' => 'user:999'], 2, 'The subject does not exist in panel admin.'],
    'malformed subject' => [['subject' => 'missing-colon'], 2, 'An identity has the form type:id.'],
    'malformed tenant' => [['--tenant' => 'missing-colon'], 2, 'An identity has the form type:id.'],
    'malformed context' => [['--context' => 'missing-colon'], 2, 'An identity has the form type:id.'],
    'unknown permission' => [['permission' => 'admin:orders.unknown'], 1, 'UnknownPermissionException: Permission "admin:orders.unknown" is not in the catalog'],
]);
