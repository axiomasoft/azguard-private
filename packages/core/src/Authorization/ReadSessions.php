<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Panels\Panel;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\StorageReadSession;
use Closure;

/**
 * @internal The read sessions of one batch: every attempt of a DecisionSet reads a database source through the same
 * pinned handle, so the storage schema is checked once per handle and not once per subject, and the grouped snapshot
 * of {@see ReadAttempt::readMany()} spans one session.
 */
final class ReadSessions
{
    /** @var array<string, StorageReadSession> */
    private array $sessions = [];

    public function close(): void
    {
        $this->sessions = [];
    }

    /** @param Closure(): StorageReadSession $open */
    public function get(DatabaseSource $source, Panel $panel, Closure $open): StorageReadSession
    {
        return $this->sessions[spl_object_id($source)."\0".$panel->id()."\0".$panel->settings()->reads()->value] ??= $open();
    }
}
