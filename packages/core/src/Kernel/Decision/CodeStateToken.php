<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Attributes\Api;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use JsonException;

/**
 * State evidence of a decision made from code alone: the panel build, without storage. The token is opaque: compare
 * two with `equals()`, keep one as text with `toString()` and read it back with `fromString()`.
 */
#[Api(since: '1.0')]
final readonly class CodeStateToken
{
    private const string PREFIX = 'ct1.';

    private function __construct(
        private string $panel,
        private string $buildId,
        private string $fingerprint,
    ) {}

    /**
     * A token of a panel build.
     *
     * @throws InvalidPanelIdException
     * @throws InvalidIdentityException when the build id or fingerprint is empty
     */
    public static function of(string $panel, string $buildId, string $fingerprint): self
    {
        PermissionGrammar::assertPanelId($panel);

        if ($buildId === '' || $fingerprint === '') {
            throw new InvalidIdentityException('Code state token needs a non-empty build id and fingerprint.');
        }

        return new self($panel, $buildId, $fingerprint);
    }

    /**
     * Reads a token from the text `toString()` gave.
     *
     * @throws InvalidIdentityException when the text is not a code state token of a known version or its values are invalid
     */
    public static function fromString(string $token): self
    {
        if (! str_starts_with($token, self::PREFIX)) {
            throw new InvalidIdentityException('A code state token starts with '.self::PREFIX.'.');
        }
        $json = base64_decode(strtr(substr($token, strlen(self::PREFIX)), '-_', '+/'), true);

        try {
            $fields = $json === false ? null : json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidIdentityException('Malformed code state token.', previous: $error);
        }

        if (! is_array($fields) || array_keys($fields) !== ['panel', 'build_id', 'fingerprint']
            || ! is_string($fields['panel']) || ! is_string($fields['build_id']) || ! is_string($fields['fingerprint'])) {
            throw new InvalidIdentityException('Malformed code state token.');
        }

        return self::of($fields['panel'], $fields['build_id'], $fields['fingerprint']);
    }

    /** The panel the build belongs to. */
    public function panel(): string
    {
        return $this->panel;
    }

    public function equals(self $other): bool
    {
        return $this->panel === $other->panel
            && $this->buildId === $other->buildId
            && $this->fingerprint === $other->fingerprint;
    }

    /** The versioned text form, `ct1.` and base64url JSON; `fromString()` reads it back. */
    public function toString(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(json_encode(['panel' => $this->panel, 'build_id' => $this->buildId, 'fingerprint' => $this->fingerprint],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @internal */
    public function buildId(): string
    {
        return $this->buildId;
    }

    /** @internal */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * @internal The `state` object of an event payload: exactly the keys journal rows have always carried.
     *
     * @return array{kind: 'code', panel: string, build_id: string, fingerprint: string}
     */
    public function payload(): array
    {
        return ['kind' => 'code', 'panel' => $this->panel, 'build_id' => $this->buildId, 'fingerprint' => $this->fingerprint];
    }

    /**
     * @internal Every recorded value by name, for explanations and traces.
     *
     * @return array{panel: string, buildId: string, fingerprint: string}
     */
    public function fields(): array
    {
        return ['panel' => $this->panel, 'buildId' => $this->buildId, 'fingerprint' => $this->fingerprint];
    }
}
