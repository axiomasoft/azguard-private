# Release evidence

One file per release candidate: `release-evidence/v<version>.yml`. It lives outside `packages/`, so it is never part of a
split archive. It binds one commit to the workflow runs, the local runs and the artifacts that qualified it, and a
validator checks it. A change after qualification (any new commit) needs a new qualification run and a new file.

## Fill

Copy the example in `docs/reference/release-evidence.md` and replace every placeholder:

- `commit` is the commit the tag points to; `candidate` is the qualified candidate commit.
- `workflows` holds the URL of one run per workflow, all on `commit`: `release` (the tag run), `tests`, `nightly`,
  `compatibility`, `static`, `style`, `docs`, `security`, `zizmor`.
- `local` has one row per local acceptance command: `command`, `exit` (must be 0), `summary`, `log`.
- `artifacts` holds the sha256 of the two archives and the two SBOMs from `SHA256SUMS`.
- `split` holds the tag commit of each split repository and `tree_identical: true`.
- `owner_approval` is added by the repository owner when the release is approved.

## Validate

```bash
php bin/release-evidence.php release-evidence/v1.0.0-rc.1.yml                          # shape only
php bin/release-evidence.php release-evidence/v1.0.0-rc.1.yml --live                   # also GitHub: runs, tag, attestations
php bin/release-evidence.php release-evidence/v1.0.0-rc.1.yml --live --require-approval
```

Output is one line per violation, `<rule-id> <field>: <reason>`; exit `0` clean, `1` violations, `2` usage or read error.
Rules: `E1` schema, `E2` version, `E3` commit, `E4` created_at, `E5` workflows, `E6` local, `E7` artifacts, `E8` split,
`E9` required field; `L1` run commit, `L2` run conclusion, `L3` tag target, `L4` release run event, `L5` attestation;
`A1` approval. The validator never writes.
