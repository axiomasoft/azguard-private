# Compatibility promise

AzGuard follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). `axiomasoft/azguard` and
`axiomasoft/azguard-filament` are released together under one version. Within a major version, code written against a
supported contract keeps working after `composer update`. A change that breaks a supported contract waits for the next
major version.

This page says what is supported, what counts as a break, and how the promise is checked.

## What is supported

A type is supported when it carries a publication attribute. Everything else is internal, whatever its visibility:

| Marker | Meaning | Who relies on it |
|---|---|---|
| `#[Api]` | Consumer API: your application calls it, constructs it or reads it | Applications |
| `#[Spi(kind: SpiKind::Implement)]` | Extension contract: you implement the interface or extend the base class. AzGuard calls its methods positionally | Extension authors |
| `#[Spi(kind: SpiKind::Call)]` | Capability that only extension code calls (for example the context a plugin receives) | Extension authors |
| `@internal` on a member | Excluded from the promise, even in a supported type | Nobody |
| `Internal` namespace | The whole type is excluded | Nobody |

The `since` argument of an attribute names the version that published the type. Both packages record their supported
surface in a manifest (`packages/*/api-manifest.json`), generated from the attributes. `composer api:check` fails when a
manifest is stale.

### Enums

A public enum is **closed** or **open**; the class docblock says which (`@closed` or `@open`).

- A **closed** enum supports exhaustive `match`. A new case is a breaking change, so a closed enum never gains one
  inside a major version.
- An **open** enum can gain cases in a minor version. Match it with a default arm. `DecisionReason`, `EventType`
  and `FailureKind` are open.

### Named arguments

Parameter names of an `#[Api]` method, constructor and factory are part of the promise: the DSL and the value objects
are made for named arguments, and renaming a parameter is a break. A member that does not promise names says
`@no-named-arguments`. AzGuard never calls an `Implement` method with named arguments, so an implementer may rename a
parameter of the method it overrides.

## What counts as a break

After 1.0 each of these needs a new major version:

| Change | Applies to |
|---|---|
| Removing a supported type, or moving it into an `Internal` namespace | Every supported type |
| Changing a publication, or the `since` of an existing type | Every supported type |
| Changing the kind of a type (class, interface, enum, trait), adding `final` or `readonly`, adding `abstract`, removing or changing a parent or an interface | Every supported type |
| Removing a public member, or a protected member of a non-final type | Every supported type |
| Narrowing a visibility, or making a member static or non-static | Every supported member |
| Changing a return type, a parameter type, a default value or a constant value | Every supported member |
| Removing or reordering a parameter | Every supported method |
| Adding a required parameter | Every supported method |
| Adding any parameter | A method implementers override: every method of an `Implement` type, and every public or protected non-final method of a non-final type |
| Renaming a parameter | A method that promises named arguments, except the methods of an `Implement` interface |
| Withdrawing the named-argument promise of a type or method | Every supported type |
| Adding a method to an `Implement` interface, or an abstract method to a non-final class | Implementers |
| Removing an enum case or changing its value; adding a case to a closed enum; reopening a closed enum | Enums |
| Changing the generics or class-strings documented in the PHPDoc of a supported member | Every supported member |
| Removing a facade method or changing its signature | The `AzGuard` facade |
| Removing or remapping a PSR-4 prefix of a package | Autoloading |

These are compatible and stay in minor versions: a new supported type; a new method on a `final` class or on an `#[Api]`
or `Call` interface; a new optional trailing parameter on a method nobody overrides; a new constant; a new case of an
open enum; a new promise of named arguments; closing an open enum.

## Extension authors and persisted formats

Three things are promised separately:

- **API** (`#[Api]`): what an application calls, constructs or reads.
- **SPI** (`#[Spi]`): what an extension implements (`Implement`) or only calls (`Call`). AzGuard calls an
  `Implement` method positionally, so its parameter names may change; adding a method to the interface waits for the
  next major version.
- **Persisted format**: what is stored or emitted and read again later: the storage schema and its migrations, the audit
  rows, event payload keys, state tokens, the doctor check keys and finding ids, the `explain` JSON and exception codes.
  The machine formats below are pinned by fixtures, and `composer api:compat` compares them with the baseline.

For an extension author this means:

- depend only on types marked `#[Api]` or `#[Spi]`; a type in an `Internal` namespace or marked `@internal` can change in
  any release;
