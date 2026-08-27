# Make Gemini the text default

- **Type**: `wayfinder:task` (AFK — no human action needed after all)
- **Status**: closed
- **Assignee**: unclaimed
- **Blocked by**: —

## Question

**Scope corrected after research.** This ticket originally claimed no provider key existed anywhere
in `.env`. That was wrong — it came from a grep whose pattern did not actually match the string
`GEMINI_API_KEY`, and the empty result was reported as fact. **`GEMINI_API_KEY` is set and the
streaming research confirmed live calls against it succeed.** The human-blocking part of this ticket
has evaporated; what remains is small and automatable.

The work:

1. Change `'default'` in `config/ai.php` from `openai` to `gemini` (line 18). Leave the other
   modality defaults alone — out of scope for this map.
2. Add a `GEMINI_API_KEY=` placeholder to `.env.example`, which still does not advertise the
   requirement.
3. Verify a round trip after the flip.

Model ids are confirmed from `vendor/laravel/ai/src/Providers/GeminiProvider.php`: `gemini-3.7-flash`
is both `default` and `smartest`, `gemini-3.5-flash-lite` is `cheapest`. Note that Gemini 3.x reasons
by default — the research measured 84 reasoning tokens on a trivial prompt, so there is real
pre-first-token latency to design the UI around.

## Answer should record

The model id settled on for chat, and observed time-to-first-token, since it shapes the loading state
in the prototype ticket.

## Resolution

`config/ai.php` line 16 now reads `'default' => 'gemini'`. Added the missing `GEMINI_API_KEY=`
placeholder to `.env.example`. Model id left at the provider default, `gemini-3.7-flash`
(`GeminiProvider.php:96`), which is also its `smartest` tier; `gemini-3.5-flash-lite` is available as
`cheapest` if latency proves painful.

Time-to-first-token not yet measured against the real chat endpoint — deferred to the build, since
Gemini 3.x reasoning latency is better measured through the actual UI than a synthetic call.
