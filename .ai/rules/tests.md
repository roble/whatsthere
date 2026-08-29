---
paths:
  - '**/tests/**'
---

# Tests

## Test after the feature is verified, not during
Build the feature first and verify it by hand (Chrome DevTools against the running app). Only once it works and the user agrees it is finished, add tests covering the main scenarios — and for e2e specifically, ask first.

Do not write tests speculatively alongside code that is still changing shape, and delete tests once they no longer describe how the feature works — a stale test is worse than no test.

This overrides the general "every change must be programmatically tested" guidance in CLAUDE.md.
