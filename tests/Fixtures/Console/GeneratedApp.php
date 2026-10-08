<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Console;

use AzGuard\Configuration\AzGuardConfig;
use FilesystemIterator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A throwaway application directory for the generators: its own base path, a unique namespace so that classes of two
 * tests never clash, and an autoloader for what the generators write there.
 */
final class GeneratedApp
{
    private static int $count = 0;

    public readonly string $root;

    public readonly string $namespace;

    private string $originalBasePath = '';

    /** @var (callable(string): void)|null */
    private $loader;

    private function __construct(private readonly Application $app)
    {
        $this->namespace = 'AzGuardGenerated\\G'.getmypid().'x'.(++self::$count);
        $this->root = sys_get_temp_dir().'/azguard-generated-'.bin2hex(random_bytes(6));
        (new Filesystem)->ensureDirectoryExists($this->root.'/app/Guards');
    }

    /** Points the application at the directory and the configuration at the namespace; undo with `release()`. */
    public static function in(Application $app, bool $withConfig = false): self
    {
        $generated = new self($app);
        $generated->originalBasePath = $app->basePath();
        $app->setBasePath($generated->root);
        $app->make('config')->set('azguard.scaffold', ['namespace' => $generated->namespace.'\\Guards', 'path' => 'app/Guards']);
        $app->forgetInstance(AzGuardConfig::class);

        $prefix = $generated->namespace.'\\';
        $generated->loader = static function (string $class) use ($prefix, $generated): void {
            if (str_starts_with($class, $prefix)) {
                $file = $generated->root.'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        };
        spl_autoload_register($generated->loader);

        if ($withConfig) {
            $generated->publishConfig();
        }

        return $generated;
    }

    public function release(): void
    {
        if ($this->loader !== null) {
            spl_autoload_unregister($this->loader);
            $this->loader = null;
        }
        $this->app->setBasePath($this->originalBasePath);
        $this->app->forgetInstance(AzGuardConfig::class);
        (new Filesystem)->deleteDirectory($this->root);
    }

    /** The configuration file of the package as `vendor:publish` leaves it. */
    public function publishConfig(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->root.'/config');
        copy(__DIR__.'/../../../packages/core/config/azguard.php', $this->root.'/config/azguard.php');
    }

    /** A class under `app/Guards`, such as `Orders\OrdersGuardPanelProvider`. */
    public function class(string $relative): string
    {
        return $this->namespace.'\\Guards\\'.$relative;
    }

    public function path(string $relative = ''): string
    {
        return $this->root.'/'.ltrim($relative, '/');
    }

    public function has(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function read(string $relative): string
    {
        return is_file($this->path($relative)) ? (string) file_get_contents($this->path($relative)) : throw new RuntimeException($relative.' was not generated.');
    }

    /** @return list<string> every file under the directory, relative to it, sorted */
    public function files(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($files);

        return $files;
    }
}
