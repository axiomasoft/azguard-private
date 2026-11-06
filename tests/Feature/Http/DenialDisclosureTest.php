<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Laravel\Http\DecisionFailedException;
use AzGuard\Laravel\Http\DecisionResponder;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

final class SecretThrowingHttpSource implements ProvidesGrants
{
    public static bool $throw = false;

    public function id(): string
    {
        return 'custom:secret-http-source';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if (self::$throw) {
            throw new RuntimeException('SQLSTATE[42S02]: Secret table azguard_internal_grants');
        }

        return [];
    }
}

final class SecretThrowingHttpPolicy
{
    #[Decides(ClientPermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        throw new RuntimeException('SQLSTATE[42S02]: Secret table azguard_internal_grants');
    }
}

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

    it('does not disclose exception messages, table names, or source and policy classes when a source or policy throws', function (bool $json): void {
        SecretThrowingHttpSource::$throw = false;
        $panel = HttpWorld::panel(
            static fn (PanelBuilder $builder) => $builder->policies([SecretThrowingHttpPolicy::class]),
            [CrmWorld::database(), new SecretThrowingHttpSource],
        );
        HttpWorld::actingAs(1);

        Route::get('/disclosure-source', static fn () => 'allowed')->middleware('azguard.can:crm:clients.update');
        Route::get('/disclosure-policy', static fn () => 'allowed')->middleware('azguard.can:crm:clients.view_any');
        Route::get('/responder-source', static function () use ($panel): string {
            DecisionResponder::authorize(CrmWorld::decide($panel, 1, ClientPermission::Update, 1, 1));

            return 'allowed';
        });
        Route::get('/responder-policy', static function () use ($panel): string {
            $request = AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of($panel->id(), ClientPermission::ViewAny->value))
                ->inScope(CrmWorld::scope(1, 1));
            DecisionResponder::authorize(app(Authorizer::class)->decide($panel, $request));

            return 'allowed';
        });

        $headers = ['X-Tenant' => '1', 'X-Project' => '1'];

        SecretThrowingHttpSource::$throw = true;
        $sourceResponse = $json ? $this->getJson('/disclosure-source', $headers) : $this->get('/disclosure-source', $headers);
        $sourceResponse->assertForbidden();
        expect($sourceResponse->getContent())
            ->toContain('This action is unauthorized.')
            ->not->toContain(
                'SQLSTATE',
                '42S02',
                'azguard_internal_grants',
                'Secret table',
                SecretThrowingHttpSource::class,
                'SecretThrowingHttpSource',
                'custom:secret-http-source',
                'source_error',
            );

        $responderSource = $json ? $this->getJson('/responder-source', $headers) : $this->get('/responder-source', $headers);
        $responderSource->assertStatus(503);
        expect($responderSource->getContent())
            ->not->toContain(
                'SQLSTATE',
                '42S02',
                'azguard_internal_grants',
                'Secret table',
                SecretThrowingHttpSource::class,
                'SecretThrowingHttpSource',
                'custom:secret-http-source',
                'source_error',
            );

        try {
            DecisionResponder::authorize(CrmWorld::decide($panel, 1, ClientPermission::Update, 1, 1));
            $this->fail('Expected DecisionFailedException for source error.');
        } catch (DecisionFailedException $error) {
            expect($error->getStatusCode())->toBe(503)
                ->and($error->getMessage())->toBe('Authorization is temporarily unavailable.')
                ->and($error->getMessage())->not->toContain('SQLSTATE', 'azguard_internal_grants', 'SecretThrowingHttpSource');
        }

        SecretThrowingHttpSource::$throw = false;
        $policyResponse = $json ? $this->getJson('/disclosure-policy', $headers) : $this->get('/disclosure-policy', $headers);
        $policyResponse->assertForbidden();
        expect($policyResponse->getContent())
            ->toContain('This action is unauthorized.')
            ->not->toContain(
                'SQLSTATE',
                '42S02',
                'azguard_internal_grants',
                'Secret table',
                SecretThrowingHttpPolicy::class,
                'SecretThrowingHttpPolicy',
                'policy_error',
            );

        $responderPolicy = $json ? $this->getJson('/responder-policy', $headers) : $this->get('/responder-policy', $headers);
        $responderPolicy->assertStatus(500);
        expect($responderPolicy->getContent())
            ->not->toContain(
                'SQLSTATE',
                '42S02',
                'azguard_internal_grants',
                'Secret table',
                SecretThrowingHttpPolicy::class,
                'SecretThrowingHttpPolicy',
                'policy_error',
            );
    })->with([false, true]);

});
