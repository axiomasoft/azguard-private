<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Support\Config;
use AzGuard\Support\Panel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Inspect the fully-resolved set of abilities for a user in a panel — the
 * same {@see PermissionSet} the resolver hands to Authorizer::check(), read
 * off-hot-path for debugging "why can/can't this user do X".
 *
 * Examples:
 *   php artisan guard:abilities 1
 *   php artisan guard:abilities 1 --panel=app
 *   php artisan guard:abilities 1 --panel=app --json
 */
final class AbilitiesCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:abilities
        {user      : User ID}
        {--panel=  : Panel ID (defaults to az-guard.default_panel / sole panel)}
        {--model=  : User model FQCN (defaults to auth.providers.users.model)}
        {--json    : Output a machine-readable JSON payload instead of text}';

    protected $description = 'List the fully-resolved abilities for a user in a panel';

    public function handle(PermissionResolverInterface $resolver, AzGuardManagerInterface $manager): int
    {
        $userId = $this->argument('user');
        $modelClass = $this->resolveUserModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::query()->find($userId);

        if ($user === null) {
            $this->error("User [{$userId}] not found (model: {$modelClass}).");

            return self::FAILURE;
        }

        $panelId = $this->resolvePanelId($manager);

        if ($panelId === null) {
            $this->error('No panel specified and none could be resolved (default_panel not set, multiple panels registered).');

            return self::FAILURE;
        }

        $set = $resolver->forUser($user, $panelId);

        if ($this->wantsJson()) {
            $this->line((string) json_encode(
                value: [
                    'user_id' => $userId,
                    'panel_id' => $panelId,
                    'wildcard' => $set->isWildcard(),
                    'abilities' => $set->keys(),
                ],
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->renderText($userId, $panelId, $set);

        return self::SUCCESS;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function resolvePanelId(AzGuardManagerInterface $manager): ?string
    {
        $option = $this->option('panel');

        if (is_string($option) && $option !== '') {
            return isset($manager->getPanels()[$option]) ? $option : null;
        }

        $current = $manager->currentPanel();

        if ($current instanceof Panel) {
            return $current->getId();
        }

        $default = Config::defaultPanel();
        $panels = $manager->getPanels();

        if ($default !== null && isset($panels[$default])) {
            return $default;
        }

        return count($panels) === 1 ? array_key_first($panels) : null;
    }

    private function renderText(mixed $userId, string $panelId, PermissionSet $set): void
    {
        $this->line('');
        $this->info("Abilities for user <comment>{$userId}</comment> (panel: <comment>{$panelId}</comment>):");
        $this->line('');

        if ($set->isWildcard()) {
            $this->line('<fg=yellow>Wildcard (\'*\') — super-admin, all abilities granted.</>');
            $this->line('');

            return;
        }

        $keys = $set->keys();

        if ($keys === []) {
            $this->warn('  No abilities resolved.');
            $this->line('');

            return;
        }

        sort($keys);

        $this->table(
            headers: ['Ability'],
            rows: array_map(static fn (string $key): array => [$key], $keys),
        );

        $this->line('<fg=gray>Total: '.count($keys).' ability(ies)</>');
        $this->line('');
    }
}
