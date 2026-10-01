# AzGuard 0.3 — frozen reference

This directory holds the AzGuard 0.3 code and tests exactly as they were at repository commit `f6d33cc`
(`packages/core`, `packages/context`, `packages/filament`, `tests/`, and the 0.3 root reports).

- It is a read-only reference for the 1.0 rebuild: discovery, generators, and Filament resources can be read here.
- It is not autoloaded and not scanned by Composer, Pest, PHPStan, Rector, Pint, coverage, or CI.
- Nothing here is edited; history is readable with `git log --follow`.
- The whole directory is removed in PLAN2.P6.9.

The 1.0 code lives in `packages/core` (`axiomasoft/azguard`) and `packages/filament` (`axiomasoft/azguard-filament`).
