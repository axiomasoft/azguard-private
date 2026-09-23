# P7 qualification and release examples

## Evidence row

```text
composer test:pgsql | PHP 8.4 / Laravel 13 | PostgreSQL 16 | azguard_test | PASS | run-id/log
redis focused suite | PHP 8.4 / Laravel 13 | Redis 7 + ext-redis | prefix=azg:<run> | FAIL(skip) | extension missing
```

## Read-only candidate preflight

```bash
version="$1"
candidate_ref="$2"
git show "${candidate_ref}:CHANGELOG.md" | grep -F "## [${version}]" >/dev/null
```

Production implementation must validate arguments/ref and match the repository's exact heading
format; the essential property is reading the candidate tree, not the working copy.

## Ownership routing

```text
P7 observes cache-expiry regression -> return to P1.2
P7 observes revision race           -> return to P2.2/P2.3
P7 observes missing Redis CI setup  -> repair P7.1
P7 observes stale upgrade prose     -> repair P7.2
```

