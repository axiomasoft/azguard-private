<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/** Records the order of pipes and what each saw; resolved by the container from its class name. */
final class RecordingPipe
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly string $name = 'class') {}

    public function handle(Change $change, Closure $next): ChangeResult
    {
        self::$log[] = $this->name.':'.$change->type->value;

        return $next($change);
    }
}
