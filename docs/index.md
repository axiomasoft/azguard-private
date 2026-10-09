---
layout: home

hero:
  name: AzGuard
  text: Authorization for Laravel, in code
  tagline: Roles are classes, permissions are enums, and every check goes through one decision pipeline that fails closed.
  image:
    src: /logo.svg
    alt: AzGuard
  actions:
    - theme: brand
      text: Get started
      link: /getting-started/introduction
    - theme: alt
      text: Quick start
      link: /getting-started/quick-start
    - theme: alt
      text: Comparison
      link: /comparison

features:
  - title: Code-first and typed
    details: Permissions are backed enums and roles are PHP classes. Renames are refactors, and azguard:doctor checks the whole setup in CI.
  - title: Panels
    details: Separate permission sets for an admin area, a customer cabinet or an API, each with its own subjects, roles and settings.
  - title: Policies with grants
    details: A grant is required and a policy may veto it, or the policy alone decides. Either way, @can, middleware and Filament reach the same answer.
  - title: Tenants, scopes and expiry
    details: Grant a role on one team for one week. Inherited scopes, tenant membership and pruning with events are built in.
  - title: Fails closed, explains itself
    details: An error in a source, policy or hook denies with a logged code. azguard:explain shows every step of a decision.
  - title: Filament 5
    details: The first-party plugin authorizes resources, pages and widgets, filters lists in SQL and adds editors for grants.
---
