<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Scopes\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses()->group('batch');

it('preserves a custom queryable scope resolver refusal in batch decisions', function (bool $throws): void {
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
    });
    Project::query()->insert(['id' => 1]);
    $definition = new class($throws) extends BaseAssignmentScope
    {
        public function __construct(private readonly bool $throws) {}

        public function type(): string
        {
            return 'project';
        }

        public function query(): Builder
        {
            return Project::query();
        }

        public function tenantOf(Model $record): TenantRef
        {
            return TenantRef::global();
        }

        public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
        {
            if ($this->throws) {
                throw new RuntimeException('Structural scope authority is unavailable.');
            }

            return null;
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile(
        new GeneratedSource(direct: [AuthorizationWorld::grant()]),
        fn (PanelBuilder $panel) => $panel->scopes(AssignmentScopePolicy::inherit($definition)),
    );
    $request = $request->on(AssignmentScopeRef::of('project', 1));
    $expected = $throws ? DecisionReason::AssignmentScopeFilterError : DecisionReason::AssignmentScopeNotAccepted;

    expect($engine->decide($panel, $request)->reason)->toBe($expected)
        ->and($engine->decideMany([$request, $request])->get(0)->reason)->toBe($expected);
})->with([false, true]);
