<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Kernel\Grammar\PermissionGrammar;

/**
 * State evidence of a decision read from storage: incarnation, panel version, epoch and generation. The epoch moves
 * with changes that are not one subject's (a manual touch, a reset, a dynamic permission or a write whose subjects
 * are unknown); the version moves with every write of the panel.
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
        public int $epoch = 0,
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
        int $epoch = 0,
    ): self {
        PermissionGrammar::assertPanelId($panel);

        if ($storageId === '' || $incarnation === '' || $fingerprint === '') {
            throw new InvalidIdentityException('State token needs a non-empty storage id, incarnation and fingerprint.');
        }

        if ($version < 0 || $generation < 0 || $epoch < 0) {
            throw new InvalidIdentityException('State token version, generation and epoch must not be negative.');
        }

        return new self($storageId, $panel, $incarnation, $version, $generation, $fingerprint, $epoch);
    }

    public function equals(self $other): bool
    {
        return $this->storageId === $other->storageId
            && $this->panel === $other->panel
            && $this->incarnation === $other->incarnation
            && $this->version === $other->version
            && $this->generation === $other->generation
            && $this->fingerprint === $other->fingerprint
            && $this->epoch === $other->epoch;
    }
}
