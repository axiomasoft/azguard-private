<?php

declare(strict_types=1);

use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Concerns\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Concerns\Roles\EditorRole;
use AzGuard\Tests\Fixtures\Concerns\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;
use AzGuard\Tests\Fixtures\Roles\SellerRole;

/*
 * Roles are panel signals of the same rule: a full role name `panel:key` names its panel, a code role class names the
 * panels that register it (several are ambiguous without another signal). A role key names nothing. Fixture: admin
 * (default) registers manager/support/root/auditor, cabinet registers editor/auditor.
 */

beforeEach(fn () => SubjectWorld::seed());

it('picks the panel of a role signal before the request and the default panel', function (array $roles, array $panels, ?string $current, string $expected): void {
    [$registry, $currentPanel] = SubjectWorld::compile();
    $currentPanel->set($current === null ? null : $registry->get($current));

    try {
        $outcome = app(PanelResolver::class)->select(SubjectWorld::member(), roles: $roles, panels: $panels)->id();
    } catch (AmbiguousPanelException|ConflictingPanelException|UnknownRoleException $e) {
        $outcome = $e::class;
    }

    expect($outcome)->toBe($expected);
})->with([
    'a role key names no panel' => [['manager'], [], null, 'admin'],
    'a role key follows the request' => [['editor'], [], 'cabinet', 'cabinet'],
    'a class of one panel' => [[EditorRole::class], [], 'admin', 'cabinet'],
    'a class of one panel with a leading backslash' => [['\\'.ManagerRole::class], [], 'cabinet', 'admin'],
    'a full role name' => [['cabinet:editor'], [], 'admin', 'cabinet'],
    'a class of two panels' => [[AuditorRole::class], [], 'admin', AmbiguousPanelException::class],
    'a class of two panels with a named panel' => [[AuditorRole::class], ['cabinet'], null, 'cabinet'],
    'classes of different panels' => [[ManagerRole::class, EditorRole::class], [], null, ConflictingPanelException::class],
    'a full name against a named panel' => [['admin:manager'], ['cabinet'], null, ConflictingPanelException::class],
    'a class registered nowhere' => [[SellerRole::class], [], null, UnknownRoleException::class],
]);

it('lets the trait find the panel of a role class', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();

    expect($member->grantRole(EditorRole::class)->applied())->toBeTrue()
        ->and(SubjectWorld::roleRows())->toBe(['cabinet|editor|1|global|manual'])
        ->and($member->hasRole(EditorRole::class))->toBeTrue()
        ->and(fn () => $member->hasRole(AuditorRole::class))->toThrow(AmbiguousPanelException::class)
        ->and($member->hasRole(AuditorRole::class, guard: 'cabinet'))->toBeFalse()
        ->and(fn () => $member->guard('admin')->grantRole(EditorRole::class))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $member->hasRole('editor'))->toThrow(UnknownRoleException::class);
});
