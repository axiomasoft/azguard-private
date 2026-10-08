<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Queue;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;
use Throwable;

/**
 * A queued job that records the panel it runs in and how the resolver picks the panel for a short name.
 *
 * It carries references only: the user and the client by id.
 */
final class PanelProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $runs = [];

    public static bool $fails = false;

    public function __construct(public readonly int $user = 1, public readonly int $client = 1) {}

    public function handle(): void
    {
        $user = User::query()->findOrFail($this->user);
        $client = Client::query()->findOrFail($this->client);
        $run = ['panel' => app(CurrentPanel::class)->get()?->id(), 'rejected' => app(CurrentPanel::class)->rejected()];

        try {
            $resolution = app(PanelResolver::class)->resolve(subject: $user);
            $run['picked'] = $resolution['panel']->id();
            $run['step'] = $resolution['step'];
            $run['short'] = AzGuard::check($user, 'clients.view', $client);
        } catch (Throwable $exception) {
            $run['refused'] = $exception::class;
        }
        // A full name is explicit: no hint overrides it.
        $run['explicit'] = AzGuard::check($user, 'crm:clients.view', $client);
        self::$runs[] = $run;

        if (self::$fails) {
            throw new RuntimeException('the job failed');
        }
    }

    public static function reset(): void
    {
        self::$runs = [];
        self::$fails = false;
    }
}
