<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when the fluent grant root is extended into a context
 * (`AzGuard::forUser($user)->inContext(...)`) while the optional
 * azguard/context package is not installed — the context branch has no
 * implementation to hand over to, and failing loudly beats silently
 * writing a panel-wide grant.
 *
 * Resolution: install azguard/context — its service provider binds the
 * context grant builder factory.
 */
final class ContextPackageNotInstalledException extends AzGuardException
{
    public function __construct()
    {
        parent::__construct(
            'Context grants require the azguard/context package: '
            .'AzGuard::forUser($user)->inContext(...) has no implementation to delegate to. '
            .'Install azguard/context (its service provider binds the context grant builder factory).',
        );
    }
}
