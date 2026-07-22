# Filament development

## When to use this skill
Use this skill when adding or changing Filament v5 resources/pages/widgets, or when writing tests around Filament behaviour.

## Project expectations
- Generate Filament components via `php artisan make:filament-*` (always `--no-interaction`) and follow the existing `app/Filament/` structure: resource class + `Pages/` + `Schemas/<Model>Form.php` + `Tables/<Model>sTable.php`.
- Keep UI definitions idiomatic: `make()` constructors, fluent schema configuration, `Closure`-based dynamic values, and proper relationship helpers (`->relationship()`).
- Import actions from `Filament\Actions\` only; layout components from `Filament\Schemas\Components\`.
- In Boost-enabled projects the vendor-shipped Filament guidelines are the source of truth for API details; this skill covers structure and project patterns.
