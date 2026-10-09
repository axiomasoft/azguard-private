<?php

declare(strict_types=1);

namespace AzGuard\Plugins\Audit;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeEventPublisher;
use AzGuard\Changes\ChangeJournal;
use AzGuard\Changes\ChangeResult;
use AzGuard\Kernel\Support\Narrow;
use Closure;

/**
 * The change pipe of the audit plugin: it lets the change through unchanged and, once the writer has answered, adds one
 * journal row per effect. A repeat that changed nothing has no effect and so no row. The pipe returns the result it
 * received.
 *
 * @internal registered by `AuditPlugin`; the container builds it
 */
final readonly class RecordChange
{
    public function __construct(private ChangeJournal $journal, private ChangeEventPublisher $events) {}

    public function handle(Change $change, Closure $next): ChangeResult
    {
        $result = Narrow::instance($next($change), ChangeResult::class, 'the downstream change result');

        foreach ($this->events->events($change, $result) as $event) {
            $this->journal->append(ChangeJournal::row($event));
        }

        return $result;
    }
}