- a published migration is never edited; a schema change is a new migration. Published-baseline upgrades require
  artifact-specific qualification with `bin/upgrade-fixture.sh`; the [2.0 guide](../guides/upgrading-2.md) records
  the currently unavailable baseline and historical-schema limitations;
- an event payload gains keys in a minor version and never loses or retypes one, so read keys by name and ignore the
  ones you do not know;
- a closed enum never gains a case, so `match` over it stays exhaustive; an open enum may, so keep a default arm.

## Machine formats

The formats of [Machine formats](/reference/formats), [configuration](/reference/configuration),
[console commands](/reference/commands), [events](/reference/events) and [exceptions](/reference/exceptions) are
supported in the same way. Inside a major version:

- an exception class, its parent and its code stay; a new exception or code is an addition;
- an event type, its class and its payload keys stay; a new type or a new data key is an addition;
- a configuration key keeps its path, its type and its default; a retired key stays retired;
- a command keeps its name, arguments, options and exit codes; a new command, a new optional option and a new exit
  label are additions;
- a doctor check key, finding id, severity and the fields of the JSON stay; a new check or finding id is an addition;
- a publish tag, a migration basename and a stored table, column, index and constraint stay. A change of the schema
  ships as a new migration with a new basename and never edits a published one;
- the JSON of `azguard:explain` keeps its fields and step names.

A reader of these formats must ignore keys it does not know.

## How it is checked

- `composer api:check` regenerates the manifests and fails when they are stale.
- `composer api:compat` compares the manifests and the format fixtures with the baseline and fails on every break listed
  above. It is a blocking job of the continuous integration and of the release workflow, so a tag cannot skip it.
- `compatibility.json` declares supported `major: 2`. Auto comparison selects the newest stable `v2.Y.Z` ancestor,
  excluding tags on HEAD. Before an eligible stable tag exists, it uses the immutable `major_candidate` SHA.
  The fallback must also precede HEAD: the first 2.0 tag is never compared with itself. Tags of other majors and
  prereleases cannot become an automatic baseline. A same-major break still exits 1.
- The former `candidate` SHA is retained unchanged. The committed [2.0 migration inventory](./compatibility-2.json)
  records every break and addition between that candidate and `major_candidate`, with the exact rationale for
  starting the 2.0 line. This inventory is generated with `php bin/api-compat.php --write-migration-inventory`;
  auto comparison verifies it against both pinned committed trees and rejects a missing or stale inventory.
  Later 2.x changes are compared normally and cannot be hidden in that historical inventory.
- `php bin/api-compat.php --against=<ref> --json` still compares across majors on request and reports all breaks
  with exit 1; the major policy does not suppress explicit migration diagnostics. Historical specs without `major`
  retain the original newest-stable-v1+ / `candidate` behavior.
- The pointer in `compatibility.json` can change in a pull request only with the `compat-baseline` label, which is
  the explicit release decision.

## Versions

| Version | What it may contain |
|---|---|
| Patch (`1.0.1`) | Bug fixes, documentation, internal changes |
| Minor (`1.1.0`) | The additions listed above |
| Major (`2.0.0`) | Any break listed above |

## Supported platforms

Composer's requirements permit PHP `^8.3` and Illuminate `^12.0|^13.0`. The tested support promise is the following
five pairs, matching `.github/workflows/tests.yml` and `bin/consumer-matrix.sh`:

| PHP | Laravel | Dependency resolution |
|---|---|---|
| 8.3 | 12 | lowest and latest |
| 8.4 | 12 | lowest and latest |
| 8.3 | 13 | lowest and latest |
| 8.4 | 13 | lowest and latest |
| 8.5 | 13 | lowest and latest |

PHP 8.5 with Laravel 12 and Laravel 11 are outside this promise. The SQLite lane covers these pairs; separate
server lanes use the pinned MySQL 8, MariaDB 10.11, PostgreSQL 16 and Redis 7 images from `docker-compose.yml`.
These are tested engine lines, not a claim that every engine version or platform cross-product was qualified.

The Filament package requires `filament/filament: ^5.8.4` (5.8.4 or later within major 5), and
`axiomasoft/azguard: self.version`: install both AzGuard packages at the same version. The root development lock
pins development tools; consumer lowest/latest lanes resolve independently and keep their own locks.
