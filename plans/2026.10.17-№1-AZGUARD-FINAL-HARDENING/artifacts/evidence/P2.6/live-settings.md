# P2.6 live settings (2026-10-17, GOVERNANCE-APPLY granted: "делай все, кроме CI")

| Command | Result |
|:--|:--|
| `gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/{main,tags,tag-creation}.json` | 25311716 main, 25311717 release-tags, 25311718 release-tag-creation, all active |
| `gh api repos/axiomasoft/azguard-private/rules/branches/main --jq ...` | deletion,non_fast_forward,pull_request,required_linear_history,required_status_checks |
| `gh api -X PUT .../private-vulnerability-reporting` | enabled: true |
| `gh api -X PATCH repos/axiomasoft/azguard-private` (secret scanning, push protection) | both enabled |
| `gh api -X PUT .../automated-security-fixes` | accepted |
| `php bin/repository-settings-audit.php --live` | clean, exit 0 |
| `gh pr close 78 79 81 82 83` | closed with a comment |

Not done (need the branch pushed and CI, excluded by the owner): PR feat/1.0-final-hardening -> main, merge, drills D-1..D-4.
