<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Authorization\Visibility;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\GatePermission;
use AzGuard\Tests\Fixtures\PoliciesGate\GateRecord;
use AzGuard\Tests\Fixtures\PoliciesGate\GateWorld;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

final class GateSourceContractTest extends TestCase
{
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return GateSource::make();
    }

    public function test_gate_scalar_and_batch_agree_and_visibility_refuses_an_unqueryable_policy(): void
    {
        GateWorld::seed();

        try {
            Gate::define('contract-gate', static fn (User $user, GateRecord $record): bool => $record->getKey() === 1);
            [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'contract-gate')]);
            $allow = new GateRecord;
            $allow->setAttribute('id', 1);
            $deny = new GateRecord;
            $deny->setAttribute('id', 2);
            $requests = [GateWorld::request()->on(null, $allow), GateWorld::request()->on(null, $deny)];
            $batch = $authorizer->decideMany($requests);
            $this->assertTrue($authorizer->decide($panel, $requests[0])->allowed());
            $this->assertFalse($authorizer->decide($panel, $requests[1])->allowed());
            $this->assertTrue($batch->get(0)->allowed());
            $this->assertFalse($batch->get(1)->allowed());
            $this->expectException(VisibilityNotSupportedException::class);
            app(Visibility::class)->visibleTo($panel, GateRecord::query(), $requests[0]->subject(), GatePermission::Access);
        } finally {
            Relation::morphMap([], false);
        }
    }
}
