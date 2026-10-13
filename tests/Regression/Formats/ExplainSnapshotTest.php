<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * I17, blocking now: the document `azguard:explain --json` prints. The shape is pinned key by key
 * with the type of every leaf; the decision object and the step sequence are pinned by value. Build ids and
 * fingerprints are hashes of the code and stay types only.
 */

beforeEach(fn () => GateWorld::seed());
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** The keys of a JSON document at every depth with the type of each leaf; a list shows the shape of each of its items. */
function baselineJsonShape(mixed $value): mixed
{
    if (is_array($value)) {
        return $value === [] ? '[]' : array_map(baselineJsonShape(...), $value);
    }

    return get_debug_type($value);
}

/** @return array{exit: int, decision: mixed, shape: mixed, steps: list<string>} */
function baselineExplain(string $permission): array
{
    $exit = Artisan::call('azguard:explain', ['subject' => 'user:1', 'permission' => $permission, '--panel' => 'admin', '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return ['exit' => $exit, 'decision' => $json['decision'], 'shape' => baselineJsonShape($json),
        'steps' => array_map(static fn (array $step): string => $step['stage'].':'.$step['outcome'].':'.$step['result'], $json['steps']),
        'step_keys' => array_values(array_unique(array_merge(...array_map(array_keys(...), $json['steps']))))];
}

it('keeps the explain document of an allow, a policy denial and a missing grant', function (): void {
    GateWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    RuntimePolicy::$result = Response::denyAsNotFound('hidden', 'policy-code');
    $snapshot = ['allow' => baselineExplain('orders.view'), 'policy_deny' => baselineExplain('orders.policy'), 'not_granted' => baselineExplain('orders.edit')];
    [$actual, $stored] = FormatSnapshot::compare('explain', $snapshot);

    expect($actual)->toBe($stored);
});

it('has no path that decides not_applicable: the factory exists, nothing calls it', function (): void {
    $callers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/packages', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && str_contains($file->getPathname(), '/src/')
            && preg_match('/(?<!function )notApplicable\(/', (string) file_get_contents($file->getPathname())) === 1) {
            $callers[] = $file->getFilename();
        }
    }

    expect($callers)->toBe([]);
});
