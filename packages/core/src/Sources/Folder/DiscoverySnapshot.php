<?php

declare(strict_types=1);

namespace AzGuard\Sources\Folder;

/**
 * What one panel's folders contributed, as scalars, so a catalog cache can restore it without parsing.
 *
 * @phpstan-type Root array{path: string, namespace: ?string, origin: string}
 * @phpstan-type Names array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}
 * @phpstan-type DefinitionRow array{local: string, authority: string, label: ?string, group: ?string, description: ?string, case: array{enum: string, name: string}, resource_model: ?string, granted_to_all: bool}
 * @phpstan-type BindingRow array{permission: string|array{enum: string, name: string}, policy: class-string, method: string, roots: list<string>}
 * @phpstan-type Payload array{names: Names, roots: list<Root>, files: array<string, string>, definitions: list<DefinitionRow>, roles: list<class-string>, role_origins: array<class-string, list<string>>, scopes: list<class-string>, bindings: list<BindingRow>, granted_to_all: list<string>, sources: list<class-string>, enums: list<class-string>}
 */
final readonly class DiscoverySnapshot
{
    /**
     * @param  Names  $names
     * @param  list<Root>  $roots
     * @param  array<string, string>  $files  absolute path => sha256 of the file
     * @param  list<DefinitionRow>  $definitions
     * @param  list<class-string>  $roles
     * @param  list<class-string>  $scopes
     * @param  list<BindingRow>  $bindings
     * @param  list<string>  $grantedToAll
     * @param  list<class-string>  $sources  classes declared with #[AsSource]
     * @param  list<class-string>  $enums
     * @param  array<class-string, list<string>>  $roleOrigins
     */
    public function __construct(
        public array $names,
        public array $roots,
        public array $files,
        public array $definitions,
        public array $roles,
        public array $scopes,
        public array $bindings,
        public array $grantedToAll,
        public array $sources,
        public array $enums,
        public bool $fromCache,
        public array $roleOrigins = [],
    ) {}

    /**
     * Roots, folder names and file hashes. This is the part of the panel fingerprint that follows the folders.
     *
     * @return array{names: Names, roots: list<Root>, files: array<string, string>}
     */
    public function fingerprint(): array
    {
        return ['names' => $this->names, 'roots' => $this->roots, 'files' => $this->files];
    }

    /**
     * @return Payload
     */
    public function cache(): array
    {
        return [
            'names' => $this->names,
            'roots' => $this->roots,
            'files' => $this->files,
            'definitions' => $this->definitions,
            'roles' => $this->roles,
            'role_origins' => $this->roleOrigins,
            'scopes' => $this->scopes,
            'bindings' => $this->bindings,
            'granted_to_all' => $this->grantedToAll,
            'sources' => $this->sources,
            'enums' => $this->enums,
        ];
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromCache(array $payload): ?self
    {
        if (! is_array($payload['names'] ?? null) || ! is_array($payload['roots'] ?? null) || ! is_array($payload['files'] ?? null)
            || ! is_array($payload['definitions'] ?? null) || ! is_array($payload['roles'] ?? null) || ! is_array($payload['scopes'] ?? null)
            || ! is_array($payload['bindings'] ?? null) || ! is_array($payload['granted_to_all'] ?? null)
            || ! is_array($payload['sources'] ?? null) || ! is_array($payload['enums'] ?? null)
            || ! is_array($payload['role_origins'] ?? null)) {
            return null;
        }

        foreach ($payload['role_origins'] as $class => $origins) {
            if (! is_string($class) || ! is_array($origins) || $origins === [] || ! array_is_list($origins)
                || array_filter($origins, static fn (mixed $origin): bool => ! is_string($origin)) !== []) {
                return null;
            }
        }

        /** @var Payload $payload */
        return new self(
            names: $payload['names'],
            roots: $payload['roots'],
            files: $payload['files'],
            definitions: $payload['definitions'],
            roles: $payload['roles'],
            scopes: $payload['scopes'],
            bindings: $payload['bindings'],
            grantedToAll: $payload['granted_to_all'],
            sources: $payload['sources'],
            enums: $payload['enums'],
            fromCache: true,
            roleOrigins: $payload['role_origins'],
        );
    }
}
