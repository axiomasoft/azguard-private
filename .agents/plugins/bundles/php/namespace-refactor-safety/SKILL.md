---
name: namespace-refactor-safety
bucket: php
version: 0.1.1
description: "Use for PHP namespace/class moves: FQN-safe edits, aliases, and caller compatibility."
risk: write
persona: oss-dev
tags: ["php", "refactor", "namespace", "safety"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Namespace / Move Refactor Safety

Large namespace moves break silently. Stage them.

## Three phases (don't collapse)

1. **Alias.** Add a `class_alias` / re-export at the old FQN so nothing breaks while you move.
2. **Update `use` statements** across call sites (mechanical, reviewable diff).
3. **Hard rename / remove the alias** once all references point at the new FQN.

## Traps

- **Prefix collisions on find-replace.** `Channel` is a substring of `ChannelMember`; `Message` of `MessageNotFound`. A blind `sed s/Message/Chat\Message/` mangles `MessageNotFound`. Anchor on the FQN / `use` line, not the bare token.
- **Contracts as the seam.** Define/keep an interface at the boundary — `phpstan` then flags every implementer that drifts (a typed seam beats grep).
- **Arch-tests for parity.** A Pest/PHPUnit arch test asserting "namespace mirrors directory / layer X only depends on Y" catches a half-finished move.

## Versioning

- Moving a PUBLIC class FQN is a breaking change → major bump + a migration note (or keep the alias one minor as deprecation).

## Related

- `php:laravel-structure`, `php:static-analysis` (phpstan seam).
