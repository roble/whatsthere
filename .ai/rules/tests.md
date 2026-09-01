---
paths:
  - '**/tests/**'
---

# Tests

## Test after the feature is verified, not during
Build the feature first and verify it by hand (Chrome DevTools against the running app). Only once it works and the user agrees it is finished, add tests covering the main scenarios — and for e2e specifically, ask first.

Do not write tests speculatively alongside code that is still changing shape, and delete tests once they no longer describe how the feature works — a stale test is worse than no test.

This overrides the general "every change must be programmatically tested" guidance in CLAUDE.md.

## Tests never reach the network
`Tests\TestCase::setUp()` calls `Http::preventStrayRequests()`, so any HTTP call without a matching `Http::fake()` throws instead of leaving the machine. This matters because `Laravel\Ai`, Nominatim and Overpass all go through Laravel's HTTP client: without it, a tool test that forgets its fake reaches the real service and passes, and OpenAI calls cost money. `phpunit.xml` also blanks `OPENAI_API_KEY` so anything slipping past the fakes fails on auth rather than billing.

The same setUp fakes `*__inertia_ssr*`. Inertia server-renders the landing page, which with a hot Vite dev server is a real POST to :5173 — so page tests otherwise behave differently depending on whether `npm run dev` is running, and never exercise SSR in CI at all.

Fake the provider, not the transport, for agents: `Ai::fakeAgent(SomeAgent::class, ['reply'])`.
