<?php

declare(strict_types=1);

use AzGuard\Registry\Values\PermissionSet;

describe('PermissionSet', function () {

    // ─── Construction ───────────────────────────────────────────────────────

    it('creates empty set', function () {
        $set = PermissionSet::empty();

        expect($set->isEmpty())->toBeTrue()
            ->and($set->count())->toBe(0)
            ->and($set->keys())->toBe([]);
    });

    it('creates wildcard set', function () {
        $set = PermissionSet::wildcard();

        expect($set->isWildcard())->toBeTrue()
            ->and($set->isEmpty())->toBeFalse();
    });

    it('creates set from keys and deduplicates', function () {
        $set = PermissionSet::fromKeys(['app.posts.view', 'app.posts.edit', 'app.posts.view']);

        expect($set->count())->toBe(2)
            ->and($set->keys())->toBe(['app.posts.view', 'app.posts.edit']);
    });

    // ─── has / grants ───────────────────────────────────────────────────────

    it('contains returns true for exact match', function () {
        $set = PermissionSet::fromKeys(['app.posts.view']);

        expect($set->has('app.posts.view'))->toBeTrue()
            ->and($set->has('app.posts.edit'))->toBeFalse();
    });

    it('wildcard set contains any key', function () {
        $set = PermissionSet::wildcard();

        expect($set->grants('anything.at.all'))->toBeTrue();
    });

    // ─── matchesWildcard ─────────────────────────────────────────────────────

    it('matches wildcard pattern', function () {
        $set = PermissionSet::fromKeys(['app.documents.*']);

        expect($set->matchesWildcard('app.documents.view'))->toBeTrue()
            ->and($set->matchesWildcard('app.documents.edit'))->toBeTrue()
            ->and($set->matchesWildcard('app.posts.view'))->toBeFalse();
    });

    it('global wildcard set matches any key via matchesWildcard', function () {
        $set = PermissionSet::wildcard();

        expect($set->matchesWildcard('whatever'))->toBeTrue();
    });

    it('set without wildcard patterns returns false for non-matching key', function () {
        $set = PermissionSet::fromKeys(['app.posts.view']);

        expect($set->matchesWildcard('app.posts.view'))->toBeFalse(); // exact, not pattern
    });

    // ─── grants ──────────────────────────────────────────────────────────────

    it('grants returns true for exact key', function () {
        $set = PermissionSet::fromKeys(['app.posts.view']);

        expect($set->grants('app.posts.view'))->toBeTrue();
    });

    it('grants returns true for wildcard pattern match', function () {
        $set = PermissionSet::fromKeys(['app.posts.*']);

        expect($set->grants('app.posts.delete'))->toBeTrue();
    });

    it('grants returns false when key not covered', function () {
        $set = PermissionSet::fromKeys(['app.posts.view', 'app.tags.*']);

        expect($set->grants('app.comments.create'))->toBeFalse();
    });

    // ─── merge ───────────────────────────────────────────────────────────────

    it('merges two regular sets and deduplicates', function () {
        $a = PermissionSet::fromKeys(['app.posts.view', 'app.posts.edit']);
        $b = PermissionSet::fromKeys(['app.posts.edit', 'app.tags.view']);
        $merged = $a->merge($b);

        expect($merged->keys())->toContain('app.posts.view')
            ->toContain('app.posts.edit')
            ->toContain('app.tags.view')
            ->and($merged->count())->toBe(3);
    });

    it('merging with wildcard yields wildcard', function () {
        $regular = PermissionSet::fromKeys(['app.posts.view']);
        $wild = PermissionSet::wildcard();

        expect($regular->merge($wild)->isWildcard())->toBeTrue()
            ->and($wild->merge($regular)->isWildcard())->toBeTrue();
    });

    it('merging two empty sets gives empty set', function () {
        $merged = PermissionSet::empty()->merge(PermissionSet::empty());

        expect($merged->isEmpty())->toBeTrue();
    });

    // ─── filter ──────────────────────────────────────────────────────────────

    it('filter keeps only matching keys', function () {
        $set = PermissionSet::fromKeys(['app.posts.view', 'app.posts.edit', 'app.tags.view']);
        $known = ['app.posts.view', 'app.tags.view'];

        $filtered = $set->filter(fn (string $k) => in_array($k, $known, true));

        expect($filtered->keys())->toBe(['app.posts.view', 'app.tags.view']);
    });

    it('filter of wildcard set still returns filtered set', function () {
        // Wildcard '*' as a key: filter checks string '*', not expansion
        $set = PermissionSet::wildcard();
        $filtered = $set->filter(fn (string $k) => $k !== '*');

        expect($filtered->isEmpty())->toBeTrue()
            ->and($filtered->isWildcard())->toBeFalse();
    });

    // Pins the documented wildcard semantics after the F22 flip (D18): '*'
    // matches exactly ONE segment, '**' recurses across segments, and the
    // literal prefix is segment-anchored. The legacy dot-crossing grammar
    // survives for one cycle behind features.wildcard_permission.
    it('wildcard pattern matches one segment and is prefix-anchored', function () {
        $prefix = PermissionSet::fromKeys(['app.documents.*']);

        expect($prefix->matchesWildcard('app.documents.view'))->toBeTrue()
            // one segment only: deeper keys need '**'
            ->and($prefix->matchesWildcard('app.documents.nested.deep'))->toBeFalse()
            // the segment boundary is anchored: 'documentsX' is not 'documents.'
            ->and($prefix->matchesWildcard('app.documentsX.view'))->toBeFalse();

        $recursive = PermissionSet::fromKeys(['app.documents.**']);

        expect($recursive->matchesWildcard('app.documents.nested.deep'))->toBeTrue();

        $top = PermissionSet::fromKeys(['app.*']);

        expect($top->matchesWildcard('app.documents'))->toBeTrue()
            ->and($top->matchesWildcard('app.documents.view'))->toBeFalse()
            ->and($top->matchesWildcard('admin.documents'))->toBeFalse();
    });
});
