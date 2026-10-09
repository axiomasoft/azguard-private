<?php

declare(strict_types=1);

use AzGuard\Scopes\ModelKey;
use AzGuard\Tests\Fixtures\Scopes\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('projects');
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->string('organization_id');
        $table->timestamps();
    });
    Model::unguarded(function (): void {
        Project::query()->create(['organization_id' => 'acme']);
        Project::query()->create(['organization_id' => 'globex']);
    });
});

it('lets an integer key hold only a canonical integer and a string key any id', function (string $id, bool $int): void {
    $string = new class extends Model
    {
        protected $keyType = 'string';
    };
    $integer = new class extends Model
    {
        protected $keyType = 'integer';
    };

    expect(ModelKey::canHold(new Project, $id))->toBe($int)
        ->and(ModelKey::canHold($integer, $id))->toBe($int)
        ->and(ModelKey::canHold($string, $id))->toBeTrue();
})->with([
    ['1', true], ['0', true], ['-7', true], ['9223372036854775807', true],
    ['01', false], ['-0', false], ['1.0', false], ['1e3', false], [' 1', false], ['a/b', false], ['9223372036854775808', false],
]);

it('constrains a query to the ids the key can hold and to nothing when none is left', function (): void {
    expect(ModelKey::where(Project::query(), ['2', '01', 'a/b', null])->pluck('id')->all())->toBe([2])
        ->and(ModelKey::where(Project::query(), ['01', 'a/b'])->count())->toBe(0)
        ->and(ModelKey::where(Project::query(), [])->count())->toBe(0)
        ->and(ModelKey::where(Project::query(), '1')->pluck('id')->all())->toBe([1]);
});

it('finds only the row whose key is the id as written', function (): void {
    expect(ModelKey::find(Project::query(), '2')?->getAttribute('organization_id'))->toBe('globex')
        ->and(ModelKey::find(Project::query(), '02'))->toBeNull()
        ->and(ModelKey::find(Project::query(), '3'))->toBeNull()
        ->and(ModelKey::of(new Project))->toBeNull()
        ->and(ModelKey::of(Project::query()->findOrFail(1)))->toBe('1');
});
