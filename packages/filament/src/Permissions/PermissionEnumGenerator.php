<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use Illuminate\Support\Str;

/**
 * Builds the source of a backed permission enum for one {@see PermissionSubject}.
 *
 * The enum value is the short key ("{resource}.{ability}"); the owning panel
 * prefixes it with the panel id when resolving, so the generated enum is
 * panel-agnostic and picked up by EnumPermissionCatalogBuilder.
 *
 * The {resource} segment is formatted through the injected {@see PermissionSchema}
 * so the case config ('snake'|'kebab'|'camel'|'none') matches what
 * `ResourceGate`/`PolicyGenerator` check at runtime — see F11.
 */
final readonly class PermissionEnumGenerator
{
    public function __construct(
        private PermissionSchema $schema = new PermissionSchema,
    ) {}

    public function className(PermissionSubject $subject): string
    {
        return Str::studly($subject->name).'Permission';
    }

    public function source(PermissionSubject $subject, string $namespace): string
    {
        $class = $this->className($subject);

        // The enum value stays "{resource}.{ability}" — the panel prefix is
        // applied at runtime by Panel::resolvePermission(), never baked into
        // the enum. Only the {resource} case follows the schema, mirroring
        // ResourceGate/PolicyGenerator so the generated keys never drift
        // from what the gate checks (F11).
        $resource = $this->schema->formatResource($subject->name);

        $cases = array_map(
            fn (string $ability): string => sprintf(
                "    case %s = '%s.%s';",
                Str::studly($ability),
                $resource,
                $ability,
            ),
            $subject->abilities,
        );

        $body = implode("\n", $cases);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            enum {$class}: string
            {
            {$body}
            }

            PHP;
    }
}
