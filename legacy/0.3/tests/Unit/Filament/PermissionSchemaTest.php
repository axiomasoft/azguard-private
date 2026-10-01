<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Filament\Permissions\PermissionSubject;

it('renders a key from the template, panel, resource and ability', function (): void {
    $schema = new PermissionSchema;

    expect($schema->key('admin', 'Post', 'view_any'))->toBe('admin.post.view_any');
});

it('snake-cases multi-word resource names by default', function (): void {
    $schema = new PermissionSchema;

    expect($schema->key('admin', 'BlogPost', 'create'))->toBe('admin.blog_post.create');
});

it('honours a custom key template and case', function (): void {
    $schema = PermissionSchema::fromConfig([
        'key' => '{ability}:{resource}',
        'case' => 'kebab',
    ]);

    expect($schema->key('admin', 'BlogPost', 'view_any'))->toBe('view_any:blog-post');
});

it('formats a bare {resource} segment matching the key transform (F11)', function (string $case, string $expected): void {
    $schema = PermissionSchema::fromConfig(['case' => $case]);

    // formatResource() must equal the {resource} segment key() bakes in, so the
    // panel-agnostic enum generator stays in parity with the gate.
    expect($schema->formatResource('BlogPost'))->toBe($expected)
        ->and($schema->key('admin', 'BlogPost', 'view_any'))
        ->toBe("admin.{$expected}.view_any");
})->with([
    'snake' => ['snake', 'blog_post'],
    'kebab' => ['kebab', 'blog-post'],
    'camel' => ['camel', 'blogPost'],
    'none' => ['none', 'BlogPost'],
]);

it('builds one key per ability for a subject', function (): void {
    $schema = new PermissionSchema;
    $subject = new PermissionSubject('Post', 'Posts', ['view_any', 'create', 'delete']);

    expect($schema->keys('admin', $subject))->toBe([
        'admin.post.view_any',
        'admin.post.create',
        'admin.post.delete',
    ]);
});

it('builds catalog definitions grouped by the subject label', function (): void {
    $schema = new PermissionSchema;
    $subjects = [
        new PermissionSubject('Post', 'Posts', ['view_any', 'create']),
        new PermissionSubject('Tag', 'Tags', ['view_any']),
    ];

    $definitions = $schema->definitions('admin', $subjects);

    expect($definitions)->toHaveCount(3)
        ->and($definitions[0]->key())->toBe('admin.post.view_any')
        ->and($definitions[0]->shortKey())->toBe('post.view_any')
        ->and($definitions[0]->panelId())->toBe('admin')
        ->and($definitions[0]->group())->toBe('Posts')
        ->and($definitions[0]->meta()->label())->toBe('View Any')
        ->and($definitions[2]->key())->toBe('admin.tag.view_any')
        ->and($definitions[2]->group())->toBe('Tags');
});
