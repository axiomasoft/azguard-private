<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Kernel\Grammar\PermissionGrammar;

/**
 * A role key inside a panel; full form `admin:manager`.
 */
final readonly class RoleKey
{
    private function __construct(
        private string $panel,
        private string $key,
    ) {}

    /**
     * @throws InvalidPanelIdException
     * @throws InvalidRoleKeyException
     */
    public static function of(string $panel, string $key): self
    {
        PermissionGrammar::assertPanelId($panel);
        PermissionGrammar::assertRoleKey($key);

        return new self($panel, $key);
    }

    /**
     * @throws InvalidPanelIdException
     * @throws InvalidRoleKeyException
     */
    public static function parse(string $full): self
    {
        $parts = explode(':', $full);

        if (count($parts) !== 2) {
            throw new InvalidRoleKeyException(
                'Invalid full role key '.json_encode($full, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                .': must contain exactly one ":" between panel id and role key.',
            );
        }

        return self::of($parts[0], $parts[1]);
    }

    public function panel(): string
    {
        return $this->panel;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function full(): string
    {
        return $this->panel.':'.$this->key;
    }

    public function equals(self $other): bool
    {
        return $this->panel === $other->panel && $this->key === $other->key;
    }
}
