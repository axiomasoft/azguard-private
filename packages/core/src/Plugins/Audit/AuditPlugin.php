<?php

declare(strict_types=1);

namespace AzGuard\Plugins\Audit;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRecipe;
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

    /**
     * The retention in days of the audit plugin the recipe attaches to its panel, in the order provider, plugins,
     * `configure`; null when the panel has none. A plugin given by class name keeps the default.
     *
     * @internal read by `azguard:audit:prune`
     */
    public static function retentionIn(PanelRecipe $recipe): ?int
    {
        foreach ([PanelRecipe::PROVIDER, PanelRecipe::PLUGIN, PanelRecipe::CONFIGURE] as $layer) {
            if ($layer === PanelRecipe::CONFIGURE && in_array('azguard/audit', $recipe->withoutPlugins(), true)) {
                continue;
            }
            foreach ($recipe->plugins($layer) as $plugin) {
                if ($plugin instanceof self) {
                    return $plugin->retentionDays;
                }

                if ($plugin === self::class) {
                    return self::make()->retentionDays;
                }
            }
        }

        return null;
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
