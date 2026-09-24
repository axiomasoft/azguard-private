# Perplexity capture — P7 — 2026-09-23

**Tool:** `perplexity.search` (ordinary search)
**Capture kind:** response text returned to the design session
**Normative status:** non-normative retrieval lead; load-bearing claims require direct primary-source or repository verification.

## Query

Use only primary sources and provide a concise evidence ledger with URLs, no quotations. Composer package support matrices and dependency constraint testing; GitHub Actions tag-triggered workflows and the fact that a tag points to an existing commit; changelog-before-tag release sequencing for PHP packages. Distinguish official guarantees from recommended release-process inference.

## Response

"Topic","Officially established","Source"
"Composer constraints","A require value is a version constraint, not necessarily one immutable version. Composer resolves constraints against available VCS refs/tags and selects a matching version subject to the whole dependency graph.","Composer: Versions and constraints getcomposer"
"Composer support matrix","A support matrix can be encoded with require, conflict, and platform constraints such as php and ext-*; Composer checks platform requirements against the PHP/runtime environment executing Composer.","Composer: platform dependencies getcomposer"
"Constraint semantics","^1.2.3 means ≥1.2.3\ge 1.2.3≥1.2.3 and <2.0.0< 2.0.0<2.0.0; ^0.3 means ≥0.3.0\ge 0.3.0≥0.3.0 and <0.4.0< 0.4.0<0.4.0. Composer recommends caret constraints for library interoperability.","Composer: Versions and constraints getcomposer"
"Upper bounds","Composer explicitly warns that unbounded constraints can admit future breaking versions; it recommends upper bounds, increased only after compatibility testing.","Composer FAQ: unbound constraints getcomposer"
"Tag-based package versions","Composer normally consumes Git tags; it derives its available package versions from tags, normalizing a leading v where applicable.","Composer: VCS tags and branches getcomposer"
"GitHub Actions tag triggers","A workflow can use on.push.tags to run only when matching tags are pushed; patterns are evaluated against the Git ref name.","GitHub Actions workflow syntax docs.github"
"Tag points at a commit/ref","GitHub documents a create event for a created branch or tag, with GITHUB_SHA being the last commit on that created tag/ref. For a push event, the documented SHA is the tip commit pushed to the updated ref.","GitHub Actions events github"
"Tag mutability","GitHub documents force-updating a tag to point it at another commit. Therefore, ordinary Git tags should not be assumed immutable merely because they exist.","GitHub: immutable releases and tags github"
"Changelog organization","Keep a Changelog recommends an Unreleased section and moving its changes into a versioned release section at release time; it also recommends an entry for every version and a release date.","Keep a Changelog 1.1.0 keepachangelog"

