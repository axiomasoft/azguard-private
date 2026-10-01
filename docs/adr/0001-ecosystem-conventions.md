# ADR 0001: Ecosystem conventions

- **Status:** Proposed (accepted for AzGuard, proposed to the `axiomasoft` ecosystem)
- **Date:** 2026-10-01
- **Deciders:** AzGuard maintainers

## Context

Packages of the `axiomasoft` ecosystem solve different problems: AzGuard decides who may do what, Vaulter stores
files. A developer who knows one package should still find their way around the other quickly, so packages are
built the same way: the same configuration shape, commands, events, errors, and test helpers.

At the same time each package keeps its own subject words. Authorization and file storage are different
subjects, and a forced shared vocabulary would hide the difference.

This ADR is the AzGuard copy of one shared text. It records the engineering rules AzGuard follows and the
vocabulary AzGuard owns. Every other package applies the same rules in its own column; Vaulter applies its own
column in its own repository. This ADR does not describe the state of any other repository.

## Decision

### 1. Common engineering rules

Each rule is stated once as a common rule and then as the AzGuard value.

| Topic | Common rule | AzGuard |
|:--|:--|:--|
| Installation | One vendor for the whole ecosystem: `axiomasoft/<package>` | `axiomasoft/azguard` |
| Versions inside a product | Packages of one product require each other as `self.version` | `self.version` |
| Configuration | One config file per package; readonly `*Config` objects; validated at load; `config()` is read only inside `Configuration\` | Same |
| Host keys | `<package>.ids.host_keys` accepts `string`, `bigint`, `uuid`, `ulid` | `azguard.ids.host_keys` with the same values |
| Connection and table prefix | Own connection setting and a short table prefix per package | `azguard.storages.<name>.connection`, `table_prefix = 'azg_'` (there may be several storages) |
| On whose behalf | An `ActorRef` plus a reserved system actor type `<package>:system` | `AzGuard::actingAs(...)`, `ActorRef`, `SYSTEM_TYPE = 'azguard:system'`; the actor is optional |
| Event envelope | `eventId` (ULID), `occurredAt`, `actor`, `correlationId`; `EventType` is `noun.verb_past`; events fire after commit | The same fields plus `state` (`StateToken`) |
| Errors | `<Condition>Exception` with a `snake_case` code | Same |
| Commands | `<package>:<area>:<verb>`, `<package>:make:*`, `<package>:doctor --json` | `azguard:<area>:<verb>`, `azguard:make:*`, `azguard:doctor --json` |
| Extension keys | `vendor/name` | `vendor/name` |
| Tests for consumers | A `<Package>\Testing\` namespace plus contract suites | `AzGuard\Testing\` plus contract suites |
| References to host entities | Morph alias plus the id as a string | Morph alias plus string id (`SubjectRef`, `AssignmentScopeRef`) |

The naming rules are normative templates:

- event types follow `noun.verb_past`;
- commands follow `azguard:<area>:<verb>`;
- exceptions follow `<Condition>Exception` and expose a `snake_case` code.

### 2. Domain vocabulary

Words are not forced to match. If a concept is truly the same in two packages (actor, event, host key), it is
named and encoded the same way. If concepts only look alike, they keep different names.

| AzGuard term | Meaning | Closest term elsewhere in the ecosystem | Why they are not one word |
|:--|:--|:--|:--|
| **Panel** | Builder of the permissions of one part of an application: subjects, sources, settings | Profile: a set of policies for storage drives | A panel describes *who may do what*; a profile describes *how a storage behaves* |
| **Context** | The entity in which a permission applies (a store, a project) | Owner of a drive, tenant | A context selects grants; an owner or tenant isolates data |
| **Permission grant** | A permission given to a subject directly, without a role | Node grant: access to a tree node | In AzGuard it is a right to an action; elsewhere it is an access level to a file or folder |
| **Policy** (second AzGuard level) | A domain policy method that refines a granted permission | A profile that selects a permissions driver | Different levels: one permission in AzGuard, drive behavior elsewhere |
| **Role**, **Role grant** | A set of permissions and its assignment | none | Packages without roles express access through levels |

## Consequences

- Phases that introduce host keys, events, and commands in AzGuard cite this ADR instead of restating the rules.
- A change to a common rule needs the same change in the shared text and in each package column; AzGuard cannot
  change its value alone and stay conformant.
- Other packages are asked to adopt the common rules by their own owners. Sending this ADR to them is outside
  the scope of AzGuard and does not change its `Proposed` status for the ecosystem.
- Vocabulary stays local. Reusing an AzGuard word for a different concept elsewhere in the ecosystem is a defect
  of that package, not of this ADR.
