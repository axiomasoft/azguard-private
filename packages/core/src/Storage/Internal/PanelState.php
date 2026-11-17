<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use AzGuard\Kernel\Internal\Narrow;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

#[Internal]
final readonly class PanelState
{
    public function __construct(
        public string $panel,
        public int $version,
        public string $incarnation,
        public DateTimeImmutable $updatedAt,
        public int $epoch = 0,
    ) {}

    /** @internal A row of `panel_state`: panel, version, incarnation, updated_at (UTC) and epoch. */
    public static function fromRow(object $row): self
    {
        $fields = get_object_vars($row);
        $value = static fn (string $column): mixed => $fields[$column] ?? null;

        return new self(Narrow::string($value('panel'), 'panel_state.panel'), Narrow::int($value('version'), 'panel_state.version'),
            Narrow::string($value('incarnation'), 'panel_state.incarnation'),
            new DateTimeImmutable(Narrow::string($value('updated_at'), 'panel_state.updated_at'), new DateTimeZone('UTC')),
            Narrow::int($value('epoch'), 'panel_state.epoch'));
    }
}
