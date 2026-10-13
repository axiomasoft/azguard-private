<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use RuntimeException;

/** An evaluator independent of the scopes module: denies the listed scope ids, records every question. */
final class RecordingEvaluator implements AssignmentScopeEvaluator
{
    /** @var list<string> */
    public static array $denied = [];

    /** @var list<string> scope keys asked through allows() or allowsMany() */
    public static array $asked = [];

    public static int $batches = 0;

    public static bool $throws = false;

    public static function reset(): void
    {
        self::$denied = self::$asked = [];
        self::$batches = 0;
        self::$throws = false;
    }

    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void {}

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        self::$asked[] = $resolved->ref->key();

        if (self::$throws) {
            throw new RuntimeException('Recording evaluator is unavailable.');
        }

        return ! in_array($resolved->ref->id(), self::$denied, true);
    }

    public function allowsMany(array $checks): array
    {
        self::$batches++;

        return array_map(fn (array $check): bool => $this->allows($check[0], $check[1]), $checks);
    }
}
