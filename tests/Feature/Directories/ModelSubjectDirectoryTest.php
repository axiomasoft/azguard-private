<?php

declare(strict_types=1);

use AzGuard\Directories\DirectoryResolver;
use AzGuard\Directories\ModelSubjectDirectory;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Feature\Directories\Support\FixtureSubjectDirectory;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Feature\Directories\Support\Person;
use AzGuard\Tests\Feature\Directories\Support\UnsafePerson;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    World::seed();
    Schema::create('people', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
    Person::query()->insert([
        ['id' => 1, 'name' => 'Ольга', 'email' => 'olga@example.test'],
        ['id' => 2, 'name' => '50% скидка', 'email' => 'sale@example.test'],
        ['id' => 3, 'name' => '5000 скидка', 'email' => 'bulk@example.test'],
        ['id' => 12, 'name' => 'Иван', 'email' => 'ivan_12@example.test'],
    ]);
});

afterEach(function (): void {
    World::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

function people(?Closure $configure = null): Panel
{
    return World::compile(fn (PanelBuilder $panel) => $configure === null ? $panel->for(model: Person::class) : $configure($panel->for(model: Person::class)));
}

it('searches subjects by key and by the declared columns and labels them with azguardLabel', function (): void {
    $panel = people();
    $lookup = Lookups::make($panel, target: null);
    $directory = DirectoryResolver::for($panel, app())->subjects('dir.person');

    expect(array_map(fn ($o) => $o->subject->key(), $directory->search('', $lookup, 10)))->toBe(['dir.person:1', 'dir.person:2', 'dir.person:3', 'dir.person:12'])
        ->and(array_map(fn ($o) => $o->subject->id(), $directory->search('Ольг', $lookup, 10)))->toBe(['1'])
        ->and($directory->search('olga', $lookup, 10)[0]->label)->toBe('Ольга <olga@example.test>')
        ->and(array_map(fn ($o) => $o->subject->id(), $directory->search('12', $lookup, 10)))->toBe(['12'])
        ->and(array_map(fn ($o) => $o->subject->id(), $directory->search('ivan', $lookup, 10)))->toBe(['12'])
        ->and($directory->search('nobody', $lookup, 10))->toBe([]);
});

it('treats wildcards of the term as text and never as a pattern', function (): void {
    $panel = people();
    $lookup = Lookups::make($panel, target: null);
    $directory = DirectoryResolver::for($panel, app())->subjects('dir.person');
    $ids = fn (string $term): array => array_map(fn ($o) => $o->subject->id(), $directory->search($term, $lookup, 10));
    expect($ids('50%'))->toBe(['2'])
        ->and($ids('1%2'))->toBe([])
        ->and($ids('%'))->toBe(['2'])
        ->and($ids('_'))->toBe(['12'])
        ->and($ids('"; drop table people; --'))->toBe([]);
});

it('finds literal underscores and escape characters without matching the spelling with them removed', function (): void {
    Person::query()->insert([
        ['id' => 20, 'name' => 'foo_bar', 'email' => 'literal@example.test'],
        ['id' => 21, 'name' => 'foobar', 'email' => 'plain@example.test'],
        ['id' => 22, 'name' => 'path\\name', 'email' => 'slash@example.test'],
        ['id' => 23, 'name' => 'pathname', 'email' => 'noslash@example.test'],
        ['id' => 24, 'name' => 'wow!50%_', 'email' => 'escape@example.test'],
    ]);
    $panel = people();
    $lookup = Lookups::make($panel, target: null);
    $directory = DirectoryResolver::for($panel, app())->subjects('dir.person');
    $ids = fn (string $term): array => array_map(fn ($o) => $o->subject->id(), $directory->search($term, $lookup, 10));

    expect($ids('foo_bar'))->toBe(['20'])
        ->and($ids('path\\name'))->toBe(['22'])
        ->and($ids('wow!50%_'))->toBe(['24']);
});

it('limits and orders results by key and selects the model by morph type', function (): void {
    $panel = people();
    $lookup = Lookups::make($panel, target: null);
    $all = DirectoryResolver::for($panel, app())->subjects();

    expect(array_map(fn ($o) => $o->subject->key(), $all->search('', $lookup, 3)))->toBe(['crm.user:1', 'crm.user:2', 'crm.user:3'])
        ->and(array_map(fn ($o) => $o->subject->key(), $all->search('', $lookup, 6)))->toBe(['crm.user:1', 'crm.user:2', 'crm.user:3', 'crm.user:4', 'dir.person:1', 'dir.person:2'])
        ->and($all->search('', $lookup, 0))->toBe([])
        ->and(array_map(fn ($o) => $o->subject->key(), $all->search('', $lookup, 2, 'dir.person')))->toBe(['dir.person:1', 'dir.person:2'])
        ->and($all->search('', $lookup, 5, 'unknown.type'))->toBe([])
        ->and(DirectoryResolver::for($panel, app())->subjects('unknown.type')->search('', $lookup, 5))->toBe([]);
});

it('describes a subject of the panel and nothing else', function (): void {
    $panel = people();
    $lookup = Lookups::make($panel, target: null);
    $directory = DirectoryResolver::for($panel, app())->subjects();

    expect($directory->describe(SubjectRef::of('dir.person', 1), $lookup)?->label)->toBe('Ольга <olga@example.test>')
        ->and($directory->describe(SubjectRef::of('crm.user', 2), $lookup)?->label)->toBe('2')
        ->and($directory->describe(SubjectRef::of('dir.person', 99), $lookup))->toBeNull()
        ->and($directory->describe(SubjectRef::of('foreign.type', 1), $lookup))->toBeNull();
});

it('works without a selected target and for every phase', function (AssignmentScopePhase $phase): void {
    $panel = people();
    $lookup = Lookups::make($panel, target: null, actor: null, phase: $phase);

    expect(DirectoryResolver::for($panel, app())->subjects('dir.person')->search('olga', $lookup, 5))->toHaveCount(1);
})->with([[AssignmentScopePhase::Assignment], [AssignmentScopePhase::Inspection], [AssignmentScopePhase::Revocation]]);

it('refuses search columns that are not plain column names', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->for(model: UnsafePerson::class));
    $lookup = Lookups::make($panel, target: null);

    expect(fn () => DirectoryResolver::for($panel, app())->subjects('dir.unsafe')->search('x', $lookup, 5))->toThrow(DefinitionException::class);
});

