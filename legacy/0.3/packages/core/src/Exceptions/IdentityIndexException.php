<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Named fail-closed verdict for exact identity indexes.
 *
 * Schema is left unchanged when this is thrown from a preflight.
 */
final class IdentityIndexException extends AzGuardException
{
    public const string MARIADB_UNSUPPORTED = 'mariadb-unsupported';

    public const string MYSQL_VERSION_UNSUPPORTED = 'mysql-version-unsupported';

    public const string MYSQL_ENGINE_UNSUPPORTED = 'mysql-engine-unsupported';

    public const string MYSQL_PAGE_SIZE_UNSUPPORTED = 'mysql-page-size-unsupported';

    public const string MYSQL_ROW_FORMAT_UNSUPPORTED = 'mysql-row-format-unsupported';

    public const string KEY_CAPACITY_UNSUPPORTED = 'identity-key-capacity-unsupported';

    public const string POSTGRES_VERSION_UNSUPPORTED = 'postgres-version-unsupported';

    public const string PANEL_ID_OVERLENGTH = 'panel-id-overlength';

    public const string DUPLICATE_CLASS_NAME = 'duplicate-class-name';

    public const string INVALID_IDENTIFIER = 'invalid-schema-identifier';

    public const string LEGACY_IDENTITY_COLLISION = 'legacy-identity-collision';

    public function __construct(public readonly string $verdict, string $message)
    {
        parent::__construct($message);
    }
}
