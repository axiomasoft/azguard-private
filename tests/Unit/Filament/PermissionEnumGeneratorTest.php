<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\PermissionEnumGenerator;
use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Filament\Permissions\PermissionSubject;

it('derives the enum class name from the subject', function (): void {
    $generator = new PermissionEnumGenerator;

    expect($generator->className(new PermissionSubject('BlogPost', 'Blog Posts', [])))
        ->toBe('BlogPostPermission');
});

it('generates a backed permission enum with one case per ability', function (): void {
    $generator = new PermissionEnumGenerator;
    $subject = new PermissionSubject('Post', 'Posts', ['view_any', 'create'], stdClass::class);

    $source = $generator->source($subject, 'App\\Guards\\Admin\\Permissions');

    expect($source)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Guards\\Admin\\Permissions;')
        ->toContain('enum PostPermission: string')
        ->toContain("case ViewAny = 'post.view_any';")
        ->toContain("case Create = 'post.create';");
});

it('formats the {resource} segment through the injected schema case (F11)', function (string $case, string $expectedResource): void {
    $schema = PermissionSchema::fromConfig(['case' => $case]);
    $generator = new PermissionEnumGenerator($schema);
    $subject = new PermissionSubject('BlogPost', 'Blog Posts', ['view_any']);

    $source = $generator->source($subject, 'App\\Guards\\Admin\\Permissions');

    expect($source)->toContain("case ViewAny = '{$expectedResource}.view_any';");
})->with([
    'snake' => ['snake', 'blog_post'],
    'kebab' => ['kebab', 'blog-post'],
    'camel' => ['camel', 'blogPost'],
    'none' => ['none', 'BlogPost'],
]);

it('generated enum value round-trips with the runtime schema key on a non-snake case (F11)', function (string $case): void {
    // A single schema drives both codegen and runtime: the enum value emitted
    // by the generator ("{resource}.{ability}") must equal the {resource}.{ability}
    // tail of the key the gate later checks via PermissionSchema::key(), or
    // authorization silently breaks under a non-snake case.
    $schema = PermissionSchema::fromConfig(['case' => $case]);
    $generator = new PermissionEnumGenerator($schema);
    $subject = new PermissionSubject('BlogPost', 'Blog Posts', ['view_any', 'create']);

    $source = $generator->source($subject, 'App\\Guards\\Admin\\Permissions');

    foreach ($subject->abilities as $ability) {
        $runtimeKey = $schema->key('admin', $subject->name, $ability);

        // The panel prefix is applied at resolve time, not baked into the enum.
        $expectedShortKey = str_replace('admin.', '', $runtimeKey);

        expect($source)->toContain("= '{$expectedShortKey}';");
    }
})->with([
    'kebab' => ['kebab'],
    'camel' => ['camel'],
    'none' => ['none'],
]);

it('defaults to the snake schema when constructed without one', function (): void {
    $generator = new PermissionEnumGenerator;
    $subject = new PermissionSubject('BlogPost', 'Blog Posts', ['view_any']);

    expect($generator->source($subject, 'App\\Guards\\Admin\\Permissions'))
        ->toContain("case ViewAny = 'blog_post.view_any';");
});
