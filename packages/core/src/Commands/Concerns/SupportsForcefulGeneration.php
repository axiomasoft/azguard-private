<?php

declare(strict_types=1);

namespace AzGuard\Commands\Concerns;

trait SupportsForcefulGeneration
{
    /**
     * Whether the --force flag is set to overwrite existing files.
     */
    protected function shouldForce(): bool
    {
        return (bool) $this->option(key: 'force');
    }

    /**
     * Check if a file exists and handle based on --force flag.
     * Returns true if we should proceed with generation.
     */
    protected function checkFileExists(string $filePath): bool
    {
        if (! file_exists($filePath)) {
            return true;
        }

        if ($this->shouldForce()) {
            return true;
        }

        $this->error("File already exists: {$filePath}. Use --force to overwrite.");

        return false;
    }
}
