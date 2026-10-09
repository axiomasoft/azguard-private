<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Filament\Guards\AdminMemberRole;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Resources\ResourceConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * What the fixture panels are built from. A test changes it and then boots the application again with `bootFilament()`;
 * `reset()` returns the defaults.
 */
final class FilamentFixture
{
    /** @var list<class-string|ResourceConfiguration> */
    public static array $resources;

    /** @var list<class-string> */
    public static array $pages;

    /** @var list<class-string> */
    public static array $widgets;

    public static FilamentDefinitions $definitions;

    /** Whether the plugin of the `admin` Filament panel enforces its permissions. */
    public static bool $enforce;

    public static ?string $authority;

    /** @var array{resources?: list<string>, pages?: list<string>, widgets?: list<string>} */
    public static array $exclude;

    /** @var list<string>|null abilities of the plugin of the `admin` Filament panel; null keeps the default */
    public static ?array $abilities;

    /** @var list<mixed> extra sources and enums of the `admin` guard panel */
    public static array $guardPermissions;

    /** @var list<PolicyBinding> */
    public static array $guardPolicies;

    /** @var list<class-string> roles of the `admin` guard panel */
    public static array $adminRoles;

    /** Ids of the users that the member roles of the fixture guard panels admit. */
    public static array $members;

    /** @var list<string> what the member roles give besides the entry: names and patterns */
    public static array $memberPermissions;

    /** @var array{roles?: bool, role_grants?: bool, permission_grants?: bool, permissions?: bool} editors of the package in the `admin` and `tenanted` Filament panels; none by default */
    public static array $editors;

    /** @var list<mixed> extra enums of the `seller` panel */
    public static array $sellerPermissions;

    /** @var list<mixed> policies of the `seller` panel */
    public static array $sellerPolicies;

    /** @var list<string>|null the AzGuard panels the `admin` Filament panel manages; null manages every panel */
    public static ?array $manages;

    /** Whether the `admin` guard panel stores role grants in `Fields\AdminRoleGrant` and `admin` and `teams` have the `Fields\ReasonPlugin` fields. */
    public static bool $grantFields;

    /** @var array<string, list<mixed>> form extensions of the plugin, by Filament panel `admin` or `tenanted` */
    public static array $formExtensions;

    /** Whether the `tenanted` Filament panel registers the plugin before it calls `tenant()`. */
    public static bool $tenantAfterPlugin;

    /** Where `azguard:catalog:cache` writes, with the build id; null leaves the configuration alone. */
    public static ?string $catalogCachePath;

    /** @var list<DoctorCheck|class-string<DoctorCheck>> doctor checks of the `admin` guard panel */
    public static array $doctorChecks;

    /** @var array{panels?: bool, doctor?: bool} the pages of the package in the `admin` Filament panel; none by default */
    public static array $packagePages;

    /** @var list<array{guard: ?string, filament: ?string}> */
    public static array $seen;

    /** Sets the defaults when nothing has set them yet. */
    public static function ensure(): void
    {
        if (! isset(self::$resources)) {
            self::reset();
        }
    }

    /**
     * Returns the defaults, and forgets the `optimize` tasks that the providers of Filament registered in static
     * properties of the base provider: they must not outlive the application that registered them, also when its boot
     * failed. Every application registers its own again when it boots.
     */
    public static function reset(): void
    {
        ServiceProvider::$optimizeCommands = [];
        ServiceProvider::$optimizeClearCommands = [];
        self::$resources = [OrderResource::class, ArchivedOrderResource::class];
        self::$pages = [ProbePage::class];
        self::$widgets = [];
        self::$definitions = FilamentDefinitions::Enums;
        self::$enforce = true;
        self::$authority = null;
        self::$abilities = null;
        self::$exclude = [];
        self::$guardPermissions = [];
        self::$guardPolicies = [];
        self::$adminRoles = [AdminMemberRole::class];
        self::$members = [1];
        self::$memberPermissions = ['pages.*'];
        self::$editors = [];
        self::$doctorChecks = [];
        self::$packagePages = [];
        self::$sellerPermissions = [];
        self::$sellerPolicies = [];
        self::$manages = ['admin', 'seller'];
        self::$tenantAfterPlugin = false;
        self::$grantFields = false;
        self::$formExtensions = [];
        self::$catalogCachePath = null;
        self::$seen = [];
    }
}
