<?php

declare(strict_types=1);

use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\SharedPermission;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;

/*
 * One table for the panel selection rule: subject × permission form × named panel × panel of the request ×
 * default panel. Every entry point of the package must give the outcome of this table, because every entry point
 * asks the same resolver.
 *
 * Panels: `admin` takes User and Vendor, `cabinet` takes User and Seller. `OrderPermission` is attached to
 * `admin`, `SharedPermission` to both.
 */

const SELECTION_SUBJECTS = ['admin only' => Vendor::class, 'cabinet only' => Seller::class, 'both' => User::class];

const SELECTION_ACCEPTS = [
    'admin' => [Vendor::class, User::class],
    'cabinet' => [Seller::class, User::class],
];

/**
 * Permission form => panels the form names by itself (an empty list: the form names no panel).
 */
const SELECTION_PERMISSIONS = [
    'local' => [],
    'prefixed' => ['admin'],
    'full' => ['admin'],
    'enum of one panel' => ['admin'],
    'enum of two panels' => ['admin', 'cabinet'],
];

function selectionPermission(string $form): string|UnitEnum
{
    return match ($form) {
        'local' => 'orders.view',
        'prefixed' => 'admin.orders.view',
        'full' => 'admin:orders.view',
        'enum of one panel' => OrderPermission::View,
        default => SharedPermission::Export,
    };
}

/**
 * The rule as a table lookup, written without the resolver: the expected panel id or exception class.
 */
function selectionExpectation(string $model, string $form, ?string $named, ?string $current, bool $adminIsDefault): string
{
    $signals = array_values(array_filter([$named === null ? null : [$named], SELECTION_PERMISSIONS[$form] ?: null]));

    if ($signals !== []) {
        $allowed = array_values(array_intersect(...$signals));

        return match (true) {
            $allowed === [] => ConflictingPanelException::class,
            count($allowed) > 1 => AmbiguousPanelException::class,
            ! in_array($model, SELECTION_ACCEPTS[$allowed[0]], true) => SubjectNotAcceptedException::class,
            default => $allowed[0],
        };
    }

    if ($current !== null && in_array($model, SELECTION_ACCEPTS[$current], true)) {
        return $current;
    }

    $accepting = array_keys(array_filter(SELECTION_ACCEPTS, static fn (array $models): bool => in_array($model, $models, true)));

    return match (true) {
        $adminIsDefault && in_array('admin', $accepting, true) => 'admin',
        count($accepting) === 1 => $accepting[0],
        default => PanelNotResolvedException::class,
    };
}

it('picks one panel for every combination of signals', function (string $model, string $form, ?string $named, ?string $current, bool $adminIsDefault): void {
    [$resolver, $currentPanel, $registry] = PanelWorld::adminAndCabinet($adminIsDefault);
    $currentPanel->set($current === null ? null : $registry->get($current));
    $expected = selectionExpectation($model, $form, $named, $current, $adminIsDefault);

    try {
        $outcome = $resolver->resolve(new $model, selectionPermission($form), $named)['panel']->id();
    } catch (ConflictingPanelException|AmbiguousPanelException|SubjectNotAcceptedException|PanelNotResolvedException $e) {
        $outcome = $e::class;
    }

    expect($outcome)->toBe($expected)
        ->and($currentPanel->get()?->id())->toBe($current);
})->with(function (): Generator {
    foreach (SELECTION_SUBJECTS as $subject => $model) {
        foreach (array_keys(SELECTION_PERMISSIONS) as $form) {
            foreach ([null, 'admin', 'cabinet'] as $named) {
                foreach ([null, 'admin', 'cabinet'] as $current) {
                    foreach ([false, true] as $adminIsDefault) {
                        yield sprintf(
                            '%s | %s | named %s | current %s | default %s',
                            $subject, $form, $named ?? '—', $current ?? '—', $adminIsDefault ? 'admin' : '—',
                        ) => [$model, $form, $named, $current, $adminIsDefault];
                    }
                }
            }
        }
    }
});

it('pins the outcomes the table must contain', function (string $model, string $form, ?string $named, ?string $current, bool $adminIsDefault, string $expected): void {
    expect(selectionExpectation($model, $form, $named, $current, $adminIsDefault))->toBe($expected);
})->with([
    'a name of admin never falls to the default panel' => [User::class, 'full', null, 'cabinet', false, 'admin'],
    'the named panel beats the request and the default' => [User::class, 'local', 'cabinet', 'admin', true, 'cabinet'],
    'the request beats the default of the model' => [User::class, 'local', null, 'cabinet', true, 'cabinet'],
    'the default of the model without a request' => [User::class, 'local', null, null, true, 'admin'],
    'the only panel of the model' => [Seller::class, 'local', null, null, false, 'cabinet'],
    'the request is skipped for a foreign subject' => [Seller::class, 'local', null, 'admin', false, 'cabinet'],
    'two equal panels and nothing else' => [User::class, 'local', null, null, false, PanelNotResolvedException::class],
    'named panel against a prefix' => [User::class, 'prefixed', 'cabinet', null, false, ConflictingPanelException::class],
    'named panel against a full name' => [User::class, 'full', 'cabinet', 'cabinet', true, ConflictingPanelException::class],
    'named panel against an enum of one panel' => [User::class, 'enum of one panel', 'cabinet', null, false, ConflictingPanelException::class],
    'an enum of two panels needs a named panel' => [User::class, 'enum of two panels', null, 'admin', true, AmbiguousPanelException::class],
    'an enum of two panels with a named panel' => [User::class, 'enum of two panels', 'cabinet', 'admin', true, 'cabinet'],
    'a named panel that does not take the subject' => [Seller::class, 'full', null, 'cabinet', false, SubjectNotAcceptedException::class],
]);
