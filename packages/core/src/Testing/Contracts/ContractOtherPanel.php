<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Testing\FakeSubject;
use Closure;

/**
 * @internal The panel `other` of the contract suites, for the guarantees that need a second panel.
 */
final class ContractOtherPanel extends PanelProvider
{
    /** @var (Closure(PanelBuilder): mixed)|null */
    public static ?Closure $configure = null;

    public static function getId(): string
    {
        return ContractWorld::OTHER;
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(FakeSubject::class)->permissions([OtherPermission::class]);

        if (self::$configure !== null) {
            (self::$configure)($panel);
        }

        return $panel;
    }
}
