<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use Illuminate\Support\Str;

/**
 * @internal Where the generators put the structure of a panel: `scaffold.path` and `scaffold.namespace` for the roots,
 * `discovery.*` for the folder names discovery looks for, and validated names for everything under them.
 */
final readonly class Layout
{
    private const string SEGMENT = '/\A[A-Z][A-Za-z0-9]*\z/';

    public function __construct(private AzGuardConfig $config, private string $basePath) {}

    /** The panel directory name such as `Admin`; the folder of `discovery.shared` is not a panel. */
    public function panelName(string $input): string
    {
        $name = Str::studly($input);

        if (preg_match(self::SEGMENT, $name) !== 1) {
            throw new InvalidCommandInput('"'.$input.'" is not a panel name: use letters and digits, such as Admin or SalesDesk.');
        }

        if ($name === $this->folder('shared')) {
            throw new InvalidCommandInput($name.' is the folder of what panels share and is not a panel.');
        }

        return $name;
    }

    /** The id of the panel of a directory name: `SalesDesk` is `sales-desk`. */
    public function panelId(string $panel): string
    {
        $id = Str::kebab($panel);

        if (! PermissionGrammar::isPanelId($id)) {
            throw new InvalidCommandInput('The panel id "'.$id.'" of '.$panel.' is not valid: it is lowercase letters, digits and hyphens, at most 64 characters.');
        }

        return $id;
    }

    /**
     * A group path such as `Orders` or `Sales/Orders` (a backslash works too) as its segments.
     *
     * @return non-empty-list<string>
     */
    public function group(string $input): array
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', trim($input, '/\\'))) as $segment) {
            $name = Str::studly($segment);

            if (preg_match(self::SEGMENT, $name) !== 1) {
                throw new InvalidCommandInput('"'.$input.'" is not a group: use names of letters and digits separated by "/", such as Orders or Sales/Orders.');
            }
            $segments[] = $name;
        }

        return $segments;
    }

    /** A class name from a name argument, ending with the suffix of its kind (`Ldap` to `LdapSource`). */
    public function className(string $input, string $suffix = ''): string
    {
        $name = Str::studly($input);

        if ($suffix !== '' && str_ends_with($name, $suffix) && $name !== $suffix) {
            $name = substr($name, 0, -strlen($suffix));
        }

        if (preg_match(self::SEGMENT, $name) !== 1) {
            throw new InvalidCommandInput('"'.$input.'" is not a class name: use letters and digits, such as Ldap.');
        }

        return $name.$suffix;
    }

    /** The folder name of discovery such as `Permissions`. */
    public function folder(string $kind): string
    {
        return $this->config->discovery()[$kind] ?? throw new InvalidCommandInput('Unknown discovery folder "'.$kind.'".');
    }

    public function panel(string $panel): Place
    {
        return $this->root()->in($panel);
    }

    /** What several panels share: the sibling of the panel directories. */
    public function shared(): Place
    {
        return $this->root()->in($this->folder('shared'));
    }

    public function root(): Place
    {
        return new Place(rtrim($this->basePath, '/').'/'.$this->config->scaffoldPath(), $this->config->scaffoldNamespace());
    }

    /** The place of a panel that already has its directory; a generator never creates a panel by the way. */
    public function existingPanel(string $panel): Place
    {
        $place = $this->panel($panel);

        if (! is_dir($place->directory)) {
            throw new InvalidCommandInput('Panel '.$panel.' has no directory '.$this->relative($place->directory).': run azguard:make:panel '.$panel.' first.');
        }

        return $place;
    }

    /** The path relative to the application. */
    public function relative(string $path): string
    {
        $base = rtrim($this->basePath, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** `Orders` is `Order`: the name a group gives its enum, policy and abilities. */
    public static function nameOf(string $segment): string
    {
        return Str::singular($segment);
    }
}
