<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeStatus;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

/*
 * A list field kept in its own JSON column: written as the JSON text of the list, read and compared as the list.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    $storage = CrmWorld::storage();
    $storage->connection()->getSchemaBuilder()->table($storage->prefix().'role_grants', static function (Blueprint $table): void {
        $table->json('tags')->nullable();
    });
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

function listColumnPanel(array $fields = []): Panel
{
    return W::panel(configure: static fn (PanelBuilder $panel) => $panel->fields(FieldTarget::RoleGrant, [Field::string('tags')->multiple(), Field::int('levels')->multiple()->inMeta(), ...$fields]));
}

/** @return list<mixed>|null the list the column holds, decoded */
function listColumn(): ?array
{
    $json = CrmWorld::storage()->table('role_grants')->where('role', 'auditor')->value('tags');

    return $json === null ? null : json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
}

it('writes a list field of a column as JSON and reads it back as the list', function (): void {
    $panel = listColumnPanel();
    $first = W::grant($panel, 'auditor', 2, null, fields: ['tags' => ['finance', 'sales'], 'levels' => [1, 2]]);

    expect($first->status)->toBe(ChangeStatus::Applied)
        ->and(listColumn())->toBe(['finance', 'sales'])
        ->and($first->record?->fields['tags'])->toBe(['finance', 'sales'])
        ->and($first->record?->fields['levels'])->toBe([1, 2]);
});

it('answers Unchanged to the same list and Applied to another order, an empty list and null', function (array $next, ?array $column): void {
    $panel = listColumnPanel();
    W::grant($panel, 'auditor', 2, null, fields: ['tags' => ['finance', 'sales']]);
    $version = W::version();
    $again = W::grant($panel, 'auditor', 2, null, fields: ['tags' => ['finance', 'sales']]);

    expect($again->status)->toBe(ChangeStatus::Unchanged)->and(W::version())->toBe($version);

    $changed = W::grant($panel, 'auditor', 2, null, fields: $next);

    expect($changed->status)->toBe(ChangeStatus::Applied)->and(listColumn())->toBe($column)->and(W::version())->toBe($version + 1);
})->with([
    'another order' => [['tags' => ['sales', 'finance']], ['sales', 'finance']],
    'an empty list' => [['tags' => []], []],
    'null' => [['tags' => null], null],
]);

it('refuses a map and a bad element, and writes nothing', function (array $tags): void {
    $panel = listColumnPanel();
    $rows = CrmWorld::storage()->table('role_grants')->count();

    expect(fn () => W::grant($panel, 'auditor', 2, null, fields: ['tags' => $tags]))->toThrow(InvalidChangeFieldsException::class)
        ->and(CrmWorld::storage()->table('role_grants')->count())->toBe($rows);
})->with([
    'a map' => [['a' => 'finance']],
    'a gap in the keys' => [[0 => 'finance', 2 => 'sales']],
    'an element that is not a string' => [[['nested']]],
]);
