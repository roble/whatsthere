---
paths:
  - '**/tests/e2e/**'
---

# E2E

## Ask before writing or running e2e tests
Do not create Playwright/e2e tests speculatively, and do not run `npx playwright test` mid-feature. Verify UI behaviour with Chrome DevTools against the running app while you build.

Once the feature works and the user agrees it is finished, **ask** whether to add e2e tests covering the main scenarios. Write and run them after that agreement.

## Setup must migrate, never migrate:fresh
`tests/e2e/database.setup.ts` runs against the same database the dev app serves, so `migrate:fresh` destroys the team's working data. It runs `migrate` plus `db:seed` / `modules:seed`, and every seeder is `firstOrCreate`, so the setup is safe to re-run.

If you add a seeder, keep it idempotent or the suite becomes destructive again.

A Playwright run also rewrites `public/hot` to the test server's Vite port. If the app renders blank afterwards, point that file back at the dev server's port (`https://[::1]:5173`) or restart `npm run dev`.
