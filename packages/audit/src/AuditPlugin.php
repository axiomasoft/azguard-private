<?php

declare(strict_types=1);

namespace AzGuard\Audit;

use AzGuard\Audit\Extensions\AuditExtensionPlugin;
use AzGuard\Audit\Internal\Checks\AuditTableExists;
use AzGuard\Audit\Internal\JournalParticipant;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * The change journal of a panel: for each effect of a write operation a participant writes one row of the audit log in
 * the same transaction as the stored grants, with the `eventId` of the event published for that effect. A panel
 * without this plugin keeps no journal.
 *
 * `retentionDays` is how long a row is kept; the journal is cleaned by the maintenance command, never by this plugin.
 * Doctor checks that the journal table exists (`audit.table`).
 */
#[Api(since: '1.0')]
final class AuditPlugin implements Plugin
{
    private function __construct(private int $retentionDays)
    {
        self::assertRetention($retentionDays);
    }

    public static function make(int $retentionDays = AuditOptions::DEFAULT_RETENTION_DAYS): self
    {
        return new self($retentionDays);
    }

    public function extension(): AuditExtensionPlugin
    {
        return new AuditExtensionPlugin;
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
        $panel->participants([JournalParticipant::class])->options(new AuditOptions($this->retentionDays))->doctorChecks([AuditTableExists::class]);
    }

    private static function assertRetention(int $days): void
    {
        if ($days < 1) {
            throw new InvalidArgumentException('retentionDays must be at least 1.');
        }
    }
}
