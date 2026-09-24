---
name: laravel-best-practices
bucket: php
version: 0.2.0
description: "Laravel fallback guidance when equivalent versioned project or Boost skills are unavailable."
when_to_use: "Use when versioned framework guidance is absent or incomplete."
risk: write
persona: oss-dev
tags: ["php", "laravel", "best-practices"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Laravel Best Practices

Use as fallback guidance when the project lacks equivalent version-appropriate framework
skills. Boost installation alone does not prove a particular skill exists or is enabled:
inspect installed package guidance and selected provider delivery. Prefer one effective
source for the same capability while retaining unique domain and safety rules.

Follow the project's adopted patterns and installed Laravel version. These references are
examples/defaults, not grounds to replace an established architecture or create review gates.
Verify changing API details against installed code or versioned primary documentation. Read
only the reference for the affected operation, not this whole catalog.

## References by topic

- [db performance](references/db-performance.md)
- [advanced queries](references/advanced-queries.md)
- [security](references/security.md)
- [caching](references/caching.md)
- [eloquent](references/eloquent.md)
- [validation](references/validation.md)
- [config](references/config.md)
- [testing](references/testing.md)
- [queue jobs](references/queue-jobs.md)
- [routing](references/routing.md)
- [http client](references/http-client.md)
- [events notifications](references/events-notifications.md)
- [mail](references/mail.md)
- [error handling](references/error-handling.md)
- [scheduling](references/scheduling.md)
- [architecture](references/architecture.md)
- [migrations](references/migrations.md)
- [collections](references/collections.md)
- [blade views](references/blade-views.md)
- [style](references/style.md)

Validate changed behavior using the project's actual checks. Preserve authorization, input
validation, isolated test storage, transaction/retry semantics, and secret handling; an example
index, DTO, or layer convention is not universally mandatory.
