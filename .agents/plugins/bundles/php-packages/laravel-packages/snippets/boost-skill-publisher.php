<?php

// Publishing skills from Laravel-package to the consumer (vendor:publish).
//
// The skills of the package are in resources/skills/, folder name of each skill -
// with package prefix (my-package-security/), so that the consumer does not have
// collisions with skills from other sources.
//
// vendor/acme/my-package/
// └── resources/skills/
//     └── my-package-security/
//         ├── SKILL.md
//         └── snippets/
//
// B ServiceProvider::boot():

if ($this->app->runningInConsole()) {
    // Option A: agent-neutral layout
    $this->publishes([
        __DIR__.'/../resources/skills' => base_path('.ai/skills/vendor/my-package'),
    ], 'my-package-skills');

    // Option B: flat layout for Claude Code
    // (.claude/skills/<name>/SKILL.md — nesting is not supported)
    $this->publishes([
        __DIR__.'/../resources/skills' => base_path('.claude/skills'),
    ], 'my-package-skills-claude');
}

// Consumer after composer require:
//   php artisan vendor:publish --tag=my-package-skills-claude
//
// Updating skills when upgrading a package:
//   php artisan vendor:publish --tag=my-package-skills-claude --force
