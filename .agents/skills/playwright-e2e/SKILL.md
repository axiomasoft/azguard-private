---
name: playwright-e2e
bucket: quality
version: 0.1.0
description: "Browser E2E with Playwright for a web app (frontend-agnostic: Vue/Inertia/Livewire): playwright.config with webServer + projects, single-login auth via storageState, data-testid convention, data/media isolation between runs, CI run. Activate when adding/fixing an end-to-end browser scenario, configuring playwright.config, or storageState login."
risk: write
persona: oss-dev
tags: [playwright, e2e, browser, testing, storage-state, ci, quality, frontend, typescript]
requires: []
produces_for: []
outputs: []
snippets: [playwright.config.ts, example.spec.ts, ci-step.yml]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: Playwright E2E

Browser end-to-end tests via Playwright (real browser drives the app). Frontend stack irrelevant (Vue/Inertia, Livewire, Blade, any SPA) — Playwright sees final HTML.

## Scope

Activate when:
- adding/fixing an end-to-end scenario that must run through a real browser (registration, order checkout, file upload, multi-step form);
- configuring `playwright.config.*`: projects/browsers, `baseURL`, `webServer`, `storageState` auth;
- flakes because runs share DB/media/uploads → need per-run data isolation.

NOT here:
- unit/component frontend tests (jsdom, component mount) → skill `frontend-vue/vitest`;
- test-level choice, pyramid, coverage policy, what to cover with E2E → skill `quality/test-strategy`;
- only what runs in a **real browser** via Playwright.

**Laravel Boost**: in Laravel projects with Boost installed, its built-in skill owns browser/Livewire tests and testing conventions (version-specific, updates with the package) — do not duplicate or override it. This skill is the native TS `@playwright/test` stack (projects without Boost, or E2E split into a separate JS/TS layer). Package: https://github.com/laravel/boost (skills in `vendor/laravel/boost/.ai/`).

## Algorithm

1. **Find existing E2E setup first.** Before creating config:
   ```bash
   ls playwright.config.* e2e/ tests/e2e/ tests/Browser/ 2>/dev/null
   grep -RIl --include='*.ts' --include='*.js' -e 'storageState' -e '@playwright/test' -e 'data-testid' .
   grep -E '"(test:e2e|e2e|pw)"' package.json
   ```
   If config/specs exist, continue their convention (spec location, project names, selectors); do not introduce a parallel one. In Laravel, "browser" tests sometimes live as Pest/Dusk wrappers over Playwright (`tests/Browser/*.php`) — same engine via PHP API; this skill is the native TS `@playwright/test` stack.

2. **Install dep + browsers** (if absent):
   ```bash
   npm i -D @playwright/test
   npx playwright install --with-deps chromium
   ```

3. **`playwright.config.ts`** (see `snippets/playwright.config.ts`):
   - `testDir` — spec dir (`./e2e` or `./tests/e2e`);
   - `baseURL` — from env (`E2E_BASE_URL`) with local default; in specs use relative paths `page.goto('/...')`;
   - `webServer` — command that boots the app before the run (`url`, `reuseExistingServer: !process.env.CI`);
   - `projects` — browsers (min `chromium`; add firefox/webkit as needed) + **separate `setup` project** for one-time login (step 5);
   - `retries`, `forbidOnly`, `reporter` — depend on `process.env.CI`: in CI retries + `forbidOnly: true`, locally 0.

4. **Organize specs by scenario, not by page.** One file = one user flow (`order-checkout.spec.ts`, `registration.spec.ts`). Group with `test.describe`, shared setup in `beforeEach`. Keep domain names neutral (`Order`, `Article`, `Document`), no project-specific business terms.

5. **Auth — single login per run via `storageState`** (do not log in per test via form):
   - add `e2e/auth.setup.ts` tagged as `setup` project: logs in once and saves cookies/localStorage to `playwright/.auth/user.json` via `page.context().storageState({ path })`;
   - other projects declare `dependencies: ['setup']` and `use: { storageState: 'playwright/.auth/user.json' }` — each test starts logged in;
   - add `playwright/.auth/` to `.gitignore` (real session);
   - multiple roles → multiple storageState files + multiple projects (admin/user).

6. **Selector convention — `data-testid`** (do not anchor on CSS classes / visible text — break on restyle/i18n):
   - markup: `data-testid="order-submit"`; test: `page.getByTestId('order-submit')`;
   - set `testIdAttribute: 'data-testid'` in config `use` (attribute can be changed per project);
   - for meaningful assertions `getByRole`/`getByLabel` (a11y) allowed, but action anchors via testid;
   - testid naming — `<domain>-<element>-<action?>` kebab-case, stable and meaningful.

