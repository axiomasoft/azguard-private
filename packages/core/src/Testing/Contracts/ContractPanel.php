<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Testing\FakeSubject;
use Closure;

/**
 * @internal The panel `contract` of the contract suites: fake subjects and two permissions; the suite adds the rest.
 */
final class ContractPanel extends PanelProvider
{
    /** @var (Closure(PanelBuilder): mixed)|null */
    public static ?Closure $configure = null;

    public static function getId(): string
    {
        return ContractWorld::PANEL;
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(FakeSubject::class)->default()->permissions([ContractPermission::class]);

        if (self::$configure !== null) {
            (self::$configure)($panel);
        }

        return $panel;
    }
}