it('keeps a custom subject directory declared with for() and selects the default for the others', function (): void {
    $panel = people(fn (PanelBuilder $p) => $p->for(model: User::class, directory: FixtureSubjectDirectory::class));
    $lookup = Lookups::make($panel, target: null);
    $resolver = DirectoryResolver::for($panel, app());

    expect($panel->subject(User::class)?->directory)->toBe(FixtureSubjectDirectory::class)
        ->and($resolver->subjects('crm.user'))->toBeInstanceOf(FixtureSubjectDirectory::class)
        ->and($resolver->subjects('crm.user')->search('x', $lookup, 5)[0]->label)->toBe('custom:x')
        ->and($resolver->subjects('dir.person'))->toBeInstanceOf(ModelSubjectDirectory::class)
        ->and(array_map(fn ($o) => $o->subject->key(), $resolver->subjects()->search('olga', $lookup, 5)))->toBe(['crm.user:1', 'dir.person:1'])
        ->and($resolver->subjects()->describe(SubjectRef::of('dir.person', 1), $lookup)?->label)->toBe('Ольга <olga@example.test>');
});

it('refuses a subject directory class that does not implement the contract', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->for(model: User::class, directory: stdClass::class));

    expect(fn () => DirectoryResolver::for($panel, app())->subjects())->toThrow(DefinitionException::class);
});
