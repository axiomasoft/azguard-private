<?php

declare(strict_types=1);

namespace AzGuard\Tests\Unit;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BladeDirectiveTest extends TestCase
{
    use RefreshDatabase;
    // ─── @azdirect ────────────────────────────────────────────────────────────

    public function test_azdirect_renders_content_when_grant_exists(): void
    {
        $user = $this->createUserWithDirectGrant('app.documents.export', 'app');
        $this->actingAs($user);

        $panel = Panel::make()->id('app');
        AzGuard::setCurrentPanel($panel);

        $html = (string) $this->blade(
            '@azdirect(\'app.documents.export\') <span>OK</span> @endazdirect',
        );

        $this->assertStringContainsString('<span>OK</span>', $html);
    }

    public function test_azdirect_hides_content_when_grant_missing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $panel = Panel::make()->id('app');
        AzGuard::setCurrentPanel($panel);

        $html = (string) $this->blade(
            '@azdirect(\'app.documents.export\') <span>SECRET</span> @endazdirect',
        );

        $this->assertStringNotContainsString('<span>SECRET</span>', $html);
    }

    public function test_azdirect_hidden_when_not_authenticated(): void
    {
        $html = (string) $this->blade(
            '@azdirect(\'app.x\') <span>HIDDEN</span> @endazdirect',
        );

        $this->assertStringNotContainsString('<span>HIDDEN</span>', $html);
    }
}
