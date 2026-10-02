<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Kernel\Grammar\PermissionGrammar;

/**
 * State evidence of a decision read from storage: incarnation, panel version and generation.
 */
final readonly class StateToken
{
    private function __construct(
        public string $storageId,
        public string $panel,
        public string $incarnation,
        public int $version,
        public int $generation,
        public string $fingerprint,
    ) {}

    /**
     * @throws InvalidPanelIdException
     * @throws InvalidIdentityException when a string is empty or a counter is negative
     */
    public static function of(
        string $storageId,
        string $panel,
        string $incarnation,
        int $version,
        int $generation,
        string $fingerprint,
    ): self {
        PermissionGrammar::assertPanelId($panel);

        if ($storageId === '' || $incarnation === '' || $fingerprint === '') {
            throw new InvalidIdentityException('State token needs a non-empty storage id, incarnation and fingerprint.');
        }

        if ($version < 0 || $generation < 0) {
            throw new InvalidIdentityException('State token version and generation must not be negative.');
        }

        return new self($storageId, $panel, $incarnation, $version, $generation, $fingerprint);
    }

    public function equals(self $other): bool
    {
        return $this->storageId === $other->storageId
            && $this->panel === $other->panel
            && $this->incarnation === $other->incarnation
            && $this->version === $other->version
            && $this->generation === $other->generation
            && $this->fingerprint === $other->fingerprint;
    }
}
