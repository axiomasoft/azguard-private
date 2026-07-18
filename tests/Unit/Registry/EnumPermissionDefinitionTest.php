<?php

declare(strict_types=1);

namespace AzGuard\Tests\Unit\Registry;

use AzGuard\Registry\Definitions\EnumPermissionDefinition;
use PHPUnit\Framework\TestCase;

enum DocumentsPermission: string
{
    case View = 'view';
    case ViewAny = 'view-any';
    case Create = 'create';
}

final class EnumPermissionDefinitionTest extends TestCase
{
    public function test_key_returns_resolved_key(): void
    {
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::View,
            panelId: 'app',
            resolvedKey: 'app.documents.view',
        );

        $this->assertSame('app.documents.view', $def->key());
    }

    public function test_short_key_strips_panel_prefix(): void
    {
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::View,
            panelId: 'app',
            resolvedKey: 'app.documents.view',
        );

        $this->assertSame('documents.view', $def->shortKey());
    }

    public function test_group_inferred_from_class_name(): void
    {
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::View,
            panelId: 'app',
            resolvedKey: 'app.documents.view',
        );

        // DocumentsPermission -> Documents
        $this->assertSame('Documents', $def->group());
    }

    public function test_label_formatted_from_pascal_case(): void
    {
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::ViewAny,
            panelId: 'app',
            resolvedKey: 'app.documents.view-any',
        );

        // ViewAny -> View Any
        $this->assertSame('View Any', $def->meta()->label());
    }

    public function test_label_on_definition_mirrors_meta_label(): void
    {
        // P2.2: label() is part of the PermissionDefinition contract — the
        // Filament catalog UIs call it directly on the definition.
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::ViewAny,
            panelId: 'app',
            resolvedKey: 'app.documents.view-any',
        );

        $this->assertSame('View Any', $def->label());
    }

    public function test_is_not_dynamic(): void
    {
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::View,
            panelId: 'app',
            resolvedKey: 'app.documents.view',
        );

        $this->assertFalse($def->isDynamic());
    }

    public function test_panel_id(): void
    {
        $def = EnumPermissionDefinition::fromCase(
            case: DocumentsPermission::View,
            panelId: 'admin',
            resolvedKey: 'admin.documents.view',
        );

        $this->assertSame('admin', $def->panelId());
    }
}
