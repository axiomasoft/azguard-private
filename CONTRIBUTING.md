# Contributing to AzGuard

Thank you for helping. Development setup, scripts and the database matrix are in [DEVELOPMENT.md](DEVELOPMENT.md);
releases are in [RELEASING.md](RELEASING.md). Report vulnerabilities privately as described in
[SECURITY.md](SECURITY.md).

## Workflow

1. Branch from `main`: `feat/…`, `fix/…`, `docs/…`.
2. Write the code and its tests. A bug fix starts with a failing test.
3. Run `composer check` (style, static analysis, Rector, API manifest, type coverage, tests, line coverage). It fails
   without a coverage driver; install pcov or Xdebug.
4. After a change to an `@api` or `@spi` symbol, run `composer api:manifest` and commit the manifest.
5. Open a pull request to `main`. All CI checks must be green; one approval is required.

Mutation testing is optional and deferred by the owner while the API is unstable before 1.0.
It is not an acceptance or release gate for 0.7.0. The separate `composer mutate`,
`composer mutate:core` and `composer mutate:filament` commands retain their thresholds and fail on errors;
the mutation workflow runs only via `workflow_dispatch`. Further validation of the existing
mutation-runner compatibility patch is a separate task.

## Code standards

- Pint with `pint.json`, PHPStan at the configured level without a baseline, Rector clean.
- `declare(strict_types=1)` in every PHP file (enforced by Pint).
- Native types everywhere; PHPDoc only for what native types cannot express.
- `final` and `readonly` by default; enums instead of string constants; no magic methods.
- Comments explain why, in one or two lines; never refer to tickets or plans.

## Commit messages

Every commit and every pull request title follows [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>)!: <subject>

<body: why the change is needed and why this way>

BREAKING CHANGE: <what breaks and how to migrate>
```

- **Types:** `feat`, `fix`, `perf`, `refactor`, `docs`, `test`, `build`, `ci`, `style`, `chore`, `revert`.
- **Scope:** optional, kebab-case: `core`, `filament`, `sources`, `docs`, `deps`…
- **Subject:** English, imperative, printable ASCII, no trailing period; the whole header is at most 100
  characters.
- **Breaking change:** `!` after the type or scope and a `BREAKING CHANGE:` footer.

`bin/check-commits.sh origin/main` checks the commits of a branch; the `PR Check` workflow runs it and checks the
pull request title. Pull requests are squash-merged, so the title becomes the commit on `main`.

## Changelog and versions

- A user-visible change adds a line to `## [Unreleased]` in `CHANGELOG.md` in the same pull request, under
  `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` or `Security`. Internal changes (tests, CI, refactoring)
  do not.
- Versions follow [SemVer](https://semver.org/): `feat` → minor, `fix`/`perf` → patch, `!` → major. Both packages
  share one version.
