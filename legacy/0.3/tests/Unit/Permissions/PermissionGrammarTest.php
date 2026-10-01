<?php

declare(strict_types=1);

use AzGuard\Contracts\Permission;
use AzGuard\Exceptions\InvalidPermissionSyntaxException;
use AzGuard\Panels\Panel;
use AzGuard\Permissions\PermissionGrammar;
use AzGuard\Permissions\PermissionName;

enum GrammarPurePermission
{
    case View;
}

enum GrammarBackedPermission: string
{
    case View = 'docs.view';
}

final class GrammarClassPermission implements Permission
{
    public static function ability(): string
    {
        return 'docs.view';
    }
}

it('accepts documented wildcard, hierarchical and dynamic forms', function (string $key) {
    expect(PermissionGrammar::isValid($key))->toBeTrue();
    PermissionGrammar::assertValid($key);
})->with([
    '*',
    'app.*',
    'app.**',
    'app.team.{id}.edit',
    'app.documents.view',
    'a.b.c.d',
]);

it('rejects empty, whitespace, empty-dot and partial-wildcard forms', function (string $key) {
    expect(PermissionGrammar::isValid($key))->toBeFalse()
        ->and(fn () => PermissionGrammar::assertValid($key))
        ->toThrow(InvalidPermissionSyntaxException::class)
        ->and(fn () => PermissionName::resolve($key, 'test'))
        ->toThrow(InvalidPermissionSyntaxException::class);
})->with([
    '',
    '   ',
    'app..view',
    '.view',
    'app.',
    'app. view',
    'app.te*',
    'app.{',
    'app.{}',
]);

it('does not double-prefix an already scoped string', function () {
    $panel = Panel::make()->id('app')->scopedByPanelId(true);

    expect($panel->resolvePermission('app.docs.view'))->toBe('app.docs.view')
        ->and($panel->resolvePermission('docs.view'))->toBe('app.docs.view');
});

it('scopes pure enums, backed enums and Permission classes the same way', function () {
    $panel = Panel::make()->id('app')->scopedByPanelId(true);

    expect($panel->resolvePermission(GrammarPurePermission::View))->toBe('app.View')
        ->and($panel->resolvePermission(GrammarBackedPermission::View))->toBe('app.docs.view')
        ->and($panel->resolvePermission(GrammarClassPermission::class))->toBe('app.docs.view')
        ->and(PermissionName::resolve(GrammarBackedPermission::View, 'test'))->toBe('test.docs.view')
        ->and(PermissionName::resolve(GrammarClassPermission::class, 'test'))->toBe('test.docs.view');
});
