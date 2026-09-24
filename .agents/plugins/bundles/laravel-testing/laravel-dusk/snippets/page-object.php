<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page as BasePage;

// --- Базовый Page (tests/Browser/Pages/Page.php) ---

abstract class Page extends BasePage
{
    /**
     * Глобальные шорткаты элементов для всего сайта.
     *
     * @return array<string, string>
     */
    final public static function siteElements(): array
    {
        return [
            '@flash' => '[data-test="flash-message"]',
        ];
    }
}

// --- Конкретная страница (tests/Browser/Pages/DocumentListPage.php) ---

final class DocumentListPage extends Page
{
    public function url(): string
    {
        return '/documents';
    }

    /** Проверка, что браузер действительно на этой странице. */
    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())
            ->waitFor('@create-button', 10);
    }

    /**
     * Шорткаты: в тестах пишем @create-button вместо хрупких CSS-селекторов.
     *
     * @return array<string, string>
     */
    public function elements(): array
    {
        return [
            '@create-button' => '[data-test="document-create"]',
            '@search' => 'input[name="search"]',
            '@first-row' => 'table tbody tr:first-child',
        ];
    }

    /** Повторяющиеся действия страницы — методы Page Object. */
    public function searchFor(Browser $browser, string $term): void
    {
        $browser->type('@search', $term)
            ->keys('@search', '{enter}')
            ->waitFor('@first-row', 10);
    }
}

// --- Использование в тесте ---
//
// $this->browse(function (Browser $browser): void {
//     $browser->loginAs(User::factory()->create())
//         ->visit(new DocumentListPage)       // url() + assert()
//         ->click('@create-button');          // шорткат из elements()
//
//     (new DocumentListPage)->searchFor($browser, 'annual report');
// });
