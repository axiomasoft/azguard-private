# Perplexity capture — P1 — 2026-09-23

**Tool:** `perplexity.search` (ordinary search)
**Capture kind:** response text returned to the design session
**Normative status:** non-normative retrieval lead; load-bearing claims require direct primary-source or repository verification.

## Query

Use only primary sources and provide a concise evidence ledger with URLs, no quotations. For authorization caches in PHP/Laravel: PSR-16 portable cache key restrictions, TTL semantics including early eviction, why backend TTL does not enforce an application absolute authorization expiry, and safe composition of typed subject identity (morph type plus canonical identifier). Explicitly mark what no primary source standardizes.

## Response

Для authorization-кэша TTL бэкенда — это только верхняя граница пригодности записи для данного хранилища, а не самостоятельная гарантия того, что решение об авторизации не будет использовано после бизнес-дедлайна. Абсолютный срок действия авторизации должен храниться в payload и проверяться приложением при каждом cache hit.

## Рекомендуемая модель

Кэшируйте не «вечный allow», а решение с явным бизнес-истечением:
```
final readonly class AuthorizationCacheEntry { public function __construct( public bool $allowed, public DateTimeImmutable $authorizationExpiresAt, public int $policyVersion, ) {} }
```
На чтении:
```
$entry = $cache->get($key); if ( !$entry instanceof AuthorizationCacheEntry || $clock->now() >= $entry->authorizationExpiresAt ) { // Пересчитать авторизацию; при необходимости удалить просроченную запись. }
```
TTL, передаваемый в set() / remember(), должен быть не больше остатка до authorizationExpiresAt — обычно с небольшим запасом на часы и транспорт. Но проверка authorizationExpiresAt остаётся обязательной.

## Evidence ledger


## Безопасная композиция identity

Разделяйте три уровня:
- Семантическая identity: стабильная типизированная параsubject = (morph_alias, canonical_identifier).
- Каноническое представление: детерминированная сериализация компонентов с однозначными границами. Не используйте простую конкатенацию вида "{$type}:{$id}" как переносимый PSR-16 key: : запрещён PSR-16, а правила канонизации ID обычно доменные.
- Физический cache key: короткий ключ из PSR-16-safe alphabet, производный от полной семантической identity и остальных auth dimensions.
Например:
```
use Illuminate\Database\Eloquent\Model; use Psr\SimpleCache\CacheInterface; function authorizationKey( Model $subject, string $ability, string $resourceFingerprint, int $policyVersion, ): string { $subjectType = $subject->getMorphClass(); // Это доменная функция: UUID — normalized lowercase; // integer PK — canonical decimal, без ведущих нулей; // composite/natural key — собственная строгая canonicalization. $subjectId = canonicalSubjectId($subject->getKey()); $material = json_encode([ 'schema' => 1, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'ability' => $ability, 'resource' => $resourceFingerprint, 'policy_version' => $policyVersion, ], JSON_THROW_ON_ERROR); // Hex digest использует только 0-9,a-f; точки допустимы PSR-16. return 'authz.' . hash('sha256', $material); }
```
Такой ключ:
- не раскрывает subject ID, ability или resource attributes в backend keyspace;
- не зависит от ограничений конкретного Redis/Memcached/database driver;
- укладывается в PSR-16-safe charset;
- имеет предсказуемую длину: authz. + 64 hex-символа, то есть 70 символов — не переносимо при строгом лимите PSR-16 в 64 символа.
Поэтому для строгой PSR-16 переносимости сократите digest, сохранив оценку collision risk, например до 48 hex-символов:
```
return 'az.' . substr(hash('sha256', $material), 0, 48);
```
Это даёт 51 символ, допустимые a-z, 0-9, . и запас до лимита 64. Для особо чувствительных случаев можно хранить внутри value полный canonical material или его полный digest и считать несовпадение cache miss, а не использовать запись.

## TTL и абсолютное истечение

Безопасная схема:
```
$now = $clock->now(); $authorizationExpiresAt = min( $tokenExpiresAt, $grantExpiresAt, $policySnapshotExpiresAt, ); if ($now >= $authorizationExpiresAt) { return AuthorizationDecision::denyExpired(); } $ttlSeconds = max( 1, $authorizationExpiresAt->getTimestamp() - $now->getTimestamp() - 1, ); $entry = new AuthorizationCacheEntry( allowed: $decision->allowed, authorizationExpiresAt: $authorizationExpiresAt, policyVersion: $policyVersion, ); $cache->set($key, $entry, $ttlSeconds);
```
И на cache hit:
```
$entry = $cache->get($key); if ( $entry instanceof AuthorizationCacheEntry && $entry->policyVersion === $policyVersion && $clock->now() < $entry->authorizationExpiresAt ) { return AuthorizationDecision::fromCached($entry->allowed); } return $authorizer->decideAndCache(...);
```
Отдельно: если у вас есть немедленная revocation requirement, одних TTL и authorizationExpiresAt недостаточно. Нужен version/revocation dimension в key или value, например membership_version, role_assignment_version, policy_version, session_version, либо централизованный deny/revocation check перед использованием cached allow.

## Что не стандартизировано

Ни PSR-16, ни Laravel cache documentation не стандартизируют следующее:
- Единую схему authorization cache key.
- Формат или канонизацию subject ID: integer, UUID, ULID, natural/composite key.
- Использование Laravel morph alias как security identity namespace.
- Требование включать tenant, guard, audience, ability, resource scope, policy version, session version или revocation epoch.
- Правила сериализации cache payload и совместимость его классов между deploy-версиями.
- Защиту от cache-key collision, key enumeration или утечки identity через keyspace.
- Clock authority, допустимый clock skew и безопасный TTL safety margin.
- Семантику allow/deny caching, включая допустимость stale allow.
- Немедленную инвалидизацию при изменении ролей, membership, tenancy, ownership, token/session state или политики.
- Гарантию атомарности «проверить revocation → использовать cached allow → выполнить действие».
Именно поэтому portable design следует строить вокруг явного application-level authorization expiry, версионируемых входов решения и строгой typed identity; PSR-16/Laravel TTL использовать только как механизм хранения и снижения нагрузки, но не как доверенную границу безопасности.

Sources:
1. [PSR-16](https://www.php-fig.org/psr/psr-16/)
2. [Laravel Cache](https://laravel.com/docs/13.x/cache)
3. [laravel](https://laravel.com/docs/13.x/cache.md)
4. [Laravel Eloquent relationships](https://laravel.com/docs/eloquent-relationships)
5. [laravel](https://laravel.com/framework/docs/eloquent-relationships)

