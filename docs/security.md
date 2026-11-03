# Reporting security issues

Send vulnerability reports privately to [dv.vostrikov@gmail.com](mailto:dv.vostrikov@gmail.com).
Do not disclose vulnerability details in public issues or pull requests. If the link fails, copy the address
into your mail client; if there is no acknowledgement after 3 business days, retry by email.
The [Security Policy](https://github.com/axiomasoft/azguard-private/blob/main/SECURITY.md) is authoritative
for support, response targets and coordinated disclosure. Both Composer packages link to that public policy.

## Support and disclosure

The latest stable minor of 2.x receives security fixes once published; `main` receives best-effort development fixes.
The planned `2.0.0-rc.1` and `2.0.0` are not proof of publication. Prerelease users may need the next prerelease.
The 2026-11-03 inventory did not establish a published 1.x or stable 2.x Composer artifact; 1.x maintenance is
unverified, with no LTS promise. Versions below 1.0 are unsupported. Report an older installation's issue for triage,
but do not assume a backport or a qualified upgrade. Read the [2.0 upgrade guide](./guides/upgrading-2.md).

The single maintainer aims to acknowledge within 3 business days; this is a target, not a contractual SLA.
Critical/high severity and active exploitation take priority. Triage, progress updates and an embargo date are
agreed privately with the reporter. Accepted issues get a GHSA where available and a CVE request when eligible;
identifier assignment is not guaranteed. Disclosure normally accompanies verified fixes, but active exploitation,
a leak or a delayed fix can require an earlier coordinated mitigation notice. See the policy for the full process.

## Key compromise response

Use the private email contact above and describe the exposure without sending the leaked secret.

1. Contain the affected access and preserve restricted evidence: time, credential identifier, affected commits,
   workflows, artifacts and deployments. Stop affected releases until their integrity is established.
2. For release tokens, deploy keys or signing credentials, revoke/disable the compromised credential at its issuer,
   issue a replacement with minimum permissions and update authorized CI/deployment secret stores. Check for
   unauthorized tags, releases, attestations and artifacts; do not trust a signature made with a compromised key.
   Treat OIDC/workflow compromise as a build identity incident too; re-establish trusted workflow and permissions.
3. For `AZGUARD_CACHE_SIGNING_KEY` (or the `APP_KEY` fallback), rotate the host's signing material and remove the
   compromised key from previous verification keys immediately; a grace period would still accept forged entries.
   If `APP_KEY` was exposed, follow the host's encryption/session recovery procedure too. Rotate exposed cache/backend
   credentials and review authoritative data for tampering; key rotation cannot repair altered grants or source state.
4. Change the application's cache namespace, discard only its affected permission-set entries, and restart long-lived
   workers to remove in-process state. Do not copy or re-sign old entries or flush a shared Redis database. Follow
   [cache cleanup](./advanced/caching.md) and [operations](./advanced/operations.md) for scoped cleanup and state reset.
5. Rebuild and qualify artifacts from a trusted commit, verify the exact package pair, and issue a superseding
   patch/prerelease if an artifact was affected. Never move/delete a published tag. Coordinate the advisory,
   affected identifiers and user rotation instructions with the reporter under the policy's embargo process.

See the [trust model](./advanced/security.md), [configuration](./reference/configuration.md) and
[emergency release checklist](./advanced/security.md#emergency-release).

## Reporting channel verification

On 2026-11-03, read-only GitHub metadata identified `axiomasoft/azguard` as private; the connected administrator
has access, but anonymous requests to the intended reporting form returned HTTP 404. Enablement was not verified.
The future form is `https://github.com/axiomasoft/azguard/security/advisories/new`; email remains canonical until
public researcher access and private reporting enablement are verified. No test vulnerability message was sent.
The email matches the owner-supplied Composer author contact; Gmail MX records resolved. This does not prove
mailbox delivery or acknowledgement. No delivery test or remote settings change was performed.

## Security contact hosting status

The public docs site at <https://axiomasoft.github.io/azguard-private/> returned HTTP 200 on 2026-11-03.
Its `/.well-known/security.txt` project path and the origin-root path returned HTTP 404. A static file is prepared
in this site's public assets for the next separately authorized docs deployment; its presence in the repository
does **not** establish a working hosted endpoint. After deployment, verify HTTP 200, `text/plain`, the contact,
policy link and unexpired `Expires` at <https://axiomasoft.github.io/azguard-private/.well-known/security.txt>.

This is a GitHub Pages project subpath. Under [RFC 9116](https://www.rfc-editor.org/rfc/rfc9116.html#section-3),
origin-wide discovery uses `https://axiomasoft.github.io/.well-known/security.txt`; that root is not served by this
repository. Origin-root publication requires the organization site's owner and is not completed here. No custom
project domain was verified. Recheck hosting and renew `Expires` before 2027-05-01; an expired or unavailable file
must not be described as a verified reporting endpoint.
