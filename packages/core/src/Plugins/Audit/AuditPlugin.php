<?php

declare(strict_types=1);

namespace AzGuard\Plugins\Audit;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use InvalidArgumentException;

/**
 * The change journal of a panel: after each effective change a pipe writes one row of the audit log in the same
 * transaction as the stored grants, with the `eventId` of the event published for that change. A panel without this
 * plugin keeps no journal.
 *
 * `retentionDays` is how long a row is kept; the journal is cleaned by the maintenance command, never by this plugin.
 * Doctor checks that the journal table exists (`audit.table`).
 *
 * @api
 */
final class AuditPlugin extends BasePlugin
{
    private function __construct(private int $retentionDays)
    {
        self::assertRetention($retentionDays);
    }

    public static function make(int $retentionDays = 90): self
    {
        return new self($retentionDays);
    }

    public function retention(int $days): static
    {
        self::assertRetention($days);

        $copy = clone $this;
        $copy->retentionDays = $days;

        return $copy;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function id(): string
    {
        return 'azguard/audit';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->changing([RecordChange::class])->doctorChecks([AuditTableExists::class]);
    }

    private static function assertRetention(int $days): void
    {
        if ($days < 1) {
            throw new InvalidArgumentException('retentionDays must be at least 1.');
        }
    }
}
