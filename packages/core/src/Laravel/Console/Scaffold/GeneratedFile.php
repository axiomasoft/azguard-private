<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

/**
 * @internal One file a generator is about to write.
 */
final readonly class GeneratedFile
{
    public function __construct(public string $path, public string $contents) {}
}
