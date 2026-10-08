<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Contracts\Subjects\SubjectResolver;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Throwable;

/**
 * For the authors of subject resolvers: what every resolver guarantees.
 *
 * Use the trait in a test case and implement `azguardSubjectResolver()` and `azguardSubjects()`.
 *
 * - resolving the same subject twice gives the same reference;
 * - the reference survives the codec and has no ":" in its type;
 * - the model of the reference resolves back to the same reference;
 * - a reference to a subject that does not exist has no model;
 * - what is not a subject is refused, not turned into a reference.
 *
 * @api
 */
trait SubjectResolverContractTests
{
    /** A new instance of the resolver under test. */
    abstract protected function azguardSubjectResolver(): SubjectResolver;

    /**
     * Subjects the resolver accepts, as the application passes them (models or references), that exist.
     *
     * @return list<mixed>
     */
    abstract protected function azguardSubjects(): array;

    /** A reference of the type of `$existing` to a subject that does not exist. */
    protected function azguardMissingSubject(SubjectRef $existing): SubjectRef
    {
        return SubjectRef::of($existing->type(), 'azguard-contract-missing');
    }

    #[Test]
    public function resolvingIsIdempotent(): void
    {
        $resolver = $this->azguardSubjectResolver();
        $this->assertNotSame([], $this->azguardSubjects(), 'Give the suite at least one subject.');

        foreach ($this->azguardSubjects() as $subject) {
            $first = $resolver->resolve($subject);

            $this->assertTrue($first->equals($resolver->resolve($subject)), 'The same subject resolves to different references.');
            $this->assertTrue($first->equals($this->azguardSubjectResolver()->resolve($subject)), 'Two instances of the resolver disagree.');
        }
    }

    #[Test]
    public function referenceSurvivesTheCodecAndHasNoColonInItsType(): void
    {
        $resolver = $this->azguardSubjectResolver();

        foreach ($this->azguardSubjects() as $subject) {
            $ref = $resolver->resolve($subject);

            $this->assertStringNotContainsString(':', $ref->type(), 'The type of a subject reference has no ":".');
            $this->assertSame($ref->type().':'.$ref->id(), $ref->key());
            $decoded = IdentityCodec::decode(IdentityCodec::encode($ref));

            if (! $decoded instanceof SubjectRef) {
                $this->fail('The reference does not come back as a subject reference from the codec.');
            }
            $this->assertTrue($ref->equals($decoded), 'The reference changes when it goes through the codec.');
        }
    }

    #[Test]
    public function modelOfTheReferenceResolvesBackToTheSameReference(): void
    {
        $resolver = $this->azguardSubjectResolver();

        foreach ($this->azguardSubjects() as $subject) {
            $ref = $resolver->resolve($subject);
            $model = $resolver->model($ref);

            $this->assertNotNull($model, 'The reference of an existing subject has no model.');
            $this->assertTrue($ref->equals($resolver->resolve($model)), 'The model of a reference resolves to another reference.');
        }
    }

    #[Test]
    public function missingSubjectHasNoModel(): void
    {
        $resolver = $this->azguardSubjectResolver();
        $subjects = $this->azguardSubjects();
        $missing = $this->azguardMissingSubject($resolver->resolve($subjects[0] ?? $this->fail('Give the suite at least one subject.')));

        $this->assertNull($resolver->model($missing), 'A reference to a subject that does not exist has a model.');
    }

    #[Test]
    public function whatIsNotASubjectIsRefused(): void
    {
        $resolver = $this->azguardSubjectResolver();

        foreach ([new stdClass, null, 3.5] as $value) {
            try {
                $ref = $resolver->resolve($value);
            } catch (Throwable) {
                continue;
            }
            $this->fail('A '.get_debug_type($value).' was resolved to the reference '.$ref->key().'.');
        }
        $this->assertTrue(true);
    }
}
