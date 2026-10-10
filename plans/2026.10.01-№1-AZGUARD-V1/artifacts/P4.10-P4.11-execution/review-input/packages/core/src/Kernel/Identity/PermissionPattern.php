<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Grammar\PermissionGrammar;

/**
 * A granted pattern inside a panel: an exact name, `orders.*` (one segment) or `orders.**` (one or more).
 */
final readonly class PermissionPattern
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
        PermissionGrammar::assertPattern($local);

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

    public function covers(PermissionKey $key): bool
    {
        return $this->panel === $key->panel() && PatternMatcher::covers($this->local, $key->local());
    }

    public function isExact(): bool
    {
        return PermissionGrammar::isLocalKey($this->local);
    }

    public function equals(self $other): bool
    {
        return $this->panel === $other->panel && $this->local === $other->local;
    }
}
