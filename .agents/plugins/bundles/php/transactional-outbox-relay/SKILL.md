---
name: transactional-outbox-relay
bucket: php
version: 0.1.1
description: "Use for durable async delivery: transactional outbox, relay retries, idempotent consumers."
risk: write
persona: oss-dev
tags: ["php", "laravel", "queue", "outbox", "reliability", "async"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Transactional Outbox + Relay

Reliable async delivery (webhooks, integration events) without lost or duplicated effects.

## Core invariants

1. **Persist + outbox in ONE transaction.** The domain write and the outbox row commit together (`DB::transaction`). Never write the outbox "best effort" after commit — a crash in between loses the event.
2. **Single retry owner = the relay sweep.** A background relay polls unsent outbox rows and delivers. The delivery job MUST NOT also `release()`/re-dispatch on failure — two owners (queue retry + relay) race on `attempts` and double-fire. Pick ONE: the relay owns retries; the job only reports success/failure.
3. **Idempotent consumers.** At-least-once → delivery may repeat. The consumer dedups by a stable event id (unique constraint / `INSERT … ON CONFLICT DO NOTHING`), NOT read-then-insert (that races under concurrency).
4. **Claim under lock.** The relay claims a batch with `lockForUpdate` (or a `claimed_at` CAS) so two relay workers don't grab the same rows.
5. **Backoff + DLQ.** Exponential backoff on transient failure; after N attempts → dead-letter (don't retry forever, don't drop silently).
6. **Side-effects after commit.** Broadcasts/notifications fire in an after-commit listener (`dispatchAfterCommit` / `->afterCommit()`), never inside the transaction — a rollback must not leave a sent webhook.

## Red flags (real incidents)

- Outbox insert outside the domain transaction.
- `ShouldQueue` job calling `$this->release()` while a relay also re-sweeps → race on `attempts` (double delivery).
- Consumer doing `if (!exists) insert` instead of a unique constraint.
- Event dispatched inside `DB::transaction` (a listener rollback ≠ event rollback).

## Related

- `php:production-readiness-checklist` — verification pass (atomicity / race / after-commit).
- `php:repositories` — persistence layering for the domain write.
