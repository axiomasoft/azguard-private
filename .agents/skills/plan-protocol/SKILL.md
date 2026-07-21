---
name: plan-protocol
bucket: general
version: 2.11.0
description: "Executable plans in plans/<ID>: schemas, 6 statuses, DoR, neutral class/effort/capability routing, semantic Next with provider renderers, handoff and archive."
risk: write
persona: oss-dev
tags: [workflow, planning, handoff, routing, multi-model]
requires: []
produces_for: [session-handoff, complex-task-orchestrator]
outputs: ["plans/<PLAN-ID>/plan.md", "plans/<PLAN-ID>/phases/*.md", "plans/<PLAN-ID>/handoff.md"]
snippets: ["plan-template.md", "phase-template.md", "handoff-template.md", "active-template.md", "launch-block-template.md", "roadmap-template.md", "code-guidance-patterns.md", "journal-template.md", "index-template.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Plan Protocol

Ecosystem SSOT for the plan protocol: how an executable master plan is structured — a
frontier class writes it, implementation/economy execute it without the planning chat.
It fixes ONLY Axioma specifics — schemas, gates, markers, pipelines; general planning
practices that plan mode / the model handle on their own are not retold here.

## 1. When to activate

- Multi-phase work longer than one session (the plan outlives context).
- "Frontier plans; implementation/economy execute."
- Any semantic `task:plan-*` command (design/exec/run/close/audit).

**"Do we need a plan?" threshold (heuristic):**

| Task traits | Route |
|:--|:--|
| Small edit, bugfix, single file | `/task:fix` without a master plan |
| Feature in one module, PoC, experiment | `/task:dev` without a master plan |
| Cross-module; compliance (54-FZ/payments/PII); >1 repo; >1 session | `task:plan-design` with this protocol's gates is MANDATORY |

## 2. Layout

```text
plans/<PLAN-ID>/
├── plan.md            # master plan (Meta, Context, Rules, Routing, Status Board, logs)
├── phases/Pn.md       # one file per phase: phase context, items, phase handoff
├── handoff.md         # rolling "where we are" pointer (overwritten)
├── index.md           # file map + findings/research→phase provenance (section 2, optional)
├── roadmap.md         # execution map: batches/workflows/owner gates (section 8; mandatory once fully detailed)
├── journal.jsonl      # append-only execution history, one entry per task:plan-* run (section 2)
├── journal-exceptions.jsonl # optional append-only owner-gated exceptions for historical schema-invalid lines
├── brief/             # layer 0 "input": the owner's brief + appended refinements (section 16, MANDATORY)
├── findings/          # layer 1 "raw": RAG extracts, recon, source documents (section 16)
├── research/          # layer 2 "synthesis": the model's authored design docs (section 16)
├── artifacts/         # phases' working artifacts, destiny — archive
├── workflows/         # plan's field copies of workflow scripts (template sources — packages/task/workflows/)
├── root/              # long-lived material, destiny — project docs (section 3)
└── open-questions.md  # disputed/unresolved (protocol — section 3)
```

Mandatory: `plan.md`/`phases/`/`handoff.md` AND `brief/` (layer 0 — a `plan-lint` gate:
for date-based plans from 2026.07.16 a missing non-empty `brief/*.md` is an ERROR, the
plan does not pass approval; older/legacy — WARNING). Everything else is optional, per the
plan's needs. `PLAN-ID` — one of two canonical patterns:

**Execution journal `journal.jsonl`** (append-only NDJSON, schema — contract
`plan-journal-schema.md`): one entry per item execution by any `task:plan-*` run (a start
entry `result:"in-progress"` + a terminal-`result` final entry), written by the command's
close protocol (`lib/task/journal.py`). Existing lines are NEVER rewritten/deleted — history,
not current state (does not duplicate the Status Board/handoff). `plan-lint` gate: for
date-based plans from 2026.07.18 a missing/invalid `journal.jsonl` is an ERROR; older/legacy
— WARNING (a soft gate against breaking the already-running fleet — the same pattern as
brief/roadmap).

**Historical schema exception.** A compensating event cannot validate an earlier invalid
line because the gate is strict per line. When append-only forbids rewriting, the owner may
approve one provenance-preserving exception with a D# and
`python3 -m task.journal exception ...`. The optional append-only
`journal-exceptions.jsonl` pins the physical target line, SHA-256 of its exact UTF-8 bytes
without newline, exact `invalid_fields`, owner D#, actor, and reason. v1 allows only
`friction`; the target must be an existing JSON event for the same plan and have exactly
those schema errors. Duplicate targets, hash mismatch, a valid target, missing D#, and
malformed JSON fail closed. The sidecar carries no event/status/next and cannot target
itself, so it is neither current state nor cyclic correction. Preserve the old line and
old D#; a new D# explicitly supersedes the failed remediation.

**Plan index `index.md`** (optional) — a map of the plan's FILES + provenance links
(`findings`/`research` → consuming phase); NOT a carrier of status/next (that's the Status
Board/`handoff.md`). Recommended auto-generated from the file tree + frontmatter; MVP —
manual, from `snippets/index-template.md`. `plan-lint` gate — WARNING only (navigational
convenience, not a mandatory fact carrier, unlike the journal/brief).

- **date-based** (preferred, D16) — `<YYYY>.<MM>.<DD>-<CODENAME>`, e.g.
  `2026.07.10-SETTINGS-DOCTOR`: chronological sorting of `plans/` + a "when" cue without
  reading Meta;
- **legacy** (back-compat, D24) — `[A-Z][A-Z0-9-]+`, e.g. `AUDIT-W2`, `MIGRATE-V3`.

The legacy pattern stays valid, existing plans are NOT renamed; migration is forward-only
(terminal plans move to the archive as they close).

`plans/` root, next to the plan directories:

- **`plans/ACTIVE.md`** — flat pointer to the active plan. Canon — EXACTLY 4 lines
  (parsed by `plan-lint`): `# ACTIVE`, blank line, `**Active:** <PLAN-ID>` (no active
  plan — `—`), `**Updated:** <YYYY-MM-DD>`. Skeleton — `snippets/active-template.md`.
  Maintained by `plan-design` (new/from-draft — sets the new plan's ID) and `plan-close`
  (the WHOLE plan closed — resets to `—`).
- **Draft convention:** any flat `.md` directly in `plans/` (except `ACTIVE.md` and
  `README.md`) is a plan-mode draft; expand it with
  `task:plan-design from-draft <файл>` (`plan-lint` warns about lingering drafts).

**MD-only rule:** the stored plan format is Markdown with strict markers ONLY. Any JSON
is a derived parser artifact (`plan-lint --json`), never the source of truth.

**Workflow-scripts home.** A multi-agent workflow script is the owning plan's artifact
(like `findings/`/`artifacts/`): the only place is `plans/<ID>/workflows/`. Name —
`wf-<codename-lower>-<purpose>.js`, codename (D28) = the ID part after the date
(`2026.07.10-FLEX` → `flex`) or the whole legacy ID (`SKM-ROADMAP-005` →
`skm-roadmap-005`), always lowercase; example — `wf-ecosystem-design-fleet.js`. Run
pipeline docs — `<Pn>-pipeline.md` alongside. Authoring discipline (stages, slots,
structured summary) — skill `workflow-craft`; portable template sources —
`packages/task/workflows/README.md`.

## 3. Canonical plan root

Two artifact directories (RAG:✅ P5.1 §2 arc42 — timeless material at the root, iterations
in children):

- **`root/`** — long-lived material whose destiny is project documentation:
  `architecture.md`, `data-model.md`, `contracts/`, `philosophy.md`, `glossary.md` (set is
  per the plan's needs — not all files mandatory).
- **`artifacts/`** — phases' working material, archived together with the plan.

**Open Questions protocol** (RAG:✅ P5.1 §7 IETF rough consensus): a disputed point does
NOT block the plan if moved out into `open-questions.md` with one of the statuses:
`Exploration` | `Decision pending (нужен владелец)` | `Resolved → D#`.

Rules: a question "hanging" in a phase body without being moved out = audit finding;
closing a plan with pending questions = only `🟠 Done with deviations`; an objection is
recorded substantively ("I don't like it" is not an objection).

**Fork carriers — two roles, no duplicates:** `## Обсуждение` (Discussion) in plan.md
(section 4) = PROVENANCE (options, recommendation, where it landed), archived with the
plan; `open-questions.md` = TRACKER of the unresolved — a fork whose status ≠ `Resolved`
MUST have an entry there, a resolved one is NOT copied (no duplicates); nothing unresolved
→ the file is NOT created (an empty one is a noise artifact).

**Re-design over closed phases** (RAG:✅ P5.1 §7 superseded chains): a new decision = a new
D# with a "supersedes D#-old" reference; the old D# is NOT edited.

**Phase reopen — FOUR steps** (all mandatory; skipping one = audit finding):

1. Phase Status `🟢`/`🟠` → `🟡` — in **`## 4. Phase Index & Status Board`** (`plan.md`).
   That is the SOLE carrier of the PHASE status (D51): the items table in `phases/Pn.md` does
   NOT carry the phase status — `plan-lint` rejects a non-item row with an ERROR (§5). A second
   carrier of one fact = a desync source, creating it is forbidden. The reopened phase's item
   statuses are fixed by its remediation (per the new items' specs), not by the reopen step.
2. Plan Version bump.
3. A D-log entry with the reason for the reopen.
4. **The reopened phase's `## Phase Handoff` is INVALIDATED.** The block is NOT deleted
   (provenance of what was delivered and when is kept); its **first non-empty line** gets
   the marker:

   ```text
   > УСТАРЕЛ — фаза реопенена <D#>
   ```

   After the marker (same quote) — facts as of the reopen: how many items and what status
   NOW, what the next step is. A bold marker (`> **УСТАРЕЛ — фаза реопенена D#.**`) is
   legal — the gate looks at the line start. Only `plan-close` removes it, at the next
   phase close, by overwriting the whole block (basis H2: a block surviving a reopen keeps
   claiming "phase closed, N/N terminal" and misleads the cold-start agent).

   `plan_lint` gate: phase NOT terminal + block claims closure (`терминальн` /
   `фаза закрыта` / `Сдано:`) + no marker → **WARNING** "Phase Handoff stale". The closure
   claim is read **by the block's first non-empty line** — the same line the marker is sought
   on — and is cancelled by a NEGATION on that same line (`не закрыт…`): the gate judges the
   SUBJECT of the statement, not a word it meets (D50). Thus an honest interim block
   «**Фаза P5 НЕ закрыта.** Терминальны только P5.1 и P5.2» is legal and stays silent
   («терминальны» there is about ITEMS), while «**Сдано:** фаза … терминальна (3/3)» on a
   non-terminal phase → WARNING. Put the verdict about the phase on the FIRST line: the gate
   does not look below it. Whether the block's content is ACTUAL is not machine-checkable —
   that is the `plan-audit` checklist.

## 4. plan.md schema

Skeleton — `snippets/plan-template.md`. Sections strictly in this order:

- **`## 0. Meta`** — table `Поле | Значение`: Plan ID / Title / Version / Status /
  Document Type / Authoring Model / Last Updated / Repository / Related Packages /
  Execution Mode / Target Operator Models / Approval Owner. Optional fields:
  - **Home** — `brain` | `repo:<путь>`: where the plan's executable copy lives; defaults
    to the project repo (section 12, D31);
  - **visibility** — `private` | `public`, defaults to `private` when absent (D25):
    `public` → only a public roadmap extract is allowed in the git repo, the private
    business context stays in the vault;
  - **supersedes** — `<old-ID>`: the plan replaces a predecessor of the same track wave;
    the predecessor moves to `plans/archive/` (section 12);
  - **Paused By** — which plan seized ACTIVE and when; filled by plan-design mode `new`,
    which appends the field to the previously active plan.
- **`## 1. Context`** — 5–15 lines: what we do and why. Self-contained: the executor does
  NOT see the chat the plan was born in.
- **`## 2. Execution Rules`** — ≤20-line digest of execution rules + plan-specific
  deviations. The full protocol is this skill; do not copy it wholesale.
- **`## 3. Routing`** — ONLY deviations from the SSOT routing matrix (section 9). Empty
  section = defaults apply. Canonical line form (column `Exec`), the `Exec` derivation rule
  and the routing gate — section 9; legacy forms stay valid.
- **`## 4. Phase Index & Status Board`** — table `Phase | Title | Items 🟢/всего | Status`.
- **`## 5. Decision Log`** — table `D# | Дата | Решение | Почему`.
- **`## 6. Update Log`** — append-only table `Дата | Кто (role/model) | Что`. Hard limit:
  "What" ≤ 2 lines, canonical format:
  `<item> закрыт: <1 line of substance> — детали см. phases/Pn.md <item> Completion Notes`.
  Retelling Completion Notes in the Update Log is FORBIDDEN (EDUFY incident: 54KB plan.md).
- **`## 7. Contracts`** — **OPTIONAL** (only for plans that export OR consume frozen
  contracts; a missing section is not a defect). Number 7 is the canon for NEW plans, but
  `plan-lint` recognizes the section BY ITS HEADING, not by number: a plan that already
  took 7 for another section places `## N. Contracts` under a free number. Columns —
  byte-for-byte (parsed by `plan-lint`; mismatch — WARNING):

```text
  ## 7. Contracts

  ### Exported
| Контракт | Версия | Статус | Потребители | Уведомлены |
|:--|:--|:--|:--|:--|
| root/contracts/module-manifest.md | v2.0.2 | frozen | FLEX-2 (Epic 8) · ERP-IMPL (erp-03) | 2026-07-13 |

  ### Consumed
| Контракт | Донор-план | Pinned | Замечено | Реакция |
|:--|:--|:--|:--|:--|
| REGHUB/root/contracts/module-manifest.md | REGHUB | v2.0.0 | v2.0.2 (2026-07-13) | P8.2 — поднять до v2.0.2 |
```

- **`## Обсуждение`** (Discussion) — **OPTIONAL**, UNNUMBERED, the LAST section (legacy
  plans keep it numbered — recognized BY HEADING, like Contracts). Carrier of design forks;
  EVERY fork carries a MANDATORY status line (enum of section 3):

```text
  ### N — <развилка одной строкой>
  - **(рекоменд.) Вариант A.** Плюсы … Минусы …
  - **Вариант B.** Плюсы … Минусы …
  **Статус:** Resolved → D# | Decision pending (нужен владелец) | Exploration
```

## 5. phases/Pn.md schema

Skeleton — `snippets/phase-template.md`. Phase file structure:

- `# Pn — Title`
- **`## Phase Context`** — 5–10 lines, self-contained (the phase is executable without
  reading other phases).
- **`## Phase Status`** — table `ID | Title | Status | Updated`. Rows are ONLY items: the
  PHASE status lives in the plan.md Status Board (§3/§4, D51); a `Pn` row here is rejected by
  `plan-lint` with an ERROR ("row without a matching item").
- **Items.** Item heading STRICTLY `### Pn.m — Title`. Fields STRICTLY as lines
  `**Field:** value`, in this order:
  `Status, Intent, Why, Scope Included, Scope Excluded, Inputs, Files, Required Reads,
  Implementation Rules, Code Guidance, Validation, Deliverables, Completion Notes,
  Pending Work, Known Deviations, Escalation Needed`.
  Empty value — canonical «—» (not blank, not "N/A"); exception: for `Escalation Needed` —
  and ONLY for it — the marker `no` is equal in standing to «—».
  **Code Guidance is MANDATORY**: solution style, bans, allowed abstractions, required
  tests, forbidden shortcuts — what a cheap model won't infer on its own.
- **`## Phase Handoff`** — filled when the phase is closed (what was delivered, what's
  next). On a phase **reopen** the block is not deleted but invalidated with the marker
  `> УСТАРЕЛ — фаза реопенена <D#>` on its first line (§3, 4th reopen step); `plan-close`
  overwrites it at the next phase close.

**Skeleton-phase marker.** A phase deliberately left undetailed at authoring time carries
a fixed line in its Phase Context:
`> СКЕЛЕТ — не детализирован, разворачивается design-phase: task:plan-design <ID> Pn`.
With the marker, empty item fields are legal, including Code Guidance = «—». Without the
marker an empty Code Guidance is a plan defect.

**Contract-first design flow (optional authoring mode).** An alternative to per-phase "detail
one phase → exec immediately": on `new`, seal ALL phases with skeleton markers and write a
**contract block** into each `Phase Context` — what the phase consumes from earlier phases and
produces for later ones; detail items just-in-time (`task:plan-design <ID> Pn`) against the facts
of already-closed phases. Goal — see inter-phase conflicts and the coupling surface BEFORE
detailing (pre-empts the "fixing one phase breaks another" ripple) without paying the full upfront
cost of designing all items. OPTIONAL and ADDITIVE to the skeleton marker: the contract block is
not required, its absence is NOT a defect, the skeleton marker stands on its own without it.

Contract-block canon — one line in `Phase Context` (next to the skeleton marker):

```text
> **Контракт фазы (contract-first).** Потребляет: … · Производит: … · Границы: …
```

- **Потребляет** (consumes) — input from ALREADY-closed phases (artifacts, contracts, D# decisions);
- **Производит** (produces) — what the phase hands to the next (files, canon edits, D#);
- **Границы** (boundaries) — what the phase deliberately does NOT touch (handed to another phase).

**Full detailing before exec — the DEFAULT (D-canon 2026-07-16).** Contract-first skeletons are
an AUTHORING tool, not a license to start exec on a half-baked plan: by default ALL phases are
brought to DoR (refine loop §7) and approved by the owner BEFORE the first `plan-exec`/`plan-run`,
then `roadmap.md` (§8) is assembled — and execution runs in batches without babysitting.
The standard authoring pipeline is PER-PHASE: `new` (the top view: skeletons+contracts, where the
aligning questions get asked) → `Pn` one phase per pass, as SEPARATE commands/sessions — phases
are loosely coupled, the questions and the owner's approval happen inside the phase's own pass;
the sequence emerges as the passes proceed → `<PLAN-ID> finish` (final gap-closing: a cross-phase
reconcile of ALL phases — the "conflicts resolved" verdict is made only when ALL details are
visible; roadmap.md is assembled as its LAST step and only on a clean plan) → `plan-audit design`
→ exec. The single-pass variant (one strong session details everything) — only for small plans
(≤3 phases) or by the owner's explicit choice: on a large plan one stream = the highest error
risk and mixed-up approvals. `<PLAN-ID> roadmap` — a separate LIGHT rebuild of the map (a phase
changed/added, phases closed) without the full sweep; if phase contracts changed, a new `finish`
is required, not a rebuild. Just-in-time
detailing (exec starting with live skeleton markers) is allowed ONLY via an explicit D-entry with
a reason (a volatile phase whose input radically depends on earlier phases' outcomes); silently
exec'ing over skeletons = a plan-audit finding. Owner gates inside the plan (approvals of
matrices/ADRs/specs on design items) remain — full detailing removes routine manual launches, not
the owner's decisions.

**Design budget: design passes (D-canon 2026-07-16).** At `new`, right after analyzing the brief
and sizing the scope, the author declares a `## 0. Meta` field
`Design Passes: k/N — <justification>` (a registrar heuristic, N is the MINIMUM number of passes:
≤3 phases or ≤10 items → 1; 4–7 phases or 15–35 items → 2; cross-repo / ecosystem core / high
brief uncertainty → 3; the owner may raise N explicitly — plan quality is not the place to
economize, spend wisely: passes pay for themselves). A pass = a full design sweep (new/phases —
or a refine of already-detailed phases) in a NEW SESSION — the context is reset deliberately, a
fresh view catches what a stale session is blind to. Each pass ends with: k++ in Meta, an Update
Log row "Design pass k/N: what was done; what wasn't reached; the next pass's focus", and
handoff.Next — the next pass's launch-block CARRYING that focus (the new session starts from the
spots the previous one couldn't reach). The last pass ends with `finish`. `finish` with k<N —
only via an explicit owner D-entry shrinking the budget; `plan-audit design` checks the counter
against the Update Log. A missing field = N=1 (a small plan).

**Reconcile at detailing** (deterministic, cheap — the skeleton is not terminal): when expanding
phase Pn, compare its contract block's `Потребляет` with the `Производит` of the actually-closed
earlier phases. A mismatch (an early phase produced differently than the skeleton expected) →
update Pn's contract block + record a D# (`supersedes D#` when it overrides an earlier decision,
§3 "Re-design over closed phases"). Silently fitting the skeleton to fact without a D# = scope drift.

**Preventive coupling-surface enumeration** — part of the flow, NOT a separate heavy ritual: on
`new` (or first detailing) list the files/canon sections touched by ≥2 phases, in `## 5. Decision
Log` (a D# "coupling surface") or in `Phase Context`. Visible coupling pre-empts the ripple: the
author of a later phase sees its edit will hit an earlier one BEFORE writing it.

**RAG markers** on load-bearing facts in Intent/Why/Implementation Rules and in the D-log
are mandatory — schema and load-bearing definition in section 7.

## 6. Statuses

EXACTLY 6 canonical strings — copy byte-for-byte (greppable, parser is strict):

```text
⬜ Not started
🟡 In progress
🟢 Done
🟠 Done with deviations
🔴 Blocked
⛔ Skipped by decision
```

Status lives in the plan: finishing work without updating the plan = incomplete execution.
Closed an item → update `Phase Status` and the Status Board in `plan.md`.

**Status under deviations.**

```text
A non-empty Known Deviations BY ITSELF does NOT change the item's status.

🟠 Done with deviations is MANDATORY on a material deviation (any of):
- Scope Included not fully done, or done differently than prescribed;
- Validation left red (not fixed within Scope);
- public contract / SemVer / a frozen contract touched BEYOND the item's spec;
- the item's acceptance criterion not met;
- a workaround accepted that LIVES IN THE CODE/artifact (not in the execution process);
- the item commit carries a diff outside `Files` of the item (a foreign working-tree
  capture, or a file "on topic" not declared in the spec) — regardless of whether the diff
  itself is correct. NOT counted as a foreign diff (not a material deviation, need not be
  listed in `Files`): (a) `plans/<ID>/**` and `plans/ACTIVE.md` are a regular part of
  closing an item (§8: the bookkeeping commit); (b) a **deterministic derived artifact** —
  the output of a NAMED regeneration command run over the item's `Files` sources (registry
  manifests, a generated canon in another language, lockfiles, doc snapshots): regenerating
  it is a direct expected consequence of the item editing its own sources, not "someone
  else's work" (foreign-diff protection still stands — carve-out (b) does NOT cover
  non-deterministic/manual/foreign output). Do not confuse with "phase coherence of a
  derived artifact" below: that rule is about LEAVING an artifact stale; this one is about
  COMMITTING its regeneration.

🟢 Done is kept on a process deviation, but the Known Deviations entry is MANDATORY:
tier overshoot (executed with a model/effort ABOVE what Routing prescribed), commit
bundling, edit order, report style. Silent omission = defect (audit finding).

Model/effort UNDERSHOOT below what Routing prescribed is NOT a process deviation: it is
forbidden by the routing gate (§9) — such an item is not executed at all.

A defect of the SPEC ITSELF (the spec's rule is wrong or unfeasible) is likewise NOT an
executor deviation: the executor ESCALATES (§10: rule conflict / unclear acceptance), the
owner or plan-design records a new D# and SYNCS the item's Scope Included with it; the
close proceeds against the UPDATED spec → 🟢 + a Known Deviations entry (what changed, by
which D#). Editing the spec on the fly WITHOUT a D# is a material deviation (scope drift),
even if the result is "better".

A "soft Validation point" (a spec note marking some threshold/point as optional) is
FORBIDDEN: a red Validation point is ALWAYS material (see above) or, if the spec's own
threshold is wrong/inconvenient, ESCALATE to the owner for a new D# (see "Spec defect"
above) — not a silent relaxation by the executor on the spot. A "soft" knob is a path to
any inconvenient threshold being quietly voided by the very item that exceeded it
(precedent D58: an item closed 🟢 on a red point because the spec itself declared it soft).
```

**Commit gate on close.** Status `🟢`/`🟠` is NOT set without the item's diff committed:
Conventional Commits (Russian), affected package's `validate.sh` green BEFORE the commit;
no uncommitted diff for the item's Scope remains in the tree (`git status`); a diff OUTSIDE
the item's Scope is not committed — record it in Pending Work (basis D12/SKM-AUDIT-004:
4 "closed" items were marked done while their code sat uncommitted).

```text
An item is closed with TWO commits (the only topology — §8 "Item close order"; here — only
the ASSEMBLY of each, the step order is not duplicated):
- item commit        → `git add <paths from Files>`         (item's Scope only);
- bookkeeping commit → `git add plans/<ID> plans/ACTIVE.md`  (plan files only).

TWO is the topology of CLOSING, not a cap on the number of commits:
- inside STEP 1 the Scope diff may be assembled from SEVERAL commits if each is atomic in
  meaning; the item commit is the FINAL commit of step 1, and it is ITS hash that goes into
  the handoff (§8, step 3);
- a review-fix commit is ON TOP of the topology (never `amend` — traceability: it must show
  what review caught), NOT an alternative to it: the close topology is unchanged.
Neither allows MERGING steps 1 and 6 into one commit — see bundling below.

`git add -A` and `git commit -a` are FORBIDDEN: the working tree may carry a foreign diff
(the owner edits code in parallel with the agent) — it would ride into the item commit
unnoticed. A foreign diff in the tree the executor does NOT touch (does not commit, stash,
revert or "tidy up") — he NAMES it in the item's report: someone else's work is not his
subject. Basis — F1 (audit P2, 2026.07.13-PLAN-PROTOCOL-GAP): `git add -A` dragged a foreign
product file (`integrate.py`) into the item commit.

Bundling (Scope diff and bookkeeping in ONE commit) is a process deviation: 🟢 + a Known
Deviations entry — but even then the handoff MUST carry the real item-commit hash (§8,
step 3), which does not exist yet while the bundle is being assembled. A bundle is therefore
bought with a FIXING commit carrying the hash: that price is named in the report, not hidden;
a placeholder instead of a hash is a defect (basis G2: the §6/§8 disagreement already
produced the placeholder `<P2.5>`).
```

**Phase coherence of derived artifacts.** An item MAY leave a DERIVED artifact stale (the
skill's generated EN canon, the registry, a docs snapshot) if ALL three hold: (a) the phase
carries a TERMINAL regeneration item, (b) it is named in Pending Work, (c) the report
honestly states `validate.sh` is red exactly from derived drift. The commit gate on such
items is checked against NON-derived errors; a full green is mandatory on the phase's
terminal item. Regenerating after EVERY item that edits the same source publishes N
incoherent snapshots of a canon still being written. Silently closing an item while
`validate.sh` is red is not a deviation — it is a lie in Completion Notes.

**Phase DoR gate.** A phase is not moved from design to execution until Definition of Ready
passes — checklist and refine loop in section 7. A phase that went to exec with unresolved
`[NEEDS CLARIFICATION]` markers = a plan-audit finding.

## 7. Refine loop and readiness

**Marker `[NEEDS CLARIFICATION: <вопрос>]`** — the canonical way to flag an ambiguity right
in the text of the plan / an item's spec (RAG:✅ P5.1 §1 spec-kit). A disputed spot at
authoring time — either the marker or a question to the owner; an unauthorized assumption is
forbidden. The gate is binary: 0 markers.

**Phase DoR gate** (Definition of Ready, RAG:✅ P5.1 §7 Atlassian) — a fixed checklist;
plan-design must run it before declaring "phase ready", plan-audit checks it post-factum:

- [ ] All `[NEEDS CLARIFICATION]` resolved (0 markers in the phase).
- [ ] Phase Scope Included / Scope Excluded filled (Goals/Non-Goals equivalent).
- [ ] Alternatives for load-bearing decisions recorded in the D-log.
- [ ] Every `## Обсуждение` fork carries a status line; everything ≠ `Resolved` has an
      entry in `open-questions.md` (section 3).
- [ ] Load-bearing facts carry RAG markers.
- [ ] **For phases that DECLARE orchestration** (multi-agent/fan-out): a ready workflow
      script in `plans/<ID>/workflows/`, the handoff launch-block references it by
      `scriptPath` (D29). Conditional ONLY on declared orchestration — manual item-by-item
      execution needs no workflow (script home/name — section 2, authoring — `workflow-craft`).

**Criterion "a phase MUST declare orchestration"** (D16). A phase MUST set up a workflow
script (item-by-item is not legal) if EITHER signal fires:

- **N≥3 items with independent/parallelizable scope** — items don't block each other on data
  (one item's Inputs don't reference another item's Deliverables within the SAME phase) AND
  each carries deterministic Validation commands (concrete commands with a checkable result,
  not "check manually");
- **a long autonomous chain with external state** — the phase is meant to run without manual
  intervention between items for longer than one executor session (state passes through
  git+plan, not chat context).

Neither fires (items <3, or scope is sequentially coupled, or Validation can't be formalized)
— the phase stays manual, a workflow is NOT set up "just in case". The criterion is binary:
check the facts against the phase's items (count, Inputs↔Deliverables coupling, presence of
Validation commands) — do not judge "by feel".

**RAG markers in the schema.** Every load-bearing fact/verdict in an item's
Intent/Why/Implementation Rules and in the D-log carries EXACTLY ONE marker from a closed
enum of three values (D29):

- `RAG:✅ <дата/суть запроса>` — an EXTERNAL fact verified via RAG.
- `RAG:—` — a repo-grounded fact / internal rule: confirmed by reading this repo's code or
  the plan itself, needs no external verification. Basis in parentheses:
  `RAG:— (repo-grounded: plan_lint.py:88)`.
- `[UNVERIFIED]` — not checked, the decision is taken at risk.

Load-bearing = "a project decision stands on this". A load-bearing point without a marker =
schema defect, audit finding. `RAG:—` fits NO EXTERNAL fact (library version/API, "still
current/deprecated", market, another service's/MCP's capabilities) — there it is either
`RAG:✅` or `[UNVERIFIED]`; `RAG:—` on an external fact = the same audit finding.

**Refine loop** — canonical authoring contour: design → self-audit (DoR checklist) → edit →
repeat until 0 markers.

**RECOVERY on a clinch.** The refine loop above converges while a fix stays LOCAL. A CLINCH is
when a fix to one phase/item ripples into another (tightly-coupled SSOT): per-change refine
gives thrash — each local fix exposes a new mismatch; big-bang "accumulate and sort it out at
the end" hides incompatibilities until late. The convergence discipline is the middle path
(RAG:✅ P5.1 SBD/LRM/progressive-freeze, `findings/P5-design-convergence.md`):

- **A medium batch around ONE coupling hotspot** — group edits around a single coherent design
  question and apply them across ALL affected phases/sections at once (not a micro-tweak per
  change, not "the whole design in one shot"); the batch carrier is the `Batch` column of §3
  Routing (§9 "Sub-phase batching") — RECOVERY introduces no mechanism of its own.
- **Integration validation AFTER EACH batch** — the DoR checklist over all affected phases, not
  only at the very end. "Final consolidation at the end" ≠ "accumulate desync the whole design":
  without per-batch validation this is exactly the big-bang anti-pattern.
- **Final consolidation is a single pass** (a whole-check after the hotspots are settled), NOT a
  full re-design N times.
- **Progressive freeze** — a stabilized phase is frozen by its contract block (§5, contract-first);
  onward only details, no new contracts (reconcile `Потребляет`↔`Производит` when detailing later
  phases, §5).
- **Empty feasible set → drop the variant, don't patch** (SBD): if no hotspot edit reconciles the
  phases, the variant is structurally non-viable — remove it via `## Обсуждение`+D#, don't pile up
  crutches around it.
- **Decomposition strategy on a clinch** — escalate to Perplexity (RAG delegation), not
  "by-feel" improvisation.
- **Stop criterion:** 0 open high-severity mismatches between phases AND all coupling hotspots
  reconciled — onward only editorial edits. The AUDIT loop (audit round cap, §13/`plan-audit`) is
  closed separately — RECOVERY closes the DESIGN refine loop.

**Pre-mortem for high-stakes** (fiscal/payments/tenant-isolation/ecosystem core): before
closing a design phase — a round per the Klein protocol (RAG:✅ P5.1 §7, prospective
hindsight +30%): "the plan failed 6–12 months out — why"; independent recording of causes →
clustering → mitigations with an owner in Risks/D-log.

## 8. Handoff standard

`plans/<ID>/handoff.md` — rolling pointer, OVERWRITTEN on every item/phase close (version
history lives in git, not in the file). Skeleton — `snippets/handoff-template.md`. Fields:

- Heading — two forms (any other = `plan-lint` ERROR; D30):
  - `# HANDOFF — <ISO-date> — after Pn.m` — an item is closed (addressee = the last closed item);
  - `# HANDOFF — <ISO-date> — after Pn` — a PHASE was closed/audited/re-designed, i.e. a step
    with no "last closed item" as addressee (`plan-close <ID> Pn`, `plan-audit <ID> Pn`,
    `plan-design <ID> Pn`).
  - For the `after Pn.m` form the **phase `Pn` MUST exist in the plan** (declared in the Status
    Board OR having a `phases/Pn.md` file) — otherwise ERROR "dangling reference" (D37/D41). The
    only exception — the bootstrap heading `after P0.0` of the starting handoff (there is no
    phase `P0`). The existence of the ITEM `Pn.m` itself is NOT gated (fleet back-compat).
- **Next:** a semantic carrier from the closed enum below; render provider invocation separately.
- **Done:** what is closed. · **Remaining:** what is left.
- **Sources of truth:** where the truth is (plan.md, phases/Pn.md, key files).
- **Open risks:** open risks.
- **Workarounds/Deferred/Open questions:** keys match run-report
  `devloop{workarounds,deferred,open_questions}` — cross-checkable with telemetry.

**Launch-block.** Every "what to launch next" output — handoff.Next and the finals of
plan-exec/plan-close/plan-audit reports — must take the fixed form
(`snippets/launch-block-template.md`):

```markdown
**Next:** <kind>: task:<plan-command> <PLAN-ID> <arguments>

| Параметр | Значение |
|:--|:--|
| Model class | <economy / implementation / frontier> |
| Effort | <low / medium / high / xhigh> |
| Capabilities | <semantic capability IDs; `—` if none are additional> |
| Context | <одно из трёх значений enum'а ниже> |
| Суть | <1 строка> |

````
<provider renderer: Claude `/task:…` OR Codex `$ task:…`; preserve semantic payload>
````
```

**Self-sufficiency (cold-start).** The launch-block command must work from a cold session:
plan-ID + input-doc paths (cold-start reads) + an embedded gate approval if the execution is
gated. References like "this session" — only as a supplement. `plan-lint` checks: handoff.Next
contains the launch-block table and a code block (greppable; for date-based plans from
2026.07.16 a missing launch-block is an ERROR, older/legacy — WARNING).

**Scope-payload: a launch-block CARRYING FINDINGS (D-canon 2026-07-17).** A command that hands
findings to the next session — a `plan-audit` verdict `RED`/`ATTENTION` (§3) and a C3/D6 fix
proposal — MUST NOT be bare. Four lines follow it inside the same code block (order fixed):

````
<provider-rendered invocation>

РЕЖИМ: <reopen per §3 (4 steps) | detailing | remediation — what the session actually does>
ВХОД: <file → block with the findings; cold-start read>
СКОУП: <numbered list: what to do>
НЕ ТРОГАТЬ: <anti-scope: what to leave as is>
````

Why each line:

- `РЕЖИМ:` — `design-phase: task:plan-design <ID> Pn` is AMBIGUOUS: a fresh session seeing a phase with
  terminal items decides "reopen or detailing" by itself, and errs toward the expensive one.
- `ВХОД:` — enforces the cold-start rule above ("input-doc paths"): without it the payload
  lives only in chat and dies with the session. Same class as "debt lives only in handoff":
  handoff is a rolling pointer, and at hand-off time it still points at the PREVIOUS step,
  i.e. actively misdirects the receiving session away from the findings.
- `СКОУП:` / `НЕ ТРОГАТЬ:` — the only thing holding the session back from re-developing what
  is already closed. The anti-scope is mandatory precisely because the expensive mistake here
  is not "does too little" but "redoes everything".

Grounding incident: the P0 audit of plan `2026.07.16-INS-EVO` (2026-07-17) emitted the
launch-block `/task:plan-design <ID> P0` with no payload — the owner read it as "they propose
re-developing the phase"; `plan-audit.md` itself prescribed exactly the bare command, even
though the cold-start rule already existed. What was missing was the FORM and the GATE, not
the doctrine.

**The payload's carrier is the on-disk report.** `plan-audit` puts the launch-block as a
`### Что запускать` subsection at the END of its `## Audit Pn` block (§3) — same single
permitted write, same file. A chat-only launch-block is machine-uncheckable by design; on disk
`plan-lint` gates the presence of the table, the code block and all four lines (date-based
plans from 2026.07.16 — ERROR, older/legacy — WARNING). Presence ≠ quality: the substance of
`СКОУП:`/`НЕ ТРОГАТЬ:` is not machine-measured — that is the next audit's report-quality call.

**The verdict line is a literal.** An audit declares its verdict with the line
`**Вердикт: <GREEN|ATTENTION|RED>**` in its `## Audit Pn` block and writes it VERBATIM: the values
are latin, the header `Вердикт:` is not. That is the line the gate finds the verdict by. A free-form
variant («Verdict: RED», «Итог: RED») does not fire the gate — silently — and the scope-payload
stops being required at all: this is the "gate greens on a blank" class the literal was canonized
for (registry `CANON_LITERALS`, directive `i18n-keep`). An `## Audit …` block WITHOUT a `Вердикт:`
line is a free-form note, not an audit report: it is not gated (otherwise the linter would demand a
payload from an arbitrary `## Audit` heading). A `GREEN` verdict carries no findings and requires no
payload.

**The session's final message = a launch-block.** Any `task:plan-*` session (design / exec /
run / close / audit) that has a next step ENDS its last message to the owner with a full
launch-block (semantic Next + Model class/Effort/Capabilities/Context/Essence + a provider-
rendered invocation in a separate code block). Invocation without semantic Next/route is a defect.

**Execution roadmap — `plans/<ID>/roadmap.md` (D-canon 2026-07-16).** Assembled after all phases
are fully detailed (§5 default) — the plan's execution map answering "which command next?" without
re-analyzing the whole plan and without the owner idling at the monitor. It is assembled by
`plan-design <ID> finish` as its LAST step (only on a gap-free plan); later rebuilds (phases
changed/closed) — the light `plan-design <ID> roadmap` mode. Skeleton —
`snippets/roadmap-template.md`. Contents:

- a table of ALL items in execution order: `Item | Batch | Запуск | Model/Thinking | Гейт
  владельца | Примечание`;
- **Batch** — a group of consecutive small same-model items with coupled scope, executed by ONE
  session with one carrier (`run-items: task:plan-run <ID> Pn.a-Pn.b` — a series); a large/complex/risky
  item — `solo` (own session); scope-INDEPENDENT items — `workflow` (a script in
  `plans/<ID>/workflows/`, one launch). For solo items the "why it can't be merged" justification
  is mandatory (an empty cell = an audit finding);
- **Owner gates** — only where the decision is genuinely the owner's (matrix/ADR/spec approvals,
  high-risk steps); everything else runs without stops;
- a ready launch-block for every batch/workflow group.

Consumers: `plan-exec`/`plan-run`/`plan-close` sessions check their final launch-block against the
roadmap — if the next step belongs to a batch, the BATCH command is offered, not a single item;
the owner's "what's next?" = reading roadmap.md + handoff.md. A roadmap↔Routing§3 mismatch is a
defect (the roadmap does not override models — it only groups launches). Gate: `plan-lint` — all
phases detailed (no skeleton markers) while `roadmap.md` is missing → ERROR for date-based plans
from 2026.07.16 (older/legacy — WARNING); `plan-audit design` checks item coverage and batch
justification. A living document: phase closes / re-designs update the roadmap (an Update Log row).

Under a master plan the general
session handoff (skill `session-handoff`) collapses here — no second handoff file.

**Allowed `Next` forms** (closed enum, m8/B1/N1/N2 + D8 — `plan-lint` accepts EXACTLY these 11
forms; any other form = ERROR listing the allowed ones):

```text
| Форма Next | Когда |
|:--|:--|
| exec-items: task:plan-exec <ID> Pn.m [Pn.k …] | detailed next item; item list is one-session batch |
| run-items: task:plan-run <ID> Pn.m[-Pn.k] | manual items under a verified inherited-plan route |
| audit-design: task:plan-audit <ID> design | pre-exec design audit |
| design-phase: task:plan-design <ID> Pn | next phase is a skeleton |
| design-item: task:plan-design <ID> Pn.m | escalated/re-design item |
| audit-phase: task:plan-audit <ID> Pn | terminal phase audit |
| close: task:plan-close <ID> Pn[.m] | close phase / reconcile item |
| archive: task:plan-close archive <ID> | whole plan is terminal |
| terminal: план закрыт | no protocol work remains |
| manual: <model-class/effort> | no invocation; carry route and the bare item prompt |
| waiting: <condition> → <target semantic form> | real step is not executable yet; not defect/terminal |
Иная форма = ERROR plan-lint.
```

New plans store the semantic form. Historical `/task:…`, `ЗАПУСК ВРУЧНУЮ`, and `ОЖИДАНИЕ:`
remain compatibility inputs. Render Claude `/task:…` or Codex `$ task:…` from the same carrier;
never alter PLAN-ID, item IDs, cold-start reads, scope payload, or owner gate.

**The `Next` form is declared by the FIRST non-empty line of the field** (D40). The field is
multi-line (it runs to the next `**Field:**` and includes the launch-block), so the resolver
reads the form from the declaring line, and only on failure — by a grep over the whole field
(fallback, back-compat). Practical point: the launch-block QUOTES commands and enum forms (for a
manual item — a whole bare prompt), and a quote in the «Суть» cell would hijack the resolver into
a false ERROR. Declare the form on the line `**Next:** …` — below it the form gate does not look.

**Item close order** (canonizes B1/F11/M2/A4 — repeat offenses of "dishonest" lint):

```text
TWO commits — the only topology of an item. The ASSEMBLY of each (`git add` by explicit
paths, `-A`/`-a` banned) — §6 "Commit hygiene", not duplicated here.

1. Scope diff (code/artifacts) ONLY by the `Files` paths → package `validate.sh` green →
   ITEM COMMIT. Plan files do NOT go into it.
2. Plan bookkeeping: Status, Completion Notes, Pending Work, Known Deviations, Phase Status,
   Status Board, Update Log; in `plan.md` — `Last Updated` (the item's close date). There is
   NO machine gate on `Last Updated` (a stale field = bookkeeping lagged, not a finding):
   the `plan-audit` checklist verifies it.
3. Rewrite handoff.md (Next — a form from the enum above). The handoff takes the item
   commit's hash FROM STEP 1 — that commit is already in history
   (`git rev-parse --short HEAD`), so the hash is REAL. A placeholder instead of a hash
   (`<Pn.m>`, "TBD", an empty cell) is a defect, not a form.
4. plan-lint — AFTER step 3, on the final tree. Running it BEFORE the handoff rewrite is
   FORBIDDEN: the closing item itself changes the handoff, so a "before" count does not
   describe the state of the commit.
5. The count is published WITH ATTRIBUTION: «N ERROR / M WARN — новых от диффа item'а: K»
   (K deterministically: plan-lint --baseline HEAD).
   • K > 0 — fix BEFORE the bookkeeping commit;
   • N − K > 0 (residual plan errors outside the item's Scope) — NOT hushed up: a line in
     the item's Pending Work + the handoff's Open risks.
6. BOOKKEEPING COMMIT: plan files only (`plans/<ID>/**`, `plans/ACTIVE.md`).

"Green lint" for the close gate = K = 0 (no NEW errors), not N = 0.
An item with no code diff (Scope = the plan itself) follows the same topology: step 1 commits
the item's `Files`, step 6 — the rest of the bookkeeping.
Merging steps 1 and 6 into one commit (bundling) = process deviation §6: 🟢 + a Known
Deviations entry + a fixing commit carrying the real hash in the handoff.
```

**Tree anchor for numbers in reports.** ANY NUMBER in an item's report (Completion Notes,
Validation, Known Deviations, Update Log) — a `grep` count, an ERROR/WARN count, a count of
commits/files — is given with an explicit **tree anchor** on which it is reproducible:
`<число> (на <git-ref>)`, plus the command that produced it. The default anchor is the item's
**COMMITTED tree**, not the tree at the moment the report was written:

```text
A number about the Scope diff (code/artifacts) → anchor = the ITEM COMMIT (step 1): it is
  already in history, bookkeeping does not change it — the command reproduces as is.
A number about PLAN files (greps over phases/Pn.md, lint counts) → anchor = the BOOKKEEPING
  COMMIT (step 6): the report itself changes it. Count on the tree you are COMMITTING (after
  `git add` of all step 2–3 edits), not on the pre-edit tree; if it diverged after the commit
  — fix it with a fixing commit, do not leave it.
```

A number without an anchor, or one not reproducible by its own command on the committed tree,
is a **FALSE STATEMENT** (class B1) — even if it was true when written. No machine gate — the
check lives in the `plan-audit` checklist.

**Numbers OUTSIDE this repo — only the DELTA of a paired run** (D53). A foreign root (another
repo's `plans/`, the fleet) lives its own life and mutates between runs: the tree anchor does
not extend to it — a `git-ref` of your own repo does not pin a foreign tree. So a
regression/back-compat claim over foreign roots is proven ONLY by a pair of runs on ONE snapshot
of the foreign tree (`<ref-before>` → `<ref-after>` of YOUR repo, the foreign one untouched) and
published as the **delta** of diagnostic sets: `ERROR Δ 0 · WARN Δ −1 (снят ложный …)`. An
absolute count of a foreign root («ERROR 116 → 116») is NOT proof: it does not tell the foreign
repo's drift from your edit's regression. Delta ≠ 0 — either a true finding of a new rule (name
it by name) or a blocker.

**"item/phase → handoff → context boundary" cycle:** the next step reads plan.md →
phases/Pn.md → handoff.md rather than relying on the chat tail. A provider may project
`same-session` into its native context-clear action; that action is not plan semantics.

**Session-restart discipline (launch-block Context field, D16 + D30).** The Context field
carries EXACTLY one of three values — not a static generic "clear recommended" regardless of
the next step's type:

- **`same-session — item`** — regular item-by-item exec; the provider chooses its native
  mechanism for refreshing context.
- **`cold-start — orchestration`** — an orchestrated chunk. Its carrier stores `kind`,
  `orchestration_id`, ordered inputs, required capabilities, and a provider-projection reference.
  Require `command.subagents` and `command.workflow-carrier`; missing/unknown/unsupported support
  returns `CMD_CAPABILITY_UNSUPPORTED`, with no silent solo fallback.
- **`cold-start — plan-step`** — not item exec but a protocol step ABOVE
  the plan (`plan-audit` / `plan-close` / `plan-design`): it re-reads the plan from disk (an
  audit is also adversarial — inheriting the executor's context is forbidden).

**An item with `Exec = manual` (§9) — semantic `manual` form.** Such an item has no
command, therefore the launch-block:

- `Next` = `manual: <model-class/effort>`;
- `Model class`/`Effort` — as PRESCRIBED by Routing (§9);
- the code block carries the item's **BARE PROMPT**, NOT a provider invocation;
  `task:plan-exec` has minimum `implementation/medium` and must reject undershoot;
- `Context` — from the same enum of THREE values, by the type of the next step. A manual item
  is regular item-by-item exec under the prescribed route ⇒ `same-session — item`.

This is the ONLY place where a manual item's `Context` is defined; `snippets/` quote it and
introduce no rules of their own.

**The `Next` `waiting: <condition> → <target semantic form>` (D8).** The next step is REAL but
NOT executable RIGHT NOW — a HEALTHY wait on external time/state (time-gate, baseline/usage-trend
accrual, a cross-plan gate not yet terminal), NOT a plan defect. Do not confuse: `🔴 Blocked`/§10
escalation = a DEFECT (plan diverged from code, needs re-design); «план закрыт» = TERMINAL
(nothing left to do); «ОЖИДАНИЕ» = a pause before a real step.

- `Next` = `waiting: <condition> → <target semantic form>`, where the **condition is STRUCTURAL**: a
  date `≥ГГГГ-ММ-ДД`, or a gate `<PLAN-ID> Pn терминальна`, or `usage-trend ≥N недель` (not prose
  "someday later"); the **target form** — any runnable enum form above (usually
  `exec-items: task:plan-exec <ID> Pn.m`);
- the launch-block carries the **target carrier** and provider projection, and its
  FIRST code-block line is the **guard `# НЕ раньше: <условие>`** (copy-pastable and honest about
  present non-executability); `Model class`/`Effort`/`Context` — of the TARGET step;
- do NOT write a prose caveat on the declaring line (`exec-items: … — НО не раньше …`): it does
  not copy-paste, and `plan-lint` flags it WARNING (C2), suggesting this form instead.

`plan-lint` resolves the form as `waiting` and requires a launch-block.

**Surfaced error → fix proposal (C3, D6).** A run exposes a material problem (Validation red
outside Scope · lint remainder N−K>0 · stray diff · a found defect) → the report/handoff MUST
carry a concrete remediation proposal right away (what + a launch-block for the fix + 1-line
"why"), not a passive "ready for next, there were errors" note; it ADDS to the Known
Deviations/Pending Work entry, not replaces it. When the fix is the most valuable next action,
`Next` points to it (`design-item: task:plan-design <ID> Pn.m` or a follow-up fix item), not silently to the
next item. The launch-block of such a `Next` carries the **scope-payload** (§8: `РЕЖИМ:`/`ВХОД:`/
`СКОУП:`/`НЕ ТРОГАТЬ:`) — a proposal without it hands the next session the problem but not its
boundaries. Do not confuse with §10 escalation (🔴 = plan↔code defect, needs re-design): here the
item still closes (🟢/🟠) with an explicit proposal, not a block. Report-quality — checked by
`plan-audit`, no machine gate.

## 9. Routing & Thinking Policy (SSOT matrix)

Default role→neutral model-class/effort routing. Plan section `## 3. Routing` stores ONLY deviations from
this table:

| Role | Model class/effort | Works in |
|:--|:--|:--|
| issue-planner | frontier/high | plan-design |
| invariant-auditor | frontier/xhigh | plan-audit |
| package-implementer | implementation/medium | plan-exec |
| small-implementer | economy/low | LOW slices of dev-loop |
| repo-explorer | economy/low | repo recon (grep/read/map) |
| docs-curator | implementation/low | plan-close |

Class order: `economy < implementation < frontier`; effort independently:
`low < medium < high < xhigh`. Select a provider selector only from a verified mapping.
Gate BEFORE status mutation/work/write/external call/subagent launch: exact/overshoot are legal
(`CMD_ROUTE_OVERSHOOT` info); undershoot returns `CMD_ROUTE_UNDERSHOOT`; unknown/missing/
ambiguous selectors or effort block without fallback. One axis never compensates the other.
Required capabilities accept only `native`/`adapted`; otherwise `CMD_CAPABILITY_UNSUPPORTED`.

**Thinking policy:**

- effort **high+ MANDATORY** for: decomposition, conflicting requirements, invariants,
  SemVer-public-API, final verification.
- effort **low MANDATORY** (thinking is harmful — burns tokens for nothing) for:
  grep/read/map, mechanical edits, status updates, docs sync, handoff formatting.

**Tier policy (MODEL-TIER criterion — an axis INDEPENDENT of effort):**

- **frontier** — only where the item still has OPEN design decisions OR the cost
  of a semantic mistake is irreversible/gets frozen (freeze mechanism, breaking default
  semantics, fleet canon, cross-phase synthesis);
- **prescribed implementation** against already-pinned canons (RAG verdict, frozen spec, a
  ready D# decision) → implementation/high;
- **editorial/mechanics** with deterministic gates → implementation/medium (by derivation below —
  `plan-exec`);
- "effort high+ MANDATORY" does NOT raise the model tier: a SemVer item against a frozen spec
  = implementation/high, not frontier/high. Tier overshoot PRESCRIBED by Routing is as much a plan defect
  as executor undershoot: it burns frontier on implementation-class work (historical Claude case — azguard
  D28, 2026-07-18: a blanket fable/high row over 10 heterogeneous items, 7 of 10
  over-provisioned by 1–2 tiers).

**`## 3. Routing` line form** — canon for NEW plans (emitted by `plan-design`). The full form
carries TWO OPTIONAL columns — `Batch` (id of a subphase group for one session, «Subphase
batching» below) and `Review` (review depth of the subphase, enum `full`|`light`|`none`; default
`full`, doubt → `full`):

```text
| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| B1 | P2.7–P2.9 | frontier/high | manual | full | расфриз public-контракта: открытые design-решения → frontier; effort high+ MANDATORY |
| B1 | P2.10–P2.13 | implementation/low | plan-exec | light | doc-cleanup без новых решений |
```

Columns `Batch`/`Review` are **OPTIONAL**: the legacy form without them
(`| Items | Model class/effort | Exec | Почему |`) stays VALID (back-compat, below), their absence is
NOT a defect. `Batch` empty → the subphase runs solo (not in a batch); `Review` empty → depth by
default (`full`).

**Granularity.** A range row (`P2.x–P2.y`) is legal only for HOMOGENEOUS items: one risk class
per Tier policy, one "Почему" phrase true for EVERY item of the range. When a phase gets
detailed, the Routing of its items MUST become per-item if homogeneity does not hold; a
phase-level blanket row is legal only as a draft for phases NOT yet detailed. The gate is the
`plan-design` DoR checklist (there is no machine lint on §3 — Back-compat below).

Column **Exec** — closed enum `plan-exec` | `manual` | `plan-design`: who is allowed to execute
the item. Order: `economy < implementation < frontier`, effort
`low < medium < high < xhigh`. The `task:plan-exec` minimum is **implementation/medium**.

**Exec derivation** (column empty) and **consistency** (column filled):

```text
Compare by tier, both axes: overshoot is harmless, undershoot is forbidden.
- model class ≤ implementation AND effort ≤ medium → plan-exec
- model class > implementation                     → manual
- effort > medium (high/xhigh/max)   → manual  (thinking undershoot forbidden — policy above)
- cell without model / without effort → the missing half comes from the role matrix above
Semantic capability IDs use their own cell/launch-block and SUPPLEMENT route, never replace it.

A filled Exec overrides derivation ONLY toward strictness: `manual` is legal for any
model/effort pair. The reverse (`Exec = plan-exec` where derivation yields `manual`) is a PLAN
DEFECT, not a permission: otherwise the column is a hole in the gate — exactly F7.
```

**Routing gate** — run by `plan-exec` BEFORE any work (incident basis — F7 of plan
2026.07.13-PLAN-PROTOCOL-GAP: historically contract items went to Claude sonnet against a fable/high mandate):

```text
- Exec = manual (given OR derived) → the item is NOT executed by the command: STOP, report
  «ROUTING-BLOCKED: Pn.m — предписано <model-class/effort>, минимум task:plan-exec implementation/medium»,
  launch-block in the «ЗАПУСК ВРУЧНУЮ» form (§8). The item's status is NOT changed.
- Exec = plan-exec, Routing BELOW the minimum (economy and/or low) → execute (overshoot is
  harmless), process deviation → Known Deviations; status stays 🟢 (§6).
- Exec = plan-exec CONTRADICTS model/effort → STOP, report «ROUTING-INCONSISTENT: Pn.m —
  Exec=plan-exec при <model/effort>»; `plan-design` fixes it. Status NOT changed.
- Exec = plan-design → the item is not executed: STOP, re-design (§10).
- Routing is ambiguous (no row, multiple classes/efforts, prose without a class) AND the
  item is of the design/contract class (any §10 escalation trigger would fire) → STOP
  (fail-safe: a false stop costs one restart, a false pass costs a RED audit).
```

**What EXECUTES an `Exec = manual` item.** Two equal paths, both under the route PRESCRIBED by
Routing: (a) the item's BARE PROMPT from the launch-block (§8, `manual` form);
(b) semantic `task:plan-run <ID> Pn.m` (§13), routing mode `inherited-plan`: the provider
sets session selector/effort, the command inherits them and INVERTS the routing gate — it compares
the ACTUAL session class/effort against §3
Routing and on an undershoot emits the same `ROUTING-BLOCKED`. The honesty boundary is named
explicitly: an agent's route self-attribution is NOT machine-checkable, so `plan-run`
removes provider-native switching ritual before a series of manual items, but the HARD gate stays with
`plan-exec` (its pin makes an undershoot structurally impossible). A `plan-run` executor MUST
print the actual class/effort pair in the report and in the Update Log.

**Back-compat.** Legacy `## 3. Routing` forms (prose, bullets, a table without the
Exec/`Batch`/`Review` column, an empty section) stay VALID; `plan-lint` does not parse section 3 —
the gate is run by the READING EXECUTOR, not by regex. A legacy row's Exec is derived by the rule
above. The new `Batch`/`Review` columns are likewise not parsed by the linter (no machine gate on
them).

**Subphase batching (optional exec-plan "console").** A batch — a group of subphases executed in
ONE executor session without a per-subphase confirmation ritual (small subphases don't run
handoff→`/clear` between each other). The batch carrier is the `Batch` column of §3 Routing (group
id, e.g. `B1`), NOT a separate `exec-plan.md` file: a second carrier of routing/status would
duplicate §3/Status Board and drift from them (the "second carrier of a fact" ban, §3).
§3-as-full-board of subphases is opt-in: a plan without batching keeps the legacy §3 form.

- **Merge-into-batch criterion** (all three): low subphase coupling (one's Inputs do NOT wait on
  another's Deliverables WITHIN the batch) + compatible `Model/effort` and `Exec` + compatible
  `Review` depth.
- **Each subphase STILL carries its own command/model** (its own §3 Routing row): the batch flow is
  convenience, not a loss of granularity. Falling off the flow (error, §10 escalation, model change)
  → the remaining subphases are launched one-by-one by hand (each subphase's launch-block is valid on
  its own).
- **Order within a batch is sequential** (mechanism already exists: `plan-exec` "several items in the
  arguments — execute sequentially, each through the full cycle"); closing EACH subphase — full §8
  protocol (item-commit + bookkeeping + handoff), a batch does NOT merge closings.
- **"Result > economy" invariant (hard):** batching NEVER merges a `Review=full` subphase into a flow
  that suppresses its review. A batch inherits review depth = the **MAXIMUM risk of participants**: one
  `full` in the batch → the whole batch is reviewed at its depth. Classes that are always `full` (e.g.
  correctness-critical, public-contract/SemVer, tenant-isolation, fiscal/payments,
  canon-the-fleet-follows) are NOT batched under "skip confirmation" — economy that degrades the
  result is forbidden.

**Adaptive review depth (fills the `Review` enum).** Slice-risk classification → review
depth; a PRINCIPLE, not a mandate (the canon neither requires review on every slice nor
forbids it — it gives a criterion). Default `full`; `light`/`none` — only with an explicit
`Почему` justification; doubt → `full` (fail-safe, same as the routing gate above: a false
`full` costs an extra review, a false `none` costs a RED bug in prod).

- **`full`** — correctness-critical, public-contract/SemVer, tenant-isolation,
  fiscal/payments, canon-the-fleet-follows, migrations, security-sensitive.
- **`light`** — localized logic with green deterministic tests, moderate risk.
- **`none`** — cosmetics (docs, comments, formatting, purely mechanical status updates).
- A slice doesn't fit any class unambiguously → do NOT guess toward cheaper: `full`, name
  the ambiguity in Completion Notes.
- **D6 invariant:** `full` classes are always `full` — never batched or eased under "skip
  confirmation".
- A batch inherits review depth = the MAXIMUM risk of participants — see "Subphase batching"
  above (a link, not an override: one rule, one carrier).

## 10. Escalation (cheap-model protocol)

Triggers — closed enum:

- multi-package refactoring
- unclear architecture
- public contract/SemVer
- protected generated assets
- rule conflict
- unclear acceptance

On trigger the executor leaves THREE greppable artifacts:

1. Blockquote in the item:
   `> 🔴 ESCALATION Pn.m: <причина> | trigger: <из enum> | нужен: plan-design`
2. `**Status:** 🔴 Blocked` on the item.
3. Final report line: `ESCALATION-REQUIRED: Pn.m — <причина>`.

Plan diverged from code → do NOT improvise, escalate: plan-design fixes the plan, not the
executor on the fly.

## 11. Cross-plan patterns

**Cross-plan gate** (precedent AIRESTO ERP-GATE-01): a plan's dependency on another plan is
formalized as a decision doc with a precondition table; every row references the EXACT Status
Board row ID of the donor plan. Reading is one-way: the consumer reads the donor; the donor
does not know about the consumer and is not edited.

**Cross-plan notification (back channel).** A notification the executor "must remember" never
happens (grep precedent: the donor bumped a frozen contract to v2.0.2 — ZERO mentions on the
consumer side). The carrier is section `## 7. Contracts` (§4); the addressee is a concrete item:

- An item bumping a **frozen** contract (semver bump) MUST carry in its Deliverables: (a) an
  update of the donor's `Exported` row (Версия + Уведомлены) and (b) an update of the
  `Consumed` row in EVERY consumer plan IN THE SAME `plans/` root (columns `Замечено` +
  `Реакция` — the item that will raise the pin). Not "the plan's" duty — the ITEM's: an
  obligation without an addressee is a note to self.
- A consumer in ANOTHER repo is not checked by the linter — the boundary is named honestly: an
  entry in the donor's `open-questions.md` + a line in its handoff's Open risks. Cross-repo push
  is the bus's job (mAInd), not the plan protocol's.
- `plan-lint` in root mode compares the consumer's `Consumed.Pinned` with the donor's
  `Exported.Версия` in the same root → **WARNING** on mismatch (the mechanism is young; ERROR
  would break back-compat). The link is built FROM the consumer (its `Consumed.Донор-план`) —
  the only deterministic direction; the donor's `Потребители` column is human-readable.

**Program plan** (precedents FLEX-IMPL, ECOSYSTEM P4): a coordination plan over delegates —
`Document Type: Program Master Plan (coordination)`; items = milestone delegates (one delegate
plan per item); the item's Validation = a GREEN audit of the delegate; NO code is written in a
program plan. Such a plan's Execution Rules must forbid `task:plan-exec` and name the
prescribed orchestration carrier.

## 12. Lifecycle: two homes, archive, documentation

**Two-homes model** (RAG:✅ P5.1 §2 docs-as-code: git=SSOT, hub=display/index; no external canon
found specifically for Obsidian↔repo two-way — the mechanism is our `brain sync`). The plan's
SSOT is determined by its stage:

- **Home defaults to the project repo** (D31): the plan lives where its deliverables are (repo's
  `plans/<ID>`, Meta `Home: repo:<путь>`); Brain keeps the program/cross-project plan whose
  deliverables are spread across several repos;
- **at exec start** — the repo copy becomes SSOT; the Brain mirror is synced via the `brain sync`
  channel (frontmatter `repo:` — the same mechanism as docs/);
- **during exec** — SSOT = repo; silent edits to the base plan in Brain are FORBIDDEN — only via
  the conflict protocol; default sync direction is repo→Brain (mirroring);
- **after completion** — sync back → archive → migrate root/ into docs.

**Conflict protocol.** The base plan changed during execution → the executor does NOT merge
silently: record a `temporary divergence` in the current item's Known Deviations → escalate per
section 10; the owner decides — accept into the working copy as a new D# or defer to the end of
the phase.

**Skills — same principle:** the skills' SSOT is always swissknifeman; vendor copies are updated
only by `skiller vendor/connect`; a local edit of a vendor copy = defect, the path is a
contribution to the SSOT (see `package-contribution-protocol`).

**Plan→archive→docs pipeline** (RAG:✅ P5.1 §2 Diátaxis + Oxide "committed = implemented"). On
plan terminality — carrier `archive: task:plan-close archive <ID>`:

1. The plan moves to `plans/archive/<ID>` as a SEPARATE commit `chore(plan): archive <ID>`.
2. Project docs are assembled from `root/` by Diátaxis types: architecture/philosophy →
   explanation; data-model/contracts → reference; phase guides → how-to.
3. A migration checklist (what moved to docs, what stayed in the archive) — in the final handoff.

**Plans are NEVER deleted.** A terminal plan moves to `plans/archive/<ID>` and is kept forever
(decision provenance: D-log, Completion Notes, Known Deviations — the only source of "why it was
done this way" months later). `rm`/`git rm` of a plan directory is a defect, not a protocol
operation; a superseded predecessor also goes to the archive, not the trash. The only exception —
an explicit owner order with a `--force` flag in the command (the agent never proposes deletion
on its own).

## 13. Command protocol

| Semantic command ID | Model class/effort | Does |
|:--|:--|:--|
| `task:plan-design` | frontier/high | new plan / phase / item specs; refine loop + DoR gate |
| `task:plan-exec` | implementation/medium | execute an item; self-closing |
| `task:plan-run` | item minimum; observed SESSION route | execute `Exec = manual`; inherited-plan gate checks both axes before work |
| `task:plan-close` | implementation/low | status reconciliation / phase close / `archive` |
| `task:plan-audit` | frontier/xhigh | adversarial phase audit; GREEN/ATTENTION/RED |

Provider mappings project minima to native selectors. `plan-run` has no fixed route: its minimum
comes from item Routing and the same fail-closed gate checks the observed session route. Claude
invocation is `/task:…`; Codex is `$ task:…`; UI prefix is not semantic identity.

## 14. Plan-quality checklist

- [ ] Every item executable by its assigned model class with no context beyond the plan.
- [ ] Required Reads finite and ordered (not "look around the repo").
- [ ] Code Guidance filled for every item (not «—»).
- [ ] An undetailed phase carries the skeleton marker — not "just empty fields".
- [ ] Load-bearing facts/verdicts carry a marker from the §7 enum: `RAG:✅` · `RAG:—`
      (repo-grounded ONLY) · `[UNVERIFIED]`.
- [ ] Open Questions don't "hang" in phase bodies — moved to `open-questions.md` with a status.
- [ ] handoff.Next contains semantic carrier + neutral route/capabilities + rendered code block.
- [ ] Phase-first: phases close sequentially, items don't jump between phases.
- [ ] `plan-lint` green.

## 15. Per-project deployment

Plans live IN THE PROJECT, not in `~/.claude/plans/`: top-level key `plansDirectory: "plans"` in
the project's `.claude/settings.json` (shared, committed). Rollout — 3 layers:

- `harness hooks --target <проект> --plans-dir plans` — targeted knob (a key already set to a
  different value is respected — not overwritten);
- `maind sync --only plans-dir,plans-doc` — fleet of onboarded projects (opt-out:
  `project.plans_dir="off"`); `skiller integrate` — new onboardings (default "yes").

The convention reaches the project via the managed CLAUDE.md block `maind:plan-conventions`
(provisioner `plans-doc`). Bridge from built-in plan mode: the approved plan is saved as a flat
draft in `plans/` → `task:plan-design from-draft <файл>` expands it into `plans/<ID>/` per this
skill's templates (draft deleted, history in git).

## 16. The plan's knowledge layers: brief/ → findings/ → research/ → decisions

Layer canon. The "input → raw → synthesis → decision" chain MUST be reconstructible from the
plan's files WITHOUT access to the authoring session's chat — otherwise later plan work
repays the cost of reconstructing "where did this come from", and the owner's/model's
thinking gets lost.

0. **Layer 0 — the owner's input (`brief/`, MANDATORY, plan-lint gate):**
   `brief/00-brief.md` — the owner's original message reformulated WITHOUT loss of thought
   (not a digest: every thought is kept, only the form changes); everything clarified along
   the way (answered questions, resolved forks, scope changes) is APPENDED as dated blocks
   (`brief/01-refinements.md` or new files). The layer's job: the plan shows "what was the
   input → what we arrived at" — which is also the material for improving the planning
   process itself. Without a non-empty `brief/*.md` the plan does not pass approval
   (date-based plans from 2026.07.16 — linter ERROR).
1. **Layer 1 — external raw (`findings/`):** everything obtained from external sources while
   working on the plan — RAG extracts (source URL + verification date + verdict), subagent
   recon reports, digests of source documents (emails/specs; binary attachments live next to
   them, e.g. `findings/email-assets/`), diagnoses/repros.
   **Immediate-capture rule:** the result of a RAG call or recon that influenced the plan is
   written to `findings/` in the same working turn, not "later"; a load-bearing fact living
   only in chat is a defect (a plan-audit finding).
2. **Layer 2 — authored synthesis (`research/`):** the model's design documents — analyses,
   target structures, domain models, test strategies, the owner's requirements digest
   (user-intent with "thought → where it went" tracing). Every synthesis references the
   layer-1 files it stands on; synthesis without a source carries `[UNVERIFIED]` or
   `RAG:— (repo-grounded: …)`.
3. **Layer 3 — decisions (`## 5. Decision Log`, ADRs, `root/`):** D-entries reference
   layer-1/2 files and carry RAG markers (§7); long-lived canon goes to `root/`
   (destiny — docs, §3).

- **Path and name:** `findings/<Pn>-<slug>.md` (phase-scoped) or `findings/<topic>-<date>.md`
  (plan-wide, e.g. `rag-2026-07-16.md`); `research/NN-<slug>.md` (NN — reading order).
- **How items reference them:** an item lists the layer files in its `Inputs`/`Required Reads` —
  the executor reads files, not chat; when detailing a phase (`plan-design Pn`) the phase's
  research files are read FIRST.
- **Optionality:** the directories are optional only while factually empty: if RAG calls,
  recon, or authored synthesis DID happen, capturing them in the corresponding layer is
  mandatory. The 16-field item schema is unchanged; `plan_lint.py` does not check these
  directories (not its layer).

## 17. Related skills

- `task-brief-template` (general) — single task without a master plan.
- `session-handoff` (general) — arbitrary-session handoff; under a master plan the handoff lives
  in `plans/<ID>/handoff.md`.
- `complex-task-orchestrator` (general) — decomposition and context routing.
- `context-economy` (general) — Plan→Clear→Execute, token discipline.
- `anti-drift` (general) — executor iteration discipline, circuit breaker.
- `package-contribution-protocol` (architect) — contribution to the SSOT instead of editing
  vendor copies (section 12).

<!-- ru-source-sha256: 0cbc635b1b804899f8cf9abc926c68c653d4cc296fe8b469e40c9536bd718f19 -->
