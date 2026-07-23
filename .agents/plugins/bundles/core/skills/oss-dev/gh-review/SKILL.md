---
name: gh-review
bucket: oss-dev
version: 0.1.0
description: "Review and handoff changes via GitHub CLI (gh) with context economy: gh pr diff/view/comment/review, gh api for targeted file reads instead of loading whole files. Activate when reviewing PRs, handing off to a dev, reading others' changes on GitHub."
risk: write
persona: oss-dev
tags: [github, gh, review, handoff, tokens, context]
requires: [context-economy]
produces_for: [github-flow]
outputs: []
snippets: [gh-review-commands.md]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: GitHub Review via gh

Platform-layer work on changes (PRs, review threads, others' code on GitHub) goes through **`gh`**, not whole-file reads, not web UI. Not a `git` replacement — split:

- **Local** → `git` (history, branches, working-tree state: `commit`, `branch`, `diff`, `log`, `rebase`). `gh` does not do these.
- **Platform / collaboration** → `gh` (PR, issue, review, release, `gh api`).

## When to activate

- Review a pull request (yours or someone else's).
- Handoff to a dev / accept others' work on GitHub.
- Read others' changes, a discussion thread, check status.
- Import/compare an external skill from a GitHub repo (targeted fetch).

Anti-trigger: purely local ops (commit, branch, working-tree conflict resolution) → `git`, not `gh`.

## Algorithm

### 1. Read review context surgically, not by files

Minimal slice for the task, not "read the whole PR/files":

```bash
gh pr view <N>                      # title, description, status, checklists
gh pr view <N> --comments           # only review/discussion threads
gh pr diff <N>                      # only the diff (not whole files)
gh pr diff <N> -- path/to/file      # diff of one path
gh pr checks <N>                    # CI statuses without full logs
gh pr view <N> --json files -q '.files[].path'   # list of touched paths
```

Pull a whole file **only if the diff is insufficient** — and targeted via `gh api` (step 3), not by loading a local file if it's not in the working tree.

### 2. Post review via gh, not manually in UI

```bash
gh pr comment <N> --body "..."                      # single comment
gh pr review <N> --comment -b "..."                 # review without verdict
gh pr review <N> --approve -b "LGTM: ..."           # approve
gh pr review <N> --request-changes -b "..."         # request changes
```

Group findings by severity (as in `quality/code-review`); body = path + line, not a file retelling.

### 3. Targeted file reads from someone else's repo

For import/compare of external code without cloning:

```bash
# one file's content on a branch/tag (base64 in .content)
gh api repos/{owner}/{repo}/contents/{path}?ref={ref} \
  -q '.content' | base64 -d

# list a dir's files without downloading content
gh api repos/{owner}/{repo}/contents/{dir}?ref={ref} -q '.[].path'

# commit/tag metadata (when a file changed) — for upstream drift
gh api repos/{owner}/{repo}/commits?path={path}\&per_page=1 -q '.[0].sha'
```

Channel for **imported skills**: `gh api` auto-authenticates (token from `gh auth`), respects rate-limit, returns one file. Ties to `scripts/update-upstreams.sh` and `upstream.json` (`source: github`) — see `docs/guide/upstream-sync.md`.

### 4. Handoff: leave a link, not a dump

When handing off — reference the artifact via `gh`, don't paste it as body:

```bash
gh pr view <N> --json url -q .url           # PR link
gh issue view <N> --json url -q .url        # issue link
```

Next agent/dev pulls the needed slice themselves via the same `gh` (see `session-handoff`).

## Quality checklist

- [ ] Review context taken surgically (`gh pr diff`/`--comments`), not by loading whole files
- [ ] External file read via `gh api .../contents`, not cloning/full load
- [ ] Findings posted via `gh pr comment`/`gh pr review`, not the web UI
- [ ] Local VCS ops left to `git` (gh does not duplicate them)
- [ ] Handoff via PR/issue link through `gh`, not an artifact dump into context

## Links

- snippets/gh-review-commands.md — command cheat sheet
- `general/context-economy` — general token-spend discipline (gh section)
- `oss-dev/github-flow` — Issue→PR→Merge→Release process (review step links here)
- `quality/code-review` — finding criteria and severity
- `general/session-handoff` — context handoff between sessions (handoff by link, not dump)
- `docs/guide/upstream-sync.md` — gh-fetch when importing external skills

<!-- ru-source-sha256: 3f44712c7bcdf1191b9826ee89a68460b476be9149bfb9e6d4d3c08bf42c713d -->
