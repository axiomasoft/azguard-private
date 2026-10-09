<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidIdentityException;
use JsonException;
use ReflectionReference;

/**
 * One identity codec for SQL, cache and events: canonical ids, type aliases, tagged references and composite keys.
 *
 * Composite keys are JSON arrays led by the codec version, never strings glued without boundaries. A reference is
 * encoded with its kind, so a subject, a tenant and an assignment scope with the same alias and id stay distinct.
 */
final readonly class IdentityCodec
{
    public const int VERSION = 1;

    public const string DEFAULT_ORIGIN = 'manual';

    public const int MAX_ID_LENGTH = 64;

    private const string TYPE_ALIAS_PATTERN = '/\A[a-z0-9][a-z0-9_.-]{0,127}\z/';

    private const string SOURCE_LABEL_PATTERN = '/\A[a-z0-9][a-z0-9_.:-]{0,127}\z/';

    private const string ID_PATTERN = '/\A[\x21-\x7E]{1,64}\z/';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct() {}

    /**
     * @param  class-string<InvalidIdentityException>  $exception
     *
     * @throws InvalidIdentityException
     */
    public static function assertTypeAlias(string $type, string $exception = InvalidIdentityException::class): void
    {
        if (preg_match(self::TYPE_ALIAS_PATTERN, $type) !== 1) {
            throw new $exception(self::message('type alias', $type, 'must match '.self::TYPE_ALIAS_PATTERN));
        }
    }

    /**
     * Validates a grant source id or an origin label (`folder`, `relation:project`, `manual`).
     *
     * @throws InvalidIdentityException
     */
    public static function assertSourceLabel(string $label): void
    {
        if (preg_match(self::SOURCE_LABEL_PATTERN, $label) !== 1) {
            throw new InvalidIdentityException(self::message('source label', $label, 'must match '.self::SOURCE_LABEL_PATTERN));
        }
    }

    /**
     * An int becomes its decimal string; a string is kept byte for byte (`'007'` is not `'7'`).
     *
     * @param  class-string<InvalidIdentityException>  $exception
     *
     * @throws InvalidIdentityException
     */
    public static function canonicalId(int|string $id, string $exception = InvalidIdentityException::class): string
    {
        $canonical = (string) $id;

        if (preg_match(self::ID_PATTERN, $canonical) !== 1) {
            throw new $exception(self::message(
                'id',
                $canonical,
                'must be 1-'.self::MAX_ID_LENGTH.' bytes of printable ASCII without whitespace',
            ));
        }

        return $canonical;
    }

    /**
     * @return list<string|null>
     *
     * @throws InvalidIdentityException when the object is not an identity reference
     */
    public static function encode(object $ref): array
    {
        return match (true) {
            $ref instanceof PermissionKey => ['permission', $ref->panel(), $ref->local()],
            $ref instanceof PermissionPattern => ['pattern', $ref->panel(), $ref->local()],
            $ref instanceof RoleKey => ['role', $ref->panel(), $ref->key()],
            $ref instanceof SubjectRef => ['subject', $ref->type(), $ref->id()],
            $ref instanceof TenantRef => ['tenant', $ref->type(), $ref->id()],
            $ref instanceof AssignmentScopeRef => ['context', $ref->type(), $ref->id()],
            $ref instanceof AccessScope => [
                'scope', $ref->tenant->type(), $ref->tenant->id(), $ref->context->type(), $ref->context->id(),
            ],
            $ref instanceof ActorRef => ['actor', $ref->type, $ref->id, $ref->reason],
            default => throw new InvalidIdentityException('Cannot encode '.$ref::class.' as an identity reference.'),
        };
    }

    /**
     * Inverse of {@see encode()}; every part passes the same validation as the reference factory.
     *
     * @param  array<mixed>  $encoded
     *
     * @throws InvalidIdentityException
     */
    public static function decode(array $encoded): PermissionKey|PermissionPattern|RoleKey|SubjectRef|TenantRef|AssignmentScopeRef|AccessScope|ActorRef
    {
        $parts = array_values($encoded);
        $shape = array_map(get_debug_type(...), $parts);

        $ref = match ($shape) {
            ['string', 'string', 'string'] => match ($parts[0]) {
                'permission' => PermissionKey::of($parts[1], $parts[2]),
                'pattern' => PermissionPattern::of($parts[1], $parts[2]),
                'role' => RoleKey::of($parts[1], $parts[2]),
                'subject' => SubjectRef::of($parts[1], $parts[2]),
                'tenant' => TenantRef::of($parts[1], $parts[2]),
                'context' => AssignmentScopeRef::of($parts[1], $parts[2]),
                default => null,
            },
            ['string', 'null', 'null'] => match ($parts[0]) {
                'tenant' => TenantRef::global(),
                'context' => AssignmentScopeRef::global(),
                default => null,
            },
            default => null,
        };

        return $ref ?? self::decodeComposite($parts) ?? throw new InvalidIdentityException(
            'Cannot decode '.self::describe($parts).' as an identity reference.',
        );
    }

    /**
     * Unambiguous composite key: a JSON array led by the codec version; references are encoded with their kind.
     *
     * @param  list<mixed>  $parts
     *
     * @throws InvalidIdentityException when a part is not a string, int, bool, null, list or identity reference,
     *                                  or a string is not valid UTF-8
     */
    public static function compose(array $parts): string
    {
        try {
            return json_encode([self::VERSION, ...self::normalize($parts)], self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new InvalidIdentityException('Cannot compose an identity key: '.$e->getMessage().'.', 0, $e);
        }
    }

    /**
     * @param  list<mixed>  $parts
     *
     * @throws InvalidIdentityException
     */
    public static function digest(array $parts): string
    {
        return hash('sha256', self::compose($parts));
    }

    /**
     * @param  list<mixed>  $parts
     */
    private static function decodeComposite(array $parts): AccessScope|ActorRef|null
    {
        if ($parts !== [] && $parts[0] === 'scope' && count($parts) === 5) {
            $tenant = self::decode(['tenant', $parts[1], $parts[2]]);
            $context = self::decode(['context', $parts[3], $parts[4]]);

            return $tenant instanceof TenantRef && $context instanceof AssignmentScopeRef
                ? AccessScope::in($tenant, $context)
                : null;
        }

        if ($parts === [] || $parts[0] !== 'actor' || count($parts) !== 4 || ! is_string($parts[1])
            || (! is_string($parts[2]) && $parts[2] !== null) || (! is_string($parts[3]) && $parts[3] !== null)) {
            return null;
        }

        if ($parts[1] === ActorRef::SYSTEM_TYPE && $parts[2] === null) {
            return ActorRef::system($parts[3]);
        }

        return $parts[2] === null ? null : ActorRef::of($parts[1], $parts[2], $parts[3]);
    }

    /**
     * @param  array<mixed>  $parts
     * @param  array<string, true>  $references
     * @return list<mixed>
     */
    private static function normalize(array $parts, array $references = []): array
    {
        if (! array_is_list($parts)) {
            throw new InvalidIdentityException('Cannot compose an array with keys into an identity key; use a list.');
        }

        $normalized = [];

        foreach ($parts as $index => $part) {
            $nestedReferences = $references;
            $reference = is_array($part) ? ReflectionReference::fromArrayElement($parts, $index)?->getId() : null;

            if ($reference !== null) {
                if (isset($references[$reference])) {
                    throw new InvalidIdentityException('Cannot compose a recursive array into an identity key.');
                }

                $nestedReferences[$reference] = true;
            }

            $normalized[] = match (true) {
                is_object($part) => self::encode($part),
                is_array($part) => self::normalize($part, $nestedReferences),
                is_string($part), is_int($part), is_bool($part), $part === null => $part,
                default => throw new InvalidIdentityException('Cannot compose a '.get_debug_type($part).' into an identity key.'),
            };
        }

        return $normalized;
    }

    private static function message(string $subject, string $value, string $rule): string
    {
        return 'Invalid '.$subject.' '.self::describe($value).': '.$rule.'.';
    }

    private static function describe(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
