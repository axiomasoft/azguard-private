<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal\Scaffold;

use Packman\Attributes\Internal;
use Packman\DevTools\Scaffold\GeneratedFile as PackmanGeneratedFile;

/**
 * @internal One file a generator is about to write.
 */
#[Internal]
final readonly class GeneratedFile
{
    public function __construct(public string $path, public string $contents) {}

    public function toPackman(string $relativePath, bool $overwrite = false): PackmanGeneratedFile
    {
        return new PackmanGeneratedFile($relativePath, $this->contents, $overwrite);
    }
}
