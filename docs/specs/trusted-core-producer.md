# Trusted core provenance producer: candidate

Status: design candidate only. The existing split provenance and setup workflows
remain disabled. This document does not authorize remote settings, secret
registration, workflow dispatch, tags or publication.

## Threat and trust boundary

A push to a split staging ref controls both the checked-out tree and the workflow
definition on that ref. A verifier in that checkout, or an inline check in its
workflow, cannot authenticate that same checkout before accessing a secret. A
repository or organization secret available to arbitrary split-ref workflows
therefore cannot be protected by a check in those workflows.

The candidate design uses a producer workflow on the core split repository's
protected `main` branch, with a separately protected `release-producer`
environment. GitHub must enforce that only the protected `main` ref may enter
that environment, and require an independent authorized reviewer. The source-read
credential must exist **only** as an environment secret, never as a repository or
organization secret accessible to arbitrary refs. Its minimum repository grants
are Contents read and Actions read for `axiomasoft/azguard-private`. The setup
gate must check the effective protection and relevant secret scope before any
dispatch. An unknown or unavailable observation fails closed. An environment
branch rule alone does not establish the trustworthiness of `main`: branch
protection, workflow change review, reviewer independence and immutable workflow
identity must also be verified. No claim about the current GitHub settings is made.

## Proposed execution

1. Dispatch a reviewed `workflow_dispatch` workflow explicitly on `main`; its
   only job uses `environment: release-producer`, fixed least-privilege permissions,
   and no script from the staging tree. Its workflow identity and exact commit are
   approved independently of the candidate source and are bound to the run.
2. Inputs identify the source candidate SHA, annotated tag, source release run ID
   and attempt, and expected split commit. Treat all as untrusted data. Validate
   their syntax and bind the source run to its repository, tag, SHA, attempt and
   complete successful qualification. Before executing any source-derived file,
   verify the annotated source tag using the separately approved public key and
   signer fingerprint; verify it peels to the candidate SHA. A key supplied by the
   source checkout or by a workflow input is not a trust root.
3. Fetch the staging commit as **data**. Do not check it out as the action workspace,
   load its configuration, run its scripts, install its Composer plugins, or use
   its tooling with the credential or write-scoped token. In particular, deny
   symlinks and unsafe archive members when materializing any data. Compare its
   commit and full tree against the split computed from the signed source. Build
   using reviewed tooling from the producer workflow commit, not from staging.
4. Verify the built core ZIP, source and dependency SBOMs, resolution lock and
   mapping against the exact candidate and qualification evidence. Never attest an
   artifact supplied solely by the caller. On mismatch, unavailable entitlement or
   incomplete evidence, fail before granting attestation authority. Attest the
   verified ZIP and predicates only; Filament is not produced or published.
5. The release-side verifier must constrain repository, signer workflow **and its
   approved immutable workflow commit**, event/ref, predicate type, source
   candidate, tag, run and attempt, split tree and artifact digest. A successful
   signature made by a workflow from an arbitrary staging commit is insufficient.
   Update `bin/verify-package-attestations.php` and its negative fixtures together;
   its existing `--source-ref` and `--signer-digest` checks currently bind the
   staging workflow and are not compatible with this proposed trust boundary.

## Required evidence before activation

- Local negative fixtures prove that a staging branch replacing a verifier or
  workflow, malicious input/ref, altered source/tag/producer SHA, wrong key,
  modified ZIP/SBOM/mapping, stale run and unauthorized signer all fail. A valid
  candidate fixture demonstrates every verification before any attestation step.
- A read-only P8.1 audit verifies the exact producer workflow commit and reviewed
  protected-branch rules, the environment's branch restriction and required
  reviewers, secret scope and minimum permissions, and actual attestation
  entitlement. API visibility alone does not prove a protected environment or
  credential scope. Unknown, missing, stale or unsupported observations block.
- The proposed concrete repository, environment, ruleset, reviewer, secret names
  and permissions, payload SHA and verifier change are included in a regenerated
  setup request. An explicit `CONFIGURE-RELEASE` grant is required before applying
  any remote changes or provisioning credentials. Qualification and publication
  have their own subsequent gates.

No active producer workflow is supplied by this candidate. In particular, adding
an `if` check or pinned script to a workflow loaded from a staging ref is not an
acceptable substitute for the protected execution and credential boundary above.
`trusted-producer.candidate.yml` is a non-active workflow proposal outside
`.github/workflows`: it has an unconditional failure before its credentialed
inspection and another before attestation. Its fixed inspection body validates
workflow identity, source run, qualified gate, signed source tag and the core
split without checking out either source or staging, and drops the token before
the subtree calculation. It is not a working release producer: artifact building,
real attestations and verified environment controls are still required. Do not
remove either guard or install an environment secret based on its local fixtures.

`bin/trusted-producer-evidence.php` is an unconnected candidate verifier and
`bin/trusted-producer-evidence-format.json` describes its predicate. Its approved
workflow SHA, candidate identity and artifact digests must be supplied by an
independently authenticated setup and qualification process; the function does
not establish that trust, verify environment controls, produce an attestation or
make a release ready. The fixture `php bin/test-trusted-producer-evidence.php`
uses an injected verifier and therefore is not a real `gh attestation verify`.
The setup gate remains blocked until the producer and verifier are integrated,
independently reviewed and the external controls have been verified.

`bin/trusted-core-producer-candidate.php` provides offline source/split inspection
and a deterministic core ZIP and SBOM candidate builder; direct execution always
fails. The builder requires independently approved tool and evidence digests, a
pre-resolved dependency lock and SBOM, and a signed-tag verifier. It emits a
nonrelease mapping and predicate binding the approved workflow, source, split,
run and artifact hashes. The independently approved inputs and trusted public key are caller
responsibilities; the source verifier runs in a separate sanitized process. An
injected attestation test callback is not an artifact signature. A
separate fixture tests a locally signed tag with a disposable key. Production
activation still requires the real signed-tag verifier, actual dependency
resolution and attestation integration, independently approved identities and
verified environment controls. Run Git in an isolated, freshly initialized
repository with controlled Git configuration and hooks. Do not feed Git a
staging-controlled `.git/config` while any secret is available.


Security review blocker: source inspection uses a separate verifier process with
an allowlisted environment and copies loose objects, validated pack/index pairs and only the requested tag,
using a loose ref in preference to an exact match in packed refs
into a freshly initialized Git repository. It rejects alternate object stores, and never loads the source repository configuration or hooks. The public
signing key and fingerprint must be authenticated independently. The offline builder consumes this controlled repository for its entire build;
it does not reopen the source repository. Offline tests do not qualify any remote
environment or enable the producer.

`bin/trusted-qualification-bundle.php` describes an offline-only bundle check:
exact candidate and run claims, independently supplied manifest digest, fixed
file set, config schema, file digests, and lock/SBOM graph agreement. Its
callback-based fixture never establishes authenticity, and direct execution
fails. The current release workflow uploads `release-artifacts-<attempt>` but
does not produce an independently authenticated producer-config bundle. A
future integration must authenticate the specific source repository, workflow,
run ID and attempt and its successful evidence gate, obtain the exact artifact
from that run over an authenticated GitHub API channel or verify a trusted
attestation binding those claims to the artifact digest, and independently
approve the producer tool configuration. No such remote proof is supplied by
local fixtures; the live gate remains blocked.
