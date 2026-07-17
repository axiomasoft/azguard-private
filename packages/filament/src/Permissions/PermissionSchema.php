<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;
use AzGuard\Registry\Definitions\SimplePermissionMeta;
use Illuminate\Support\Str;

/**
 * Renders permission keys and catalog definitions for a set of
 * {@see PermissionSubject}s, following the configured key template and case.
 *
 * Pure, runtime-agnostic — the same schema drives the catalog builder, the
 * gate enforcement map, and the code generator.
 */
final readonly class PermissionSchema
{
    public function __construct(
        private string $keyTemplate = '{panel}.{resource}.{ability}',
        private string $case = 'snake',
    ) {}

    /**
     * @param  array{key?: string, case?: string}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            keyTemplate: $config['key'] ?? '{panel}.{resource}.{ability}',
            case: $config['case'] ?? 'snake',
        );
    }

    public function key(string $panelId, string $subject, string $ability): string
    {
        return strtr($this->keyTemplate, [
            '{panel}' => $panelId,
            '{resource}' => $this->formatSubject($subject),
            '{ability}' => $ability,
        ]);
    }

    /**
     * Format a raw subject name into its {resource} key segment, applying the
     * configured case — the same transform {@see key()} applies internally.
     * Consumers that build keys outside the {@see keyTemplate} (e.g. the
     * panel-agnostic enum generator) use this to stay in parity.
     */
    public function formatResource(string $subject): string
    {
        return $this->formatSubject($subject);
    }

    /**
     * @return list<string>
     */
    public function keys(string $panelId, PermissionSubject $subject): array
    {
        return array_map(
            fn (string $ability): string => $this->key($panelId, $subject->name, $ability),
            $subject->abilities,
        );
    }

    /**
     * @param  iterable<PermissionSubject>  $subjects
     * @return list<PermissionDefinition>
     */
    public function definitions(string $panelId, iterable $subjects): array
    {
        $definitions = [];

        foreach ($subjects as $subject) {
            foreach ($subject->abilities as $ability) {
                $definitions[] = new SimplePermissionDefinition(
                    key: $this->key($panelId, $subject->name, $ability),
                    panelId: $panelId,
                    group: $subject->label,
                    meta: new SimplePermissionMeta(label: Str::headline($ability)),
                );
            }
        }

        return $definitions;
    }

    private function formatSubject(string $name): string
    {
        return match ($this->case) {
            'snake' => Str::snake($name),
            'kebab' => Str::kebab($name),
            'camel' => Str::camel($name),
            default => $name,
        };
    }
}
