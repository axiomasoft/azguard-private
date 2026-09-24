# PHP diff review prompts

Apply only the project's adopted architecture and conventions. This checklist does not select
an Action/Service/Repository pattern for a project or authorize a new process.

1. Trace the changed behavior across the affected HTTP/domain/persistence boundaries.
2. Check transactional atomicity, retries and duplicate effects where mutations occur.
3. Check allowed and denied resource/tenant access at server entry points. Keep enum, policy,
   route and UI behavior coherent if those mechanisms exist.
4. Check validation, mass assignment, secret handling, uploads and untrusted query input.
5. Verify meaningful regression coverage and isolated test DB/filesystem. Stack testing skills
   supply actual commands and preflight; this checklist does not add blanket coverage gates.
6. Inspect generated output through its owning source. Discover ownership from markers/config;
   neither `.ai/` nor Boost owns every project automatically.

Findings follow `quality/code-review` severity: demonstrated impact and adopted contracts.
Missing a preferred layer, named argument or style pattern does not block unless an applicable
project requirement or concrete failure explains why. Review stays read-only; a large diff or
many affected layers does not automatically spawn an orchestration workflow.
