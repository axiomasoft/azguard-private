<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use Closure;

/**
 * A check whose findings the test supplies; it records the panel each run received.
 */
final class ProbeCheck implements DoctorCheck
{
    /** @var list<?string> */
    public static array $panels = [];

    /**
     * @param  Closure(DoctorContext): iterable<mixed>  $run
     */
    public function __construct(private string $key, private Closure $run) {}

    public function key(): string
    {
        return $this->key;
    }

    public function run(DoctorContext $context): iterable
    {
        self::$panels[] = $context->panel()?->id();

        return ($this->run)($context);
    }

    public static function finding(string $key, string $message = 'Probe found a problem.'): self
    {
        return new self($key, static fn (): array => [DoctorFinding::error($key, $message)]);
    }
}
