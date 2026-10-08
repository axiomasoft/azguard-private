<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Queue;

use AzGuard\Authorization\Authorizer;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Context;

/**
 * An asynchronous export: the job carries the references of the subject, the tenant and the client, and authorizes the
 * export again when it runs. What it saw of the process it runs in is recorded for the tests.
 */
final class ExportClientsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $runs = [];

    public function __construct(public readonly int $user, public readonly int $client, public readonly int $tenant = 1) {}

    public function handle(): void
    {
        $subject = User::query()->findOrFail($this->user);
        $client = Client::query()->findOrFail($this->client);
        $panel = app(PanelRegistry::class)->get('crm');

        self::$runs[] = [
            'user' => $this->user,
            'auth' => HttpWorld::$user,
            'panel' => app(CurrentPanel::class)->get()?->id(),
            'hint' => Context::getHidden('azguard.panel'),
            'scope' => app(CurrentContext::class)->get($panel)?->tenant->key(),
            'authorizer' => spl_object_id(app(Authorizer::class)),
            'allowed' => AzGuard::panel('crm')->inTenant(Organization::query()->findOrFail($this->tenant))->for($subject)
                ->hasPermission(ClientPermission::Update, on: $client),
        ];
    }

    public static function reset(): void
    {
        self::$runs = [];
    }
}
