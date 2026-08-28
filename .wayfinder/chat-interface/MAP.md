# Map: Chat interface for the chat module

`wayfinder:map`

## Destination

The `chat` module serves a real chat interface — [ai-elements-vue](https://www.ai-elements-vue.com/)
components on the front, `laravel/ai` streaming from Gemini on the back, with messages persisted to
the existing `agent_conversations` table as **many conversations per user**, listed in the global
sidebar.

Done means: a signed-in user opens `/chat`, types, watches a reply stream in, reloads the page, and
their history is still there — and can start new sessions and switch between them from the sidebar. It is a **generic assistant** — it knows nothing about houses,
locations, or what's nearby. That is the next map, not this one.

## Notes

**Domain**: Laravel 13 + Inertia v3 + Vue 3, Saucebase modular starter kit. Vue stack only — the
`resources/js/react/` tree has been deleted from this repo, so the "apply frontend changes to both
implementations" rule in `CLAUDE.md` does not bite here.

**Execution override**: this map **builds**, it does not only decide. Wayfinder's plan-only default
is deliberately overridden. Decisions still come first; the last tickets write real code.

**Skills every session should consult**: `ai-sdk-development` (mandatory — this is a `laravel/ai`
effort), `saucebase-module-development`, `inertia-vue-development`, `tailwindcss-development`.
Also read `.ai/rules/index.md` if it exists, and the `saucebase-core` guidelines.

**Provider**: Gemini, model `gemini-3.7-flash`, and `config/ai.php` now defaults to it. Gemini 3.x
reasons by default, so expect real pre-first-token latency — the UI needs somewhere to put it.

**Starting point**: <https://www.ai-elements-vue.com/examples/chatbot> is the reference to build
from, per the map owner, rather than designing the page from scratch.

**Terminology**: this repo already does real i18n (`laravel-vue-i18n`, a `locale` column on users, a
populated `lang/`). So **"localization" means i18n here and nothing else.** For "where the user
physically is", say **location** or **geolocation**. Not yet confirmed with the map owner, but
adopted as the working convention to stop one word meaning two things.

**Verification**: `php artisan test --compact` filtered to the touched files; `vendor/bin/pint --dirty
--format agent` after any PHP change; module e2e lives at `modules/chat/tests/e2e/`.

### Tracker conventions (local markdown)

No issue tracker was configured for this repo and there is no git remote, so this uses Wayfinder's
default local-markdown tracker.

- Tickets are files in `tickets/`, one per ticket.
- **Status** is a field in the ticket body: `open` or `closed`.
- **Claiming** is the `Assignee` field: `unclaimed` means takeable.
- **Blocking** is the `Blocked by` field, since markdown has no native dependency relation.
- The **frontier** is every ticket that is `open`, `unclaimed`, and whose blockers are all `closed`.
- Resolutions are appended to the ticket under `## Resolution`, then gisted into Decisions so far.

## Decisions so far

- [How does ai-elements-vue install, and what message shape does it expect?](tickets/02-research-ai-elements-vue.md):
  **Transport-agnostic** — all 18 `ai` package references are `import type`, no `useChat`, no runtime
  coupling. Registry is `https://registry.ai-elements-vue.com/{name}.json`; Apache-2.0; no dependency
  conflicts. The `--path` flag **flattens by basename** and would clobber sibling `index.ts` files, so
  installing into the module means install-at-root-then-`git mv` (imports survive, they resolve `@/…`).
- [How does laravel/ai stream to a browser, and how does it persist a conversation?](tickets/03-research-laravel-ai-streaming.md):
  SSE, one JSON object per `data:` frame, `data: [DONE]` sentinel; `usingVercelDataProtocol()` available
  as an alternative vocabulary. Streaming is **synchronous** — no queue or broadcast worker needed.
  `continueLastConversation($user)` expresses one-conversation-per-user directly. Surfaced a blocker:
  the migrated `agent_conversations` schema is stale against v0.11.
- [Repair the stale agent_conversations migration](tickets/07-repair-conversations-migration.md):
  Both tables were empty, so dropped and recreated from the package migration. Schema verified live —
  nullable `participant_type`/`participant_id`, `approval_state` present. `config/ai.php` was stale in
  the same way and got upgraded as a side effect.
- [Make Gemini the text default](tickets/01-gemini-credentials.md): `config/ai.php` now defaults to
  `gemini`, model `gemini-3.7-flash`. `GEMINI_API_KEY=` placeholder added to `.env.example`.
- [Where do the ai-elements-vue components live?](tickets/04-where-components-live.md): **App-level
  root**, `resources/js/components/ai-elements/`. Deliberate trade-off — this is a validation project,
  so module self-containment is sacrificed for zero tooling friction. Sidesteps the `--path`
  basename-flattening trap entirely.
- [The wire contract between the chat page and Laravel](tickets/05-wire-contract.md): **Vercel UI
  message stream protocol** via `usingVercelDataProtocol()`, with `@ai-sdk/vue`'s `Chat` on the
  client. Decided on the _next_ map's needs — tool calling for house data comes free. `POST
/chat/messages` behind `web` + `auth`; the request carries **no conversation id** (`continue()` has
  no ownership check), so the server resolves it from the authenticated user.
- [What does the chat page look like?](tickets/06-prototype-chat-page.md): **Built and verified live
  against Gemini** — send, stream, reload, history restored, both themes. Split layout with a
  resizable divider; right pane intentionally empty. Fixed three defects found only by running it:
  a persistent 503 on `gemini-3.7-flash` (pinned to `gemini-3.5-flash-lite`), an invalid
  `calc()` missing spaces that meant the height never applied, and broken streaming auto-scroll
  (now driven by the page, since `vue-stick-to-bottom` never arms its own stick).

- **Sessions: list, create, navigate** (no ticket — scoped and built directly, superseding the
  earlier "one conversation per user" boundary): sessions live in the **global** `AppSidebar` via a new
  `sidebar-content` slot in `globalComponents.ts`, so they show on every page. `/chat` is a blank
  session; `/chat/{id}` opens one. The conversation row is created **up front** in `stream()` rather
  than mid-stream by `RememberConversation`, because the id must be known before the first byte —
  it comes back as an `X-Conversation-Id` header and the page does `history.replaceState`. That
  trade costs the package's LLM-generated title, so titles are the truncated opening message.
  Collapsed, the sidebar keeps only New chat and a history icon that re-expands it.

    **The guard is load-bearing**: `continue()` performs no ownership check, and the id now arrives
    from the client, so `ownedConversation()` is the single gate every id-accepting path goes through.

- **Agent-accessible via WebMCP** (no ticket — built directly): the page registers tools through
  `document.modelContext.registerTool`, so a visitor's own AI agent can list sessions, read one by id,
  switch between them, start a new one, and put a question to the assistant. Registration lives in
  core (`resources/js/webmcp/`) with a per-component `useWebMcpTools()` composable, so a future module
  contributes domain tools by returning one more array. Three API facts, established by probing Chrome
  151 rather than from the spec: `registerTool` takes an `AbortSignal`, aborting is the **only**
  unregister, and re-registering a name is silently ignored.

- **Conversation titles** (no ticket): a conversation is first named after its opening message, then
  re-titled by `ConversationTitleAgent` at the milestones in `GenerateConversationTitle::RETITLE_AT`.
  Requires a running queue worker, and **the worker must be restarted whenever a job class changes** —
  a stale worker cannot autoload it and fails with `__PHP_Incomplete_Class`.

## Not yet specified

- **Playwright e2e coverage.** The build has seven passing PHPUnit feature tests, but
  `modules/chat/tests/e2e/index.spec.ts` still targets the old scaffold page. The `data-testid`
  hooks are in place (`chat-page`, `chat-pane`, `context-pane`, `chat-input`, `chat-submit`,
  `chat-messages`, `chat-empty`), so this is specifiable, just not yet written.
- **Failure and edge-case UX.** Session expiry is now handled: the page detects it via
  `response.redirected` (fetch follows the auth redirect, so an expired session otherwise arrives as
  a 200 containing the login page and shows nothing) and raises an `AlertDialog`. Covers the 419 CSRF
  path too. Still open: provider errors, rate limits, aborted streams, and very long replies.
  Mid-stream errors arrive as an SSE frame on an already-committed HTTP 200, and an aborted stream
  persists nothing for that turn.
- **Guest access.** Every route is behind `auth`, so a signed-out visitor sees a login form and their
  agent discovers no WebMCP tools at all. This is the blocker for any public demo.
- **`read_current_chat` returns an unbounded transcript**, which floods an agent's context on a long
  conversation. Wants a `limit` with an explicit `offset`.
- **The right-hand pane.** Deliberately empty for now. What goes in it is the obvious next
  conversation, and it is where map-and-location context would naturally land.

- **Spoken replies and audio attachments.** Voice _input_ is done: the mic uses the browser's
  `SpeechRecognition` API and never touches the server, so it needed no protocol change. The two
  remaining directions are unspecified by choice. Gemini implements both `AudioProvider` (TTS) and
  `TranscriptionProvider` (STT) in `laravel/ai`, but `config/ai.php` points `default_for_audio` and
  `default_for_transcription` at `openai`, which has no key here -- either would need that flipped to
  `gemini`. Browser `speechSynthesis` is the free alternative for spoken replies. Revisit once the
  house/location features show whether audio is part of the product.

## Out of scope

Ruled beyond this map's destination. These do not graduate; they return only as a fresh effort.

- **House, location, and nearby knowledge** — the actual product. The whole point of deferring it is
  that the second map will be far better informed for having a working pipe to reason about.
- **Renaming and deleting sessions** — the session list is read-only apart from New chat. Both need a
  route with the same ownership guard, and delete needs redirect handling for the open session.
- **Geolocation capture** — asking the browser for coordinates, storing them, or acting on them.
- **Tool calling / RAG / vector search** — how the assistant would ever _look something up_.
- **Queue + websocket streaming transport.** `laravel/ai` supports `queue()` / `broadcast()` natively
  and a queue worker is already running in Docker, but no websocket server exists in this stack
  (no Reverb, Pusher, or Soketi anywhere in `docker-compose.yml`, `composer.json`, `package.json`, or
  `.env`; `BROADCAST_CONNECTION=log`). SSE covers streaming today with one process and no new infra.
  **Tripwire for revisiting:** if "close the tab, come back, answer still generating" becomes a
  product requirement, SSE cannot do it and this returns as a fresh effort.
