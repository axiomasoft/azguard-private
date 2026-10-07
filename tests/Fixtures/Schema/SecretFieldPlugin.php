<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Tests\Fixtures\Storage\Weekday;
use Closure;
use Illuminate\Validation\Rule;

/** A plugin with a typed secret parameter that contributes grant fields; the secret must never reach a schema. */
final class SecretFieldPlugin extends BasePlugin
{
    public const SECRET = 'sk-live-schema-secret-0f9c';

    private function __construct(private readonly string $apiToken) {}

    public static function make(string $apiToken): self
    {
        return new self($apiToken);
    }

    public function id(): string
    {
        return 'acme/reasons';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $token = $this->apiToken;
        $panel->fields(FieldTarget::RoleGrant, [
            Field::string('reason')->label('Причина выдачи')->required()->rules(['max:200', Rule::in(['audit', 'onboarding']),
                static fn (string $attribute, mixed $value, Closure $fail): bool => $value !== $token]),
            Field::enum('weekday', Weekday::class)->label('День')->multiple()->inMeta(),
        ]);
    }
}
