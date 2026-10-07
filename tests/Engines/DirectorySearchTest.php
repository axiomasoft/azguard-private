<?php

declare(strict_types=1);

use AzGuard\Directories\ModelSubjectDirectory;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Feature\Directories\Support\Person;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('people');
    ChangeWorld::clean();
    CrmWorld::seed();
    Schema::create('people', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
    Person::query()->insert([
        ['id' => 1, 'name' => '50% sale', 'email' => 'literal@example.test'],
        ['id' => 2, 'name' => '5000 sale', 'email' => 'plain@example.test'],
        ['id' => 3, 'name' => 'foo_bar', 'email' => 'underscore@example.test'],
        ['id' => 4, 'name' => 'foobar', 'email' => 'nounderscore@example.test'],
        ['id' => 5, 'name' => 'path\\name', 'email' => 'slash@example.test'],
        ['id' => 6, 'name' => 'pathname', 'email' => 'noslash@example.test'],
        ['id' => 7, 'name' => 'wow!50%_', 'email' => 'escape@example.test'],
    ]);
});
afterEach(function (): void {
    Schema::dropIfExists('people');
    ChangeWorld::clean();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('finds bound literal wildcard and escape characters without broadening or changing the term', function (string $term, array $ids): void {
    $panel = CrmWorld::compile();
    $lookup = Lookups::make($panel, target: null);
    $directory = new ModelSubjectDirectory([Person::class]);

    expect(array_map(fn ($option) => $option->subject->id(), $directory->search($term, $lookup, 10)))->toBe($ids);
})->with([
    'percent' => ['50%', ['1', '7']],
    'underscore' => ['foo_bar', ['3']],
    'backslash' => ['path\\name', ['5']],
    'escape' => ['wow!50%_', ['7']],
    'percent only' => ['%', ['1', '7']],
    'underscore only' => ['_', ['3', '7']],
    'untrusted term' => ['"; drop table people; --', []],
])->group('engines');
