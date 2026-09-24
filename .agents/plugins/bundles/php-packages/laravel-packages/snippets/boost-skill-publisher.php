<?php

// Публикация скиллов из Laravel-пакета потребителю (vendor:publish).
//
// Скиллы пакета лежат в resources/skills/, имя папки каждого скилла —
// с префиксом пакета (my-package-security/), чтобы у потребителя не было
// коллизий со скиллами из других источников.
//
// vendor/acme/my-package/
// └── resources/skills/
//     └── my-package-security/
//         ├── SKILL.md
//         └── snippets/
//
// В ServiceProvider::boot():

if ($this->app->runningInConsole()) {
    // Вариант A: агент-нейтральная раскладка
    $this->publishes([
        __DIR__.'/../resources/skills' => base_path('.ai/skills/vendor/my-package'),
    ], 'my-package-skills');

    // Вариант B: плоская раскладка для Claude Code
    // (.claude/skills/<name>/SKILL.md — вложенность не поддерживается)
    $this->publishes([
        __DIR__.'/../resources/skills' => base_path('.claude/skills'),
    ], 'my-package-skills-claude');
}

// Потребитель после composer require:
//   php artisan vendor:publish --tag=my-package-skills-claude
//
// Обновление скиллов при апгрейде пакета:
//   php artisan vendor:publish --tag=my-package-skills-claude --force
