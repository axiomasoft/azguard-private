<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

describe('HTTP denial', function (): void {
    beforeEach(function (): void {
        HttpWorld::seed();
        HttpWorld::panel();
        HttpWorld::actingAs(2);
        Route::get('/disclosure', static fn () => 'allowed')->middleware('azguard.can:crm:clients.update');
    });
    afterEach(function (): void {
        CrmWorld::resetRuntime();
        Carbon::setTestNow();
        Relation::morphMap([], false);
    });

    it('renders generic HTML and JSON without internal decision details', function (bool $json): void {
        $response = $json ? $this->getJson('/disclosure', ['X-Tenant' => '1', 'X-Project' => '1']) : $this->get('/disclosure', ['X-Tenant' => '1', 'X-Project' => '1']);
        $response->assertForbidden();
        $body = $response->getContent();
        expect($body)->toContain('This action is unauthorized.')
            ->not->toContain('not_granted', 'crm.project', 'crm.organization', 'grant', 'trace', 'source', 'exception');
    })->with([false, true]);

    it('keeps the stable reason code on the internal authorization response', function (): void {
        $this->withoutExceptionHandling();

        try {
            $this->getJson('/disclosure', ['X-Tenant' => '1', 'X-Project' => '1']);
            $this->fail('Expected a denial');
        } catch (AuthorizationException $error) {
            expect($error->status())->toBe(403)
                ->and($error->response()?->code())->toBe(DecisionReason::NotGranted->value);
        }
    });

});
