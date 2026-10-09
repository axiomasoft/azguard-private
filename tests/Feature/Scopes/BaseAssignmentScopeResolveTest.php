<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('projects');
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->string('organization_id');
        $table->timestamps();
    });
});

it('loads a project once and refuses a reference of another type', function (): void {
    Model::unguarded(fn () => Project::query()->create(['organization_id' => 'acme']));
    $scope = ConfiguredProjectScope::make();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolved = $scope->resolve(AssignmentScopeRef::of('crm.project', 1));

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($resolved?->record?->getAttribute('organization_id'))->toBe('acme')
        ->and($resolved?->tenant->id())->toBe('acme')
        ->and($scope->resolve(AssignmentScopeRef::of('crm.project', 99)))->toBeNull()
        ->and(fn () => $scope->resolve(AssignmentScopeRef::of('crm.client', 1)))
        ->toThrow(InvalidAssignmentScopeException::class, 'crm.client')
        ->and(fn () => $scope->resolve(AssignmentScopeRef::global()))
        ->toThrow(InvalidAssignmentScopeException::class, 'global');
});

it('resolves a context only by the exact key, the same way the batch path does', function (): void {
    Model::unguarded(fn () => Project::query()->create(['organization_id' => 'acme']));
    $scope = ConfiguredProjectScope::make();

    DB::flushQueryLog();
    DB::enableQueryLog();
    // PostgreSQL rejects 'a/b' and '1.0' with an error; neither is ever sent to the database.
    foreach (['a/b', '1.0', '9223372036854775808'] as $id) {
        expect($scope->resolve(AssignmentScopeRef::of('crm.project', $id)))->toBeNull();
    }
    expect(DB::getQueryLog())->toBe([])
        // SQLite and MySQL coerce '01' to the row 1: it is not project 1.
        ->and($scope->resolve(AssignmentScopeRef::of('crm.project', '01')))->toBeNull()
        ->and($scope->resolve(AssignmentScopeRef::of('crm.project', '1'))?->record?->getKey())->toBe(1);
});
