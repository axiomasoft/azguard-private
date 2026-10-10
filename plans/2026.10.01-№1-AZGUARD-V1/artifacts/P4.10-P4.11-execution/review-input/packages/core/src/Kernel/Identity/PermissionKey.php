<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use JsonSerializable;
use Stringable;

/**
 * A catalog permission name: local `orders.view` inside a panel, full form `admin:orders.view`.
 */
final readonly class PermissionKey implements JsonSerializable, Stringable
{
    private function __construct(
        private string $panel,
        private string $local,
    ) {}

    /**
     * @throws InvalidPanelIdException
     * @throws InvalidPermissionKeyException
     */
    public static function of(string $panel, string $local): self
    {
        PermissionGrammar::assertPanelId($panel);
        PermissionGrammar::assertLocalKey($local);

        return new self($panel, $local);
    }

    /**
     * @throws InvalidPanelIdException
     * @throws InvalidPermissionKeyException
     */
    public static function parse(string $full): self
    {
        [$panel, $local] = PermissionGrammar::splitFull($full);

        return new self($panel, $local);
    }

    public function panel(): string
    {
        return $this->panel;
    }

    public function local(): string
    {
        return $this->local;
    }

    public function full(): string
    {
        return $this->panel.':'.$this->local;
    }

    public function equals(self $other): bool
    {
        return $this->panel === $other->panel && $this->local === $other->local;
    }

    public function __toString(): string
    {
        return $this->full();
    }

    public function jsonSerialize(): string
    {
        return $this->full();
    }
}
