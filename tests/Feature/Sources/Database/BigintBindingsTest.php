<?php

declare(strict_types=1);

use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Reads;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

it('preserves adjacent large subjects in batched grants and subject revision reads and writes', function (string $hostKeys): void {
    config()->set('azguard.storages.default.host_keys', $hostKeys);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);
    app(StorageSchema::class)->create('default');
    $ids = ['9007199254740992', '9007199254740993'];
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['subject_id' => $ids[1]])]);
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source);
    $storage = DatabaseWorld::storage();
    $bound = [];
    $storage->connection()->listen(static function (QueryExecuted $query) use (&$bound, $ids): void {
        foreach ($query->bindings as $value) {
            if (in_array((string) $value, $ids, true)) {
                $bound[] = $value;
            }
        }
    });
    $subject = SubjectRef::of('user', $ids[1]);
    for ($revision = 0; $revision < 2; $revision++) {
        $storage->mutate('admin', static fn (StorageMutation $mutation) => $mutation->touchSubject('admin', $subject));
    }
    $session = $storage->readSession(Reads::Primary);
    [$rows, $revisions, $first, $second] = $session->snapshot(static function () use ($source, $session, $frame, $ids): array {
        $rows = $source->assignmentRowsMany($session, $frame, array_map(static fn (string $id): SubjectRef => SubjectRef::of('user', $id), $ids), [$frame->scope()]);
        [, $revisions] = $session->observedMany('admin', 'user', $ids);
        [, $first] = $session->observed('admin', 'user', $ids[0]);
        [, $second] = $session->observed('admin', 'user', $ids[1]);

        return [$rows, $revisions, $first, $second];
    });

    expect($rows[0]['permission_grant'])->toBe([])
        ->and($rows[1]['permission_grant'])->toHaveCount(1)
        ->and((string) $rows[1]['permission_grant'][0]['subject_id'])->toBe($ids[1])
        ->and($revisions)->toBe([$ids[1] => 2])
        ->and([$first, $second])->toBe([0, 2])
        ->and($bound)->not->toBeEmpty();
    foreach ($bound as $value) {
        expect(get_debug_type($value))->toBe($hostKeys === 'bigint' ? 'int' : 'string');
    }
})->with(['bigint', 'string']);
