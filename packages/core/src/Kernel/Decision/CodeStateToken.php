<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use InvalidArgumentException;

/**
 * State evidence of a decision made from code alone: the panel build, without storage.
 */
final readonly class CodeStateToken
{
    private function __construct(
        public string $panel,
        public string $buildId,
        public string $fingerprint,
    ) {}

    /**
     * @throws InvalidPanelIdException
     * @throws InvalidArgumentException when the build id or fingerprint is empty
     */
    public static function of(string $panel, string $buildId, string $fingerprint): self
    {
        PermissionGrammar::assertPanelId($panel);

        if ($buildId === '' || $fingerprint === '') {
            throw new InvalidArgumentException('Code state token needs a non-empty build id and fingerprint.');
        }

        return new self($panel, $buildId, $fingerprint);
    }

    public function equals(self $other): bool
    {
        return $this->panel === $other->panel
            && $this->buildId === $other->buildId
            && $this->fingerprint === $other->fingerprint;
    }
}
