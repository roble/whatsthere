---
paths:
  - '**'
---

# General

## Develop against the root hot-reload app
Run project commands from the repository root, never through Docker. The development app is already served at https://localhost with `npm run dev`, so do not build for browser verification. Use Chrome DevTools for manual checks. Do not create or run E2E tests until the whole project is finished.
