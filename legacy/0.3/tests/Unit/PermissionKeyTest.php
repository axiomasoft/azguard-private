<?php

declare(strict_types=1);

use AzGuard\Permissions\PermissionKey;

enum PermissionKeyStringBacked: string
{
    case View = 'documents.view';
}

enum PermissionKeyIntBacked: int
{
    case Low = 5;
}

enum PermissionKeyPure
{
    case Edit;
}

describe('PermissionKey::normalize', function () {
    it('passes a plain string through unchanged', function () {
        expect(PermissionKey::normalize('app.posts.view'))->toBe('app.posts.view');
    });

    it('returns the value of a string-backed enum', function () {
        expect(PermissionKey::normalize(PermissionKeyStringBacked::View))->toBe('documents.view');
    });

    it('casts the value of an int-backed enum to string', function () {
        expect(PermissionKey::normalize(PermissionKeyIntBacked::Low))->toBe('5');
    });

    it('returns the case name of a pure enum', function () {
        expect(PermissionKey::normalize(PermissionKeyPure::Edit))->toBe('Edit');
    });
});
