# Test infrastructure references

## Security reporting references

Checked on 2026-11-03 for the reporting policy and release inventory:

- [GitHub private reporting configuration](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/configure-vulnerability-reporting/configure-for-a-repository):
  the feature is for public repositories; administrator permissions do not prove researcher access or enablement.
- [Composer support metadata](https://getcomposer.org/doc/04-schema.md#support): `support.security` links to the
  vulnerability disclosure policy; keep the direct private contact in that accessible policy.
- [RFC 9116 hosting and scope](https://www.rfc-editor.org/rfc/rfc9116.html#section-3): origin discovery uses
  `/.well-known/security.txt`; a GitHub Pages project subpath does not provide the origin-root endpoint.
- [Public monorepo tags](https://api.github.com/repos/axiomasoft/azguard-private/tags?per_page=100) and
  [releases](https://api.github.com/repos/axiomasoft/azguard-private/releases?per_page=100): observed `v0.3.0` and
  `v0.7.0` tags and the `v0.3.0` release, not evidence of split Composer publication. See the dated inventory in
  [RELEASING.md](https://github.com/axiomasoft/azguard-private/blob/main/RELEASING.md#security-channel-and-publication-inventory-2026-11-03).

## Disposable database infrastructure

Checked on 2026-10-11 for the disposable database stand in `docker-compose.yml` and
the `test-db-matrix` job. See the [maintainer database stand](guides/testing.md#maintainer-database-stand);
the repository's `DEVELOPMENT.md` contains the commands and measurements.

- [MySQL 9.7 InnoDB variables](https://dev.mysql.com/doc/refman/9.7/en/innodb-parameters.html):
  `innodb_flush_log_at_trx_commit=0`, `innodb_doublewrite=OFF` and
  `innodb_flush_method=O_DIRECT_NO_FSYNC` are supported startup settings.
- [MySQL 9.7 binary logging](https://dev.mysql.com/doc/refman/9.7/en/replication-options-binary-log.html):
  `--disable-log-bin` disables the binary log.
- [MariaDB InnoDB variables](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables):
  the pre-11.0.6 doublewrite setting is boolean; MariaDB 10.11 accepts
  `innodb_doublewrite=0` and `innodb_flush_log_at_trx_commit=0`.
- [MariaDB binary-log variables](https://mariadb.com/docs/server/ha-and-performance/standard-replication/replication-and-binary-log-system-variables#log_bin):
  binary logging is disabled in the test stand with `--skip-log-bin`.
- [PostgreSQL 18 non-durable settings](https://www.postgresql.org/docs/18/non-durability.html):
  a memory-backed data directory and disabling `fsync`, `synchronous_commit` and
  `full_page_writes` are documented options for disposable databases. The official 18 image
  stores data under `/var/lib/postgresql/18/docker` and expects the mount at `/var/lib/postgresql`.
- [GitHub Actions workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#jobsjob_idservicesservice_idimage):
  an empty service image disables that service, as used by the initial tmpfs change.
  Current documentation describes `services.command`, but the installed actionlint 1.7.12
  rejects that key. The follow-up starts the selected service with Compose to reuse all
  local settings instead of duplicating native service definitions.
- [Docker Compose startup](https://docs.docker.com/reference/cli/docker/compose/up/):
  a service argument selects the database; `--wait` checks readiness, bounded by `--wait-timeout`.
  [GitHub Ubuntu runner inventory](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md)
  includes Docker Compose.
- [Actions archive cache v4](https://github.com/actions/cache/blob/v4/README.md):
  cache a selected path with manifest-derived keys and fallback prefixes. The database matrix
  applies the existing repository cache pattern to Composer's `cache-files-dir`, preserving
  dependency resolution and rebuilding `vendor` on every job.

## Audit hardening design references

Checked on 2026-10-30 while authoring PLAN2 from the two reports in `audits/`.
Four Perplexity research responses and the repository observations are retained with the plan.
These references support design decisions; they are not evidence that implementation or release
qualification has passed. Exact framework and tool behavior must also match the installed lockfile.

- [PHP unserialize](https://www.php.net/manual/en/function.unserialize.php) and
  [hash_equals](https://www.php.net/manual/en/function.hash-equals.php): authenticate external
  cache data before object decoding; an allowed-class list does not authenticate a grant.
- [Laravel 13 Eloquent Builder source](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Eloquent/Builder.php)
  and [model query builders](https://api.laravel.com/docs/13.x/Illuminate/Database/Eloquent/Model.html#method_newModelQuery):
  distinguish materialized SQL constraints from deferred scope registration. AzGuard's own
  `PredicateCompiler` already applies scopes before transferring the query; regressions must
  test that behavior before changing it.
- [Laravel scoped bindings](https://laravel.com/docs/13.x/container#binding-scoped-singletons):
  request and job lifetime boundaries do not establish a memory bound within a large job.
- [Laravel authorization](https://laravel.com/docs/12.x/authorization),
  [Filament 5 security](https://filamentphp.com/docs/5.x/advanced/security) and
  [Livewire 4 security](https://livewire.laravel.com/docs/4.x/security): explicit server-side
  authorization is required at untrusted action boundaries, including record and bulk actions.
- [Laravel polymorphic types](https://laravel.com/docs/12.x/eloquent-relationships#custom-polymorphic-types):
  stable aliases require a host-owned migration of existing type values.
- [Semantic Versioning](https://semver.org/spec/v2.0.0.html): prepare a major release for the
  incompatible changes recorded after the claimed 1.0 baseline; verify publication history
  without moving existing tags.
- [Composer library lockfiles](https://getcomposer.org/doc/02-libraries.md#lock-file):
  version the development lock and separately resolve the supported lowest and latest consumer graphs.
- [GitHub secure use](https://docs.github.com/en/actions/reference/security/secure-use),
  [reusable workflows](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows) and
  [Dependabot automation](https://docs.github.com/en/code-security/tutorials/secure-your-dependencies/automate-dependabot-with-actions):
  pin external actions, minimize permissions, and enforce complete required results for the selected source commit.
- [CodeQL languages and availability](https://docs.github.com/en/code-security/concepts/code-scanning/codeql/codeql-code-scanning):
  CodeQL covers Actions and supported source languages, including JavaScript, rather than PHP.
  Private repository eligibility must be checked.
- [Artifact attestations](https://docs.github.com/en/actions/concepts/security/artifact-attestations),
  [attestation action](https://github.com/actions/attest) and
  [verification CLI](https://cli.github.com/manual/gh_attestation_verify): verify archive digests,
  build identity and SBOM predicates separately from Git tag signatures, including the monorepo
  to public package tree mapping.
