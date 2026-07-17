<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Events\AccessDecision;
use AzGuard\Guard\Authorizer;
use AzGuard\Support\Panel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Inspect WHY a single authorization decision landed the way it did.
 *
 * Off-hot-path only: re-runs {@see Authorizer::explain()} and prints the
 * resulting {@see AccessDecision} — never a flag on the hot check() path.
 *
 * Examples:
 *   php artisan guard:explain 1 app.documents.view
 *   php artisan guard:explain 1 app.documents.view --panel=app
 *   php artisan guard:explain 1 app.documents.view --json
 */
final class ExplainCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:explain
        {user    : User ID}
        {ability : Permission key / gate ability to explain}
        {--panel=       : Panel ID (defaults to the resolver default / sole panel)}
        {--model=       : User model FQCN (defaults to auth.providers.users.model)}
        {--json         : Output a machine-readable JSON payload instead of text}';

    protected $description = 'Explain WHY a user was granted or denied an ability';

    public function handle(Authorizer $authorizer, AzGuardManagerInterface $manager): int
    {
        $userId = $this->argument('user');
        $ability = (string) $this->argument('ability');
        $modelClass = $this->resolveUserModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::query()->find($userId);

        if ($user === null) {
            $this->error("User [{$userId}] not found (model: {$modelClass}).");

            return self::FAILURE;
        }

        $panelOption = $this->option('panel');
        $previousPanel = $manager->currentPanel();

        if (is_string($panelOption) && $panelOption !== '') {
            $panel = $manager->getPanels()[$panelOption] ?? null;

            if (! $panel instanceof Panel) {
                $this->error("Panel [{$panelOption}] is not registered.");

                return self::FAILURE;
            }

            $manager->setCurrentPanel($panel);
        }

        try {
            $decision = $authorizer->explain($user, $ability);
        } finally {
            $manager->setCurrentPanel($previousPanel);
        }

        if ($this->wantsJson()) {
            $this->line((string) json_encode(
                value: $this->toPayload($decision),
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->renderText($decision);

        return self::SUCCESS;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function renderText(AccessDecision $decision): void
    {
        $this->line('');
        $this->info('Decision for user <comment>'.$decision->userId.'</comment>, ability <comment>'.$decision->ability.'</comment>:');
        $this->line('');

        $this->table(
            headers: ['Field', 'Value'],
            rows: [
                ['Panel', $decision->panelId !== '' ? $decision->panelId : '—'],
                ['Allowed', $decision->allowed ? 'yes' : 'no'],
                ['Reason', $decision->reasonCode],
                ['Winning source', $decision->winningSource ?? '—'],
            ],
        );

        $this->line('');
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(AccessDecision $decision): array
    {
        return [
            'user_id' => $decision->userId,
            'panel_id' => $decision->panelId,
            'ability' => $decision->ability,
            'allowed' => $decision->allowed,
            'reason_code' => $decision->reasonCode,
            'winning_source' => $decision->winningSource,
        ];
    }
}
