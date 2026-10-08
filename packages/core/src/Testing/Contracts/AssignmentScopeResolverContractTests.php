<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

/**
 * For the authors of assignment scope resolvers: what every resolver guarantees.
 *
 * Use the trait in a test case and implement `azguardScopeResolver()` and `azguardScopedRequests()`.
 *
 * - the same request gives the same scope;
 * - the scope survives the codec and has no ":" in its type;
 * - a request that names no scope gives null, not an error and not the global scope.
 *
 * @api
 */
trait AssignmentScopeResolverContractTests
{
    /** A new instance of the resolver under test. */
    abstract protected function azguardScopeResolver(): AssignmentScopeResolver;

    /**
     * Requests that name a scope the resolver finds.
     *
     * @return list<Request>
     */
    abstract protected function azguardScopedRequests(): array;

    /** A request that names no scope. */
    protected function azguardUnscopedRequest(): Request
    {
        return Request::create('/');
    }

    #[Test]
    public function resolvingIsIdempotent(): void
    {
        $this->assertNotSame([], $this->azguardScopedRequests(), 'Give the suite at least one request that names a scope.');
        $resolver = $this->azguardScopeResolver();

        foreach ($this->azguardScopedRequests() as $request) {
            $first = $this->azguardResolved($resolver, $request);

            $this->assertTrue($first->equals($this->azguardResolved($resolver, $request)), 'The same request resolves to different scopes.');
            $this->assertTrue($first->equals($this->azguardResolved($this->azguardScopeResolver(), $request)), 'Two instances of the resolver disagree about the same request.');
        }
    }

    #[Test]
    public function scopeSurvivesTheCodecAndHasNoColonInItsType(): void
    {
        foreach ($this->azguardScopedRequests() as $request) {
            $ref = $this->azguardResolved($this->azguardScopeResolver(), $request);

            $this->assertFalse($ref->isGlobal(), 'A request that names a scope resolved to the global scope.');
            $this->assertStringNotContainsString(':', (string) $ref->type(), 'The type of a scope has no ":".');
            $decoded = IdentityCodec::decode(IdentityCodec::encode($ref));

            if (! $decoded instanceof AssignmentScopeRef) {
                $this->fail('The scope does not come back as a scope from the codec.');
            }
            $this->assertTrue($ref->equals($decoded), 'The scope changes when it goes through the codec.');
        }
    }

    #[Test]
    public function requestWithoutAScopeGivesNull(): void
    {
        $this->assertNull($this->azguardScopeResolver()->resolve($this->azguardUnscopedRequest()), 'A request that names no scope must resolve to null.');
    }

    private function azguardResolved(AssignmentScopeResolver $resolver, Request $request): AssignmentScopeRef
    {
        return $resolver->resolve($request) ?? $this->fail('A request that names a scope resolved to none.');
    }
}
