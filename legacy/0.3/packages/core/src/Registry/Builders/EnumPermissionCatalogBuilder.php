<?php

declare(strict_types=1);

namespace AzGuard\Registry\Builders;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Registry\Definitions\EnumPermissionDefinition;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Override;
use ReflectionClass;
use ReflectionEnum;
use UnitEnum;

/**
 * Builds permission catalog entries from backed enum classes.
 *
 * When $enumClasses are provided explicitly (via Panel::permissionEnums()),
 * those classes are used directly. Otherwise, falls back to filesystem
 * discovery of *Permission.php files under the panel's basePath.
 */
final readonly class EnumPermissionCatalogBuilder implements PermissionCatalogBuilder
{
    private AzGuardManagerInterface $manager;

    /**
     * @param  string|null  $panelId  When set, this builder only handles this panel.
     * @param  list<class-string<UnitEnum>>  $enumClasses  Explicit enum class list (optional).
     * @param  AzGuardManagerInterface|null  $manager  Injected manager (parity with GrantSource DI).
     *                                                 Falls back to the container when this builder
     *                                                 is constructed directly via `new` (PanelProvider).
     */
    public function __construct(
        private ?string $panelId = null,
        private array $enumClasses = [],
        ?AzGuardManagerInterface $manager = null,
    ) {
        $this->manager = $manager ?? app(AzGuardManagerInterface::class);
    }

    #[Override]
    public function build(string $panelId): array
    {
        $panel = $this->manager->panel($panelId);

        if ($panel === null) {
            return [];
        }

        $classes = match (true) {
            $this->enumClasses !== [] => $this->enumClasses,
            $this->panelId !== null => [],
            default => $this->discoverEnumClasses($panel->getBasePath(), $panel->getNamespace()),
        };

        $definitions = [];

        foreach ($classes as $enumClass) {
            if (! class_exists($enumClass)) {
                Log::warning("AzGuard: enum class [{$enumClass}] does not exist, skipping catalog entry.", [
                    'panel' => $panelId,
                ]);

                continue;
            }

            $reflection = new ReflectionEnum($enumClass);

            foreach ($reflection->getCases() as $case) {
                /** @var UnitEnum $enumCase */
                $enumCase = $case->getValue();
                $resolvedKey = $panel->resolvePermission($enumCase);

                $definitions[] = EnumPermissionDefinition::fromCase(
                    case: $enumCase,
                    panelId: $panelId,
                    resolvedKey: $resolvedKey,
                );
            }
        }

        return $definitions;
    }

    #[Override]
    public function supports(string $panelId): bool
    {
        if ($this->panelId !== null) {
            return $this->panelId === $panelId;
        }

        return $this->manager->panel($panelId) !== null;
    }

    /**
     * @return list<class-string<UnitEnum>>
     */
    private function discoverEnumClasses(string $basePath, string $baseNamespace): array
    {
        if ($basePath === '' || $baseNamespace === '' || ! is_dir($basePath)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles($basePath) as $file) {
            if (! str_ends_with($file->getFilename(), 'Permission.php')) {
                continue;
            }

            $relative = $file->getRelativePathname();
            $class = $baseNamespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class) && (new ReflectionClass($class))->isEnum()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
