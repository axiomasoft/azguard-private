<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Support\Config;
use Illuminate\Console\Command;

/**
 * View active direct grants.
 *
 * Examples:
 *   php artisan guard:grants                           # all active
 *   php artisan guard:grants --user=1                  # specific user
 *   php artisan guard:grants --user=1 --panel=app      # user + panel
 *   php artisan guard:grants --panel=admin             # all users, panel admin
 *   php artisan guard:grants --all                     # include expired
 *   php artisan guard:grants --format=json
 */
class GrantsListCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:grants
        {--user=        : User ID (filter)}
        {--panel=       : Panel ID (filter)}
        {--model=       : User model FQCN (defaults to auth.providers.users.model)}
        {--all          : Include expired grants}
        {--format=table : Output format: table, json, csv}';

    protected $description = 'List direct permission grants';

    public function handle(): int
    {
        $userId = $this->option('user');
        $panelId = $this->option('panel');
        $modelClass = $this->resolveUserModelClass();
        $includeAll = (bool) $this->option('all');
        $format = (string) $this->option('format');

        $grantModel = Config::directGrantModel();

        $query = $grantModel::query()
            ->where('grantable_type', $modelClass)
            ->orderBy('panel_id')
            ->orderBy('grantable_id');

        if ($userId !== null) {
            $query->where('grantable_id', $userId);
        }

        if ($panelId !== null) {
            $query->where('panel_id', $panelId);
        }

        if (! $includeAll) {
            $query->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        }

        $grants = $query->get(['grantable_id', 'panel_id', 'permission_key', 'expires_at']);

        if ($grants->isEmpty()) {
            $this->warn('No grants found.');

            return self::SUCCESS;
        }

        $rows = $grants->map(fn ($g): array => [
            'user_id' => $g->grantable_id,
            'panel' => $g->panel_id,
            'permission_key' => $g->permission_key,
            'expires_at' => $g->expires_at ? $g->expires_at->toDateTimeString() : '—',
        ])->all();

        match ($format) {
            'json' => $this->line(json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'csv' => $this->outputCsv($rows),
            default => $this->table(['User ID', 'Panel', 'Permission Key', 'Expires At'], $rows),
        };

        if ($format === 'table') {
            $this->line('');
            $this->line('<fg=gray>Total: '.count($rows).' grant(s)</>');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function outputCsv(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->line(implode(',', array_keys($rows[0])));
        foreach ($rows as $row) {
            $this->line(implode(',', array_map(
                fn ($v): string => is_string($v) && str_contains($v, ',') ? "\"{$v}\"" : (string) $v,
                $row,
            )));
        }
    }
}
