# Issue Templates для .github/ISSUE_TEMPLATE/

## bug_report.md

```markdown
---
name: Bug report
about: Something is broken
labels: ["bug", "needs-triage"]
---

## Summary
<!-- [bug] заголовок ≤72 символов, повелительное наклонение -->

## Affected version
<!-- Версия пакета, где воспроизводится -->

## Steps to reproduce
1.

## Expected behavior

## Actual behavior

## Environment
- PHP / runtime version:
- Framework version:
```

## feature_request.md

```markdown
---
name: Feature request
about: New capability or enhancement
labels: ["enhancement"]
---

## Problem
<!-- Какую задачу пользователя это решает -->

## Proposed solution
<!-- Желаемое API/поведение, пример кода -->

## Alternatives considered

## Breaking change?
<!-- Затронет ли существующий публичный API -->
```

## config.yml (отключить пустые Issues)

```yaml
blank_issues_enabled: false
contact_links:
  - name: Security vulnerability
    url: https://github.com/<owner>/<repo>/security/advisories/new
    about: Не открывайте публичный Issue для уязвимостей
```
