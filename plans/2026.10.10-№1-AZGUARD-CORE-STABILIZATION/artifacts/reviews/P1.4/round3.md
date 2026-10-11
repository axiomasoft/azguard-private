# P1.4 review round 3 (owner-requested round 3 after round 2 FINDINGS)

Reviewer: codex/gpt-6.1-sol, reasoning effort high, read-only sandbox (`codex exec -s read-only`). Candidate: working tree at the time of the round.

The exact round-2 cases are fixed and covered by the non-vacuous regression at [OwnershipScanTest.php:187](/home/vostrikov/projects/packages/azguard/tests/Arch/OwnershipScanTest.php:187). Remaining confirmed findings:

- **Major · High confidence · [SourceScan.php:382](/home/vostrikov/projects/packages/azguard/tests/Arch/SourceScan.php:382)** — `function probe(ScopeEligibility &$x): void {}` in `AzGuard\Authorization` loses the scopes-owned parameter type. PHP emits `T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG`, whereas the filter exempts only the string `'&'`; it therefore classifies the type as a constant. Intersection parameters have the same problem with the other ampersand token. I2/I3 can miss forbidden dependencies. [PHP token reference](https://www.php.net/manual/en/tokens.php).

- **Major · High confidence · [SourceScan.php:265](/home/vostrikov/projects/packages/azguard/tests/Arch/SourceScan.php:265)** — Ownership references use the file’s first namespace throughout. A core-owned `Panels/PanelBuilder.php` containing `namespace AzGuard\Panels; class PanelBuilder {}` followed by `namespace AzGuard\Authorization; function probe(ScopeEligibility $x): void {}` resolves that parameter as `AzGuard\Panels\ScopeEligibility`. The oracle ignores it, so I2 misses the actual scopes dependency while I5 accepts the correctly located class.

- **Major · High confidence · [AutoloadOwnershipTest.php:158](/home/vostrikov/projects/packages/azguard/tests/Arch/AutoloadOwnershipTest.php:158)** — The required optimized authoritative Composer classmap is replaced by a token-generated array. Neither this scan nor the cited consumer job generates and verifies an authoritative map. For example, excluding a production type through `exclude-from-classmap` leaves these checks green although an authoritative consumer cannot autoload it. [Composer authoritative-map behavior](https://getcomposer.org/doc/articles/autoloader-optimization.md).

- **Minor · High confidence · [SourceScan.php:381](/home/vostrikov/projects/packages/azguard/tests/Arch/SourceScan.php:381)** — The bitwise-constant fix covers only the left operand. `const ScopeEligibility = 1; function read() { return 2 | ScopeEligibility; }` in `AzGuard\Authorization` still reports the constant as the scopes-owned class, causing a false I2 violation.

Pest could not start: `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`. Findings rely on static analysis.

VERDICT: FINDINGS

## Executor response

4 findings: bit-operator '&' tokens, per-namespace resolution of references, composer-generated authoritative classmap (AutoloadOwnership::authoritativeClassMap + 2 tests), right operand of '|'. All fixed with regression tests.
