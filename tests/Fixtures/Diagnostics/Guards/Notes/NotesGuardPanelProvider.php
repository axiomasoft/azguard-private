<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Panels\User;
use Closure;

/**
 * A folder panel for the doctor: a grant permission, a policy-only permission and its policy, one role.
 */
final class NotesGuardPanelProvider extends PanelProvider
{
    /** @var (Closure(PanelBuilder): mixed)|null */
    public static ?Closure $configure = null;

    public static function getId(): string
    {
        return 'notes';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(User::class)->permissions([DatabaseSource::make()]);

        if (self::$configure !== null) {
            (self::$configure)($panel);
        }

        return $panel;
    }
}
