<?php

declare(strict_types=1);

use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Tests\Stubs\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;

/**
 * C-12: the grantable_id user search built a raw `LIKE "%{$search}%"`, so a
 * search string containing `%`/`_` was interpreted as a SQL wildcard instead
 * of a literal character — widening the match beyond what the user typed.
 */
function buildDirectGrantForm(): Schema
{
    /** @var HasSchemas $livewire */
    $livewire = Mockery::mock(HasSchemas::class);

    return DirectGrantResource::form(Schema::make($livewire));
}

function grantableSearchResults(string $search): array
{
    $select = collect(buildDirectGrantForm()->getComponents())
        ->first(fn ($component) => $component instanceof Select && $component->getName() === 'grantable_id');

    return $select->getSearchResults($search);
}

it('treats an underscore in the search as a literal character, not a wildcard', function () {
    $exact = User::factory()->create(['name' => 'a_b']);
    User::factory()->create(['name' => 'axb']);

    $results = grantableSearchResults('a_b');

    expect($results)->toHaveKey($exact->getKey())
        ->and($results)->toHaveCount(1);
});

it('treats a percent sign in the search as a literal character, not a wildcard', function () {
    $exact = User::factory()->create(['name' => '50% done']);
    User::factory()->create(['name' => '50 done']);

    $results = grantableSearchResults('50%');

    expect($results)->toHaveKey($exact->getKey())
        ->and($results)->toHaveCount(1);
});
