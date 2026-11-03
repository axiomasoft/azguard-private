# Local qualification probe

`qualify-release.sh` syntax-checks every tracked PHP file in a temporary Git
clone. It does **not** perform release qualification, and cannot publish, split,
tag, connect to databases, run a matrix or make a remote request. It returns
`1` even when every local check passes: the remaining required lanes are listed
as unavailable in the receipt. A failed local check also returns `1`; invalid
input or an unsafe source checkout returns `2` without a successful receipt.

Create a candidate JSON outside the checkout, with the exact clean `HEAD`:

```json
{"schema":"azguard.local-qualification-candidate.v1","source_sha":"0123456789abcdef0123456789abcdef01234567"}
```

Replace the example SHA with the full commit ID, then run:

```sh
bash bin/qualify-release.sh --candidate=/tmp/azguard-candidate.json --no-publish --report=/tmp/azguard-qualification.json
```

The report and its sibling `.artifacts` directory must not already exist. The
receipt records the commit and tree, lockfile hash, tool versions, command exits
and SHA-256 digests of retained check logs. PHP coverage records the exact tracked
file count and index digest. It is explicitly `nonrelease`, is not
accepted as the full P8.2 evidence bundle, and does not satisfy any release gate.
