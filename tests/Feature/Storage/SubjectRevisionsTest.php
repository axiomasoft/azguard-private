<?php

declare(strict_types=1);

use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Schema\Blueprint;

// Schema 2 (audits/2026-10-09-consistency-design.md, step 3): a grant change moves the panel version and the revision
// of its subject; a change whose subjects are unknown (touch, reset, definitions) moves the version and the epoch.

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

/** @return array<string, int> subject id => revision */
function subjectRevisions(): array
{
    return CrmWorld::storage()->table('subject_revisions')->where('panel', 'crm')->orderBy('subject_id')
        ->pluck('revision', 'subject_id')->map(static fn (mixed $revision): int => (int) $revision)->all();
}

it('moves the revision of the written subject only and leaves the epoch', function (): void {
    $panel = W::panel();
    $before = CrmWorld::storage()->state('crm');
    $revisions = subjectRevisions();

    W::grant($panel, 'auditor', 2, null);
    W::grant($panel, 'auditor', 2, null);
    W::revoke($panel, 'auditor', 2, null);
    $after = CrmWorld::storage()->state('crm');

    // The repeat is unchanged: no write, no touch. A missing row is revision 0.
    expect(subjectRevisions())->toBe([...$revisions, '2' => ($revisions['2'] ?? 0) + 2])
        ->and($after->version)->toBe($before->version + 2)
        ->and($after->epoch)->toBe($before->epoch);
});

it('moves the epoch for a touch and atomically with the incarnation for a reset, never a subject revision', function (): void {
    $panel = W::panel();
    W::grant($panel, 'auditor', 2, null);
    $before = CrmWorld::storage()->state('crm');
    $revisions = subjectRevisions();

    W::pipeline()->touch($panel, 'manual');
    $touched = CrmWorld::storage()->state('crm');
    W::pipeline()->reset($panel);
    $reset = CrmWorld::storage()->state('crm');

    expect([$touched->version, $touched->epoch, $touched->incarnation])->toBe([$before->version + 1, $before->epoch + 1, $before->incarnation])
        ->and([$reset->version, $reset->epoch])->toBe([$before->version + 2, $before->epoch + 2])
        ->and($reset->incarnation)->not->toBe($before->incarnation)
        ->and(subjectRevisions())->toBe($revisions);
});

it('writes no revision for a change rolled back with the host transaction', function (): void {
    $panel = W::panel();
    [$state, $revisions] = [CrmWorld::storage()->state('crm'), subjectRevisions()];

    expect(fn () => CrmWorld::storage()->connection()->transaction(static function () use ($panel): void {
        W::grant($panel, 'auditor', 3, null);

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class, 'rolled back');
    W::grant($panel, 'auditor', 1, null);

    expect(subjectRevisions())->toBe([...$revisions, '1' => ($revisions['1'] ?? 0) + 1])
        ->and(CrmWorld::storage()->state('crm')->version)->toBe($state->version + 1);
});

it('upgrades a schema 1 storage in place and names the upgrade until then', function (): void {
    $storage = CrmWorld::storage();
    $schema = $storage->connection()->getSchemaBuilder();
    $schema->drop('azg_subject_revisions');
    $schema->table('azg_panel_state', static fn (Blueprint $table) => $table->dropColumn('epoch'));
    $storage->table('storage_state')->update(['schema' => json_encode([...$storage->schema(), 'version' => 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    app()->forgetInstance(StorageRegistry::class);
    $stale = app(StorageRegistry::class)->get('default');

    expect(fn () => $stale->verifySchema())->toThrow(StorageMismatchException::class, 'schema 2 upgrade migration');

    app(StorageSchema::class)->upgrade('default');
    app(StorageSchema::class)->upgrade('default');
    app()->forgetInstance(StorageRegistry::class);
    $upgraded = app(StorageRegistry::class)->get('default');
    $upgraded->verifySchema();

    expect($schema->hasTable('azg_subject_revisions'))->toBeTrue()
        ->and($upgraded->state('crm')?->epoch)->toBe(0);
    W::grant(W::panel(), 'auditor', 2, null);
    expect(subjectRevisions())->toBe(['2' => 1]);
});
