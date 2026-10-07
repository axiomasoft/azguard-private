<?php

declare(strict_types=1);

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Tests\Fixtures\Concerns\CustomGuardMember;
use AzGuard\Tests\Fixtures\Concerns\Member;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;
use Illuminate\Support\Facades\Auth;

/*
 * V108: guard(array|string $guarded) keeps the Eloquent mass-assignment selector for arrays and selects a panel for
 * strings; neither changes Auth, the current panel or the model.
 */

beforeEach(function (): void {
    SubjectWorld::seed();
    SubjectWorld::compile();
});

it('keeps the native guard(array) of Eloquent: the same model, guarded, mergeGuarded and fill', function (): void {
    $member = new Member;

    expect($member->guard(['secret']))->toBe($member)
        ->and($member->getGuarded())->toBe(['secret'])
        ->and($member->guard(guarded: ['name']))->toBe($member)
        ->and($member->getGuarded())->toBe(['name'])
        ->and($member->mergeGuarded(['secret'])->getGuarded())->toBe(['name', 'secret'])
        ->and($member->fill(['name' => 'Вера', 'secret' => 'x', 'is_root' => true])->getAttributes())->toBe(['is_root' => true])
        ->and($member->guard([])->fill(['name' => 'Вера', 'secret' => 'x'])->getAttributes())->toBe(['is_root' => true, 'name' => 'Вера', 'secret' => 'x'])
        ->and($member->isGuarded('is_root'))->toBeFalse();
});

it('selects a panel for a string without changing the model, Auth or the current panel', function (): void {
    $member = SubjectWorld::member();
    $guarded = $member->getGuarded();
    $driver = Auth::getDefaultDriver();
    $authGuard = Auth::guard();
    $current = app(CurrentPanel::class)->get();

    $admin = $member->guard('admin');
    $cabinet = $member->guard(guarded: 'cabinet');

    expect($admin)->toBeInstanceOf(SubjectAccess::class)
        ->and($admin->panel()->id())->toBe('admin')
        ->and($cabinet->panel()->id())->toBe('cabinet')
        ->and($member->guard('admin'))->not->toBe($admin)
        ->and($member->azguard()->guard('cabinet')->panel()->id())->toBe('cabinet')
        ->and($member->getGuarded())->toBe($guarded)
        ->and(Auth::getDefaultDriver())->toBe($driver)
        ->and(Auth::guard())->toBe($authGuard)
        ->and(app(CurrentPanel::class)->get())->toBe($current)
        ->and(fn () => $member->guard('unknown'))->toThrow(UnknownPanelException::class);
});

it('keeps the parameter name and union signature that Eloquent callers rely on', function (): void {
    $method = new ReflectionMethod(Member::class, 'guard');
    $parameter = $method->getParameters()[0];

    expect($method->getDeclaringClass()->getName())->toBe(Member::class)
        ->and($parameter->getName())->toBe('guarded')
        ->and((string) $parameter->getType())->toBe('array|string')
        ->and(array_map(strval(...), $method->getReturnType()->getTypes()))->toEqualCanonicalizing(['static', SubjectAccess::class])
        ->and((new ReflectionMethod(HasAzGuard::class, 'guard'))->getDocComment())->toContain('@return ($guarded is array ? static : SubjectAccess)');
});

it('lets a model with its own guard() keep it and reach panels through azguard()', function (): void {
    $member = CustomGuardMember::query()->findOrFail(1);

    expect($member->guard(['secret']))->toBe($member)
        ->and($member->guardCalls)->toBe([['secret']])
        ->and($member->getGuarded())->toBe(['secret'])
        ->and($member->azguard()->guard('cabinet')->panel()->id())->toBe('cabinet')
        ->and($member->azguard()->guard('admin')->grantRole('manager')->applied())->toBeTrue()
        ->and($member->hasRole('manager'))->toBeTrue()
        ->and($member->azguard()->guard('admin')->hasPermission('orders.view'))->toBeTrue()
        ->and($member->guardCalls)->toBe([['secret']]);
});
