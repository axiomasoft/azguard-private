# P1.5 — pinned blocking Zizmor

- zizmor: v1.31.1, linux x86_64 binary `zizmor-x86_64-unknown-linux-gnu.tar.gz`
- sha256: `d185f726bdaf92df78273217dfdd24ebcd6b0ef48cf3ce776ff5762b6e138c99` (computed after download; upstream publishes no checksum asset)
- Command: `bash bin/security-tools.sh zizmor -- --persona=pedantic --min-severity=medium --config=.github/zizmor.yml .github/workflows/`
- Before (medium+): 18 findings (4 high: 3 unpinned-images, 1 bot-conditions; 14 medium ref-version-mismatch), exit 14
- After: 0 findings, exit 0 (38 below min-severity), allowlist empty (`rules: {}`)
- Fixes: setup-php comment `# v2.40.0` -> `# 2.40.0` (real upstream tag, no `v`); postgres/redis images pinned by digest; dependabot auto-merge condition uses `github.event.pull_request.user.login`
- Negative: unpinned `actions/checkout@v6` fixture fails (V2); hash mismatch exits 2 (V3)
