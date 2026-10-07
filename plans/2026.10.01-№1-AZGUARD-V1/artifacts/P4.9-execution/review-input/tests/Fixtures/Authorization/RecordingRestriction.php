<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;
use RuntimeException;

final class RecordingRestriction implements Restriction
{
    public int $checks = 0;

    public function __construct(public string $name = 'recording', public bool $deny = false, public bool $exempt = false, public ?string $error = null, public bool $applicable = true) {}

    public function key(): string
    {
        if ($this->error === 'key') {
            throw new RuntimeException('key');
        }

        return $this->name;
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        if ($this->error === 'applies') {
            throw new RuntimeException('applies');
        }

        return $this->applicable;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        $this->checks++;

        if ($this->error === 'check') {
            throw new RuntimeException('check');
        }

        return $this->deny ? RestrictionResult::deny('blocked') : RestrictionResult::pass();
    }

    public function exemptsSuperAdmin(): bool
    {
        if ($this->error === 'exemption') {
            throw new RuntimeException('exemption');
        }

        return $this->exempt;
    }
}
