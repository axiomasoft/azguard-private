<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Attributes\Api;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use JsonException;

/**
 * State evidence of a decision read from storage (its observed state). The token is opaque: compare two with
 * `equals()`, keep one as text with `toString()` and read it back with `fromString()`. What it records (storage,
 * incarnation, counters, the revision of one subject) is the engine's and not a promise.
 */
#[Api(since: '1.0')]
final readonly class StateToken
{
    private const string PREFIX = 'st1.';

    private function __construct(
        private string $storageId,
        private string $panel,
        private string $incarnation,
        private int $version,
        private int $generation,
        private string $fingerprint,
        private int $epoch = 0,
        private ?int $subjectRevision = null,
    ) {}

    /**
     * A token of what a source read: a `FencesReads` source builds the one it returns from `state()` here.
     *
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
        ?int $subjectRevision = null,
    ): self {
        PermissionGrammar::assertPanelId($panel);

        if ($storageId === '' || $incarnation === '' || $fingerprint === '') {
            throw new InvalidIdentityException('State token needs a non-empty storage id, incarnation and fingerprint.');
        }

        if ($version < 0 || $generation < 0 || $epoch < 0 || ($subjectRevision ?? 0) < 0) {
            throw new InvalidIdentityException('State token version, generation, epoch and subject revision must not be negative.');
        }

        return new self($storageId, $panel, $incarnation, $version, $generation, $fingerprint, $epoch, $subjectRevision);
    }

    /**
     * Reads a token from the text `toString()` gave.
     *
     * @throws InvalidIdentityException when the text is not a state token of a known version or its values are invalid
     */
    public static function fromString(string $token): self
    {
        if (! str_starts_with($token, self::PREFIX)) {
            throw new InvalidIdentityException('A state token starts with '.self::PREFIX.'.');
        }
        $fields = self::decode(substr($token, strlen(self::PREFIX)));
        $keys = ['storage', 'panel', 'incarnation', 'version', 'generation', 'fingerprint', 'epoch', 'subject_revision'];

        if (array_keys($fields) !== $keys || ! is_string($fields['storage']) || ! is_string($fields['panel']) || ! is_string($fields['incarnation'])
            || ! is_int($fields['version']) || ! is_int($fields['generation']) || ! is_string($fields['fingerprint']) || ! is_int($fields['epoch'])
            || ($fields['subject_revision'] !== null && ! is_int($fields['subject_revision']))) {
            throw new InvalidIdentityException('Malformed state token.');
        }

        return self::of($fields['storage'], $fields['panel'], $fields['incarnation'], $fields['version'], $fields['generation'],
            $fields['fingerprint'], $fields['epoch'], $fields['subject_revision']);
    }

    /** The panel the state belongs to. */
    public function panel(): string
    {
        return $this->panel;
    }

    public function equals(self $other): bool
    {
        return $this->storageId === $other->storageId
            && $this->panel === $other->panel
            && $this->incarnation === $other->incarnation
            && $this->version === $other->version
            && $this->generation === $other->generation
            && $this->fingerprint === $other->fingerprint
            && $this->epoch === $other->epoch
            && $this->subjectRevision === $other->subjectRevision;
    }

    /** The versioned text form, `st1.` and base64url JSON; `fromString()` reads it back. */
    public function toString(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(json_encode([
            'storage' => $this->storageId, 'panel' => $this->panel, 'incarnation' => $this->incarnation, 'version' => $this->version,
            'generation' => $this->generation, 'fingerprint' => $this->fingerprint, 'epoch' => $this->epoch, 'subject_revision' => $this->subjectRevision,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @internal The state of the panel this token was read at, without the revision of one subject. */
    public function panelState(): self
    {
        return $this->subjectRevision === null ? $this
            : new self($this->storageId, $this->panel, $this->incarnation, $this->version, $this->generation, $this->fingerprint, $this->epoch);
    }

    /** @internal */
    public function storageId(): string
    {
        return $this->storageId;
    }

    /** @internal */
    public function incarnation(): string
    {
        return $this->incarnation;
    }

    /** @internal */
    public function version(): int
    {
        return $this->version;
    }

    /** @internal */
    public function generation(): int
    {
        return $this->generation;
    }

    /** @internal */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @internal */
    public function epoch(): int
    {
        return $this->epoch;
    }

    /** @internal */
    public function subjectRevision(): ?int
    {
        return $this->subjectRevision;
    }

    /**
     * @internal The `state` object of an event payload: exactly the keys journal rows have always carried.
     *
     * @return array{kind: 'state', storage: string, panel: string, incarnation: string, version: int, generation: int, fingerprint: string}
     */
    public function payload(): array
    {
        return ['kind' => 'state', 'storage' => $this->storageId, 'panel' => $this->panel, 'incarnation' => $this->incarnation,
            'version' => $this->version, 'generation' => $this->generation, 'fingerprint' => $this->fingerprint];
    }

    /**
     * @internal Every recorded value by name, for explanations and traces.
     *
     * @return array{storageId: string, panel: string, incarnation: string, version: int, generation: int, fingerprint: string, epoch: int, subjectRevision: ?int}
     */
    public function fields(): array
    {
        return ['storageId' => $this->storageId, 'panel' => $this->panel, 'incarnation' => $this->incarnation, 'version' => $this->version,
            'generation' => $this->generation, 'fingerprint' => $this->fingerprint, 'epoch' => $this->epoch, 'subjectRevision' => $this->subjectRevision];
    }

    /**
     * @return array<mixed>
     *
     * @throws InvalidIdentityException
     */
    private static function decode(string $body): array
    {
        $json = base64_decode(strtr($body, '-_', '+/'), true);

        try {
            $fields = $json === false ? null : json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidIdentityException('Malformed state token.', previous: $error);
        }

        return is_array($fields) ? $fields : throw new InvalidIdentityException('Malformed state token.');
    }
}