7. **Isolate data and media between runs** (E2E write to real DB + file storage):
   - **DB:** separate test DB (`*_e2e`/`*_testing`), migrate before run; or transaction/seeding with cleanup. Never point at production DB;
   - **media/uploads:** separate disk/dir for tests (e.g. `media-test` not `media`), cleaned before/after run;
   - both test dirs in `.gitignore`;
   - drive via env (`.env.e2e`/`.env.testing`): `webServer.env` passes `DB_*` and `MEDIA_DISK`/`*_DISK` to the booted app so it writes to isolated resources;
   - unique data in tests — suffix from `Date.now()`/uuid in email/name so reruns don't fail on unique keys.

8. **Waits — Playwright auto-waiting only, no `sleep`.** Use `await expect(locator).toBeVisible()`, `toHaveURL()`, `getByText().waitFor()`. Forbid fixed `waitForTimeout(ms)` (flake source). Network waits — `waitForResponse`/`waitForURL`.

9. **Local run:**
   ```bash
   npx playwright test                 # all specs, headless
   npx playwright test --ui            # interactive debug
   npx playwright test e2e/order-checkout.spec.ts --headed
   npx playwright show-report          # HTML report after run
   ```
   Add scripts to `package.json` (`"test:e2e": "playwright test"`).

10. **CI run** (see `snippets/ci-step.yml`):
    - install browsers `npx playwright install --with-deps chromium`;
    - bring up dependent services (DB) as CI services; run migrations on test DB;
    - pass isolation env (`DB_*`, `MEDIA_DISK`) to the test step — `webServer` boots the app;
    - publish artifacts: `playwright-report/`, traces/screenshots/video on failure (`trace: 'on-first-retry'`, `screenshot: 'only-on-failure'`).

## Directory layout

```
e2e/                      # testDir
├── auth.setup.ts         # project "setup": single login → storageState
├── registration.spec.ts  # scenario: registration (no login — separate project)
├── order-checkout.spec.ts# scenario: order checkout (logged in via storageState)
└── fixtures/             # test files for uploads (images, pdf)
playwright/
└── .auth/                # storageState files (in .gitignore)
playwright.config.ts
```

## Anti-patterns

- **Login via form in every test** instead of `storageState` — slow, flaky, duplicated. One `setup` project per run.
- **CSS-class / visible-text selectors** for action anchors — break on restyle/i18n. Anchors → `data-testid`.
- **`waitForTimeout(3000)`** as "wait until loaded" — fixed pauses. Only auto-waiting `expect(...).toBeVisible()`/`toHaveURL()`.
- **Running against production DB/media disk** — pollution + risk of deleting prod data. Separate DB + separate disk, both cleaned and in `.gitignore`.
- **Hardcoded `baseURL`/`http://localhost:8000` in specs** — relative paths + `baseURL` from env.
- **One spec for the whole app** — split by user scenarios.

## Quality checklist

- [ ] Existing E2E setup checked; new convention does not duplicate it
- [ ] `playwright.config.ts` sets `baseURL` from env, `webServer` (with `reuseExistingServer: !CI`), browser projects
- [ ] Login runs once via `setup` project → `storageState`; dependent projects declare `dependencies: ['setup']` + `use.storageState`
- [ ] `playwright/.auth/` and test data/media dirs added to `.gitignore`
- [ ] Action anchors use `data-testid` (`getByTestId`), `testIdAttribute` set in config
- [ ] E2E write to isolated DB + separate media disk; isolation passed to server via `webServer.env`
- [ ] Unique data generated in tests (Date.now/uuid); reruns don't fail on unique keys
- [ ] No `waitForTimeout`; waits via auto-waiting `expect`/`waitForURL`
- [ ] Specs split by user scenarios; domains neutral
- [ ] CI: browser install, test DB migrations, isolation env, report/trace artifacts on failure

## Links

- https://playwright.dev/docs/test-configuration
- https://playwright.dev/docs/auth — authentication via storageState
- https://playwright.dev/docs/locators — getByTestId / getByRole
- https://playwright.dev/docs/test-webserver
- snippets/playwright.config.ts, snippets/example.spec.ts, snippets/ci-step.yml
- Related skills:
  - `quality/test-strategy` — what to cover with E2E (pyramid, ~10% top)
  - `frontend-vue/vitest` — unit/component frontend tests (pyramid bottom layer)
  - `laravel-testing/laravel-dusk` — browser E2E in Laravel stack via Dusk/ChromeDriver; this skill is native TS `@playwright/test`. Choice: Playwright for a separate JS/TS layer, Dusk when E2E live in PHP
  - `laravel-testing/test-isolation-guard` — test DB/media-disk guard (same isolation task as step 7)
  - `devops/db-test-preflight` — test DB preflight before E2E run in CI
  - `devops/ci-cd` — where the E2E step mounts in the pipeline

<!-- ru-source-sha256: 852e60cf9404329cce15c8a0d976fc8ec0457ab97f51d22f89e64db0a1801a3b -->
