# Test infrastructure references

Checked on 2026-10-11 for the disposable database stand in `docker-compose.yml` and
the `test-db-matrix` job. See the [maintainer database stand](guides/testing.md#maintainer-database-stand);
the repository's `DEVELOPMENT.md` contains the commands and measurements.

- [MySQL 8.4 InnoDB variables](https://dev.mysql.com/doc/refman/8.4/en/innodb-parameters.html):
  `innodb_flush_log_at_trx_commit=0`, `innodb_doublewrite=OFF` and
  `innodb_flush_method=O_DIRECT_NO_FSYNC` are supported startup settings.
- [MySQL 8.4 binary logging](https://dev.mysql.com/doc/refman/8.4/en/replication-options-binary-log.html):
  `--disable-log-bin` disables the binary log.
- [MariaDB InnoDB variables](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables):
  the pre-11.0.6 doublewrite setting is boolean; MariaDB 10.11 accepts
  `innodb_doublewrite=0` and `innodb_flush_log_at_trx_commit=0`.
- [MariaDB binary-log variables](https://mariadb.com/docs/server/ha-and-performance/standard-replication/replication-and-binary-log-system-variables#log_bin):
  binary logging is disabled in the test stand with `--skip-log-bin`.
- [PostgreSQL 16 non-durable settings](https://www.postgresql.org/docs/16/non-durability.html):
  a memory-backed data directory and disabling `fsync`, `synchronous_commit` and
  `full_page_writes` are documented options for disposable databases.
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
- [Pest mutation testing](https://pestphp.com/docs/mutation-testing): a configured mutation score
  and covered-only scope are separate from line coverage. PLAN2 retains the repository's 99%
  mutation target; current online options must be checked against locked Pest 4.
- [Artifact attestations](https://docs.github.com/en/actions/concepts/security/artifact-attestations),
  [attestation action](https://github.com/actions/attest) and
  [verification CLI](https://cli.github.com/manual/gh_attestation_verify): verify archive digests,
  build identity and SBOM predicates separately from Git tag signatures, including the monorepo
  to public package tree mapping.

### PLAN2 execution coordination (2026-10-30)

- Local design reference: `agelto/plans/2026.10.23-№1-PROCESS-CORE/autopilot-prompt.txt`
  in the connected Agelto checkout, read on 2026-10-30. The AzGuard adaptation is
  `plans/2026.10.30-№1-AZGUARD-AUDIT-HARDENING/autopilot-prompt.txt`.
  It retains the Planner → conductor → registration → next cycle, small owning fixes
  and bounded recovery. Agelto-specific rig, capacity receipts, diagnostic agents and
  autopilot review are excluded. This is an adaptation, not a vendored source copy.
- Execution contracts checked against the installed Swissknifeman planner 0.34.1:
  plan-protocol v4, planner-orch orchestration overlays, and orch agent-mode/conductor
  commands. Exact GPT-6.1 Terra/Sol routes are owner-selected. Native dry-run verifies
  launch construction; it does not establish live model entitlement.
