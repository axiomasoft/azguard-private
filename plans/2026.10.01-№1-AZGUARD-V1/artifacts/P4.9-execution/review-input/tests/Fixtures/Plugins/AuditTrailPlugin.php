<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use InvalidArgumentException;

/**
 * A plugin with one exact parameter; `make()` goes through the container, so the journal is whatever is bound.
 */
final class AuditTrailPlugin extends BasePlugin
{
    /** @var list<string> panels this copy registered on */
    private array $registeredOn = [];

    public function __construct(private int $retentionDays, private readonly AuditJournal $journal)
    {
        self::assertRetention($retentionDays);
    }

    public static function make(int $retentionDays = 90): self
    {
        return app()->makeWith(self::class, ['retentionDays' => $retentionDays]);
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

    public function journal(): AuditJournal
    {
        return $this->journal;
    }

    public function id(): string
    {
        return 'acme/audit-trail';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $this->registeredOn[] = $context->panelId();

        $panel->permissions([new ArraySource('audit-trail')])
            ->restrictions([AuditFreeze::class])
            ->changing([RecordChange::class]);
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        $this->journal->lines[] = [
            'panel' => $panel->id(),
            'context' => $context,
            'retention' => $this->retentionDays,
            'registered_on' => $this->registeredOn,
        ];
    }

    private static function assertRetention(int $days): void
    {
        if ($days < 1) {
            throw new InvalidArgumentException('retentionDays >= 1');
        }
    }
}
