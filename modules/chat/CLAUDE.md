# Chat Module

The application's landing experience: a streaming AI chat with per-user
conversations, exposed to external AI agents over WebMCP.

## Key Files

| Layer      | Files                                                                              |
| ---------- | ---------------------------------------------------------------------------------- |
| Controller | `ChatController` (index, show, messages, stream, place)                            |
| Agents     | `ChatAgent` (the assistant), `ConversationTitleAgent` (names conversations)        |
| Tools      | `Ai/Tools/ShowOnMap` (geocodes a place), `Ai/Tools/EircodeToGeoLocation` (fake)    |
| Jobs       | `GenerateConversationTitle`                                                        |
| Provider   | `ChatServiceProvider` — shares `chat.sessions` via `shareInertiaData()`            |
| Pages      | `Index` (the whole UI; blank or an existing conversation)                          |
| Thoughts   | `resources/js/thoughts/` — `kinds.ts` (the registry), `index.ts` (`thoughtsFor()`) |
| Components | `ChatSessions` (sidebar), `ContextMap`, `ThinkingIndicator`, `TypewriterText`      |
| Frontend   | `resources/js/map.ts` — `MapView`, `MAP_TOOLS`, `toMapView()`, `viewKey()`         |
| WebMCP     | `resources/js/webmcp/chatTools.ts`                                                 |

**No models or migrations** — conversations live in `laravel/ai`'s
`agent_conversations` / `agent_conversation_messages` tables.

## Routes

All require `auth`:

```
GET   chat                          → chat.index     blank session
POST  chat/messages                 → chat.stream    send + stream a reply
POST  chat/place                    → chat.place     geocode for WebMCP
GET   chat/{conversation}           → chat.show      open a conversation
GET   chat/{conversation}/messages  → chat.messages  read one as JSON
```

`chat/place` is declared **before** `chat/{conversation}` or the router reads
"place" as a conversation id.

Chat is the application's home: `/` and `/dashboard` both redirect here, so the
module deliberately registers **no** sidebar nav item of its own.

## Patterns

### Ownership is the security boundary

`laravel/ai`'s `continue($id, as: $user)` performs **no ownership check**. Every
route that accepts a conversation id from the client must resolve it through
`ChatController::ownedConversation()` first, which scopes by participant and
404s otherwise. Adding a second path to `continue()` is how this class of bug
ships.

### The conversation id handshake

`stream()` creates the conversation up front rather than letting
`RememberConversation` create it mid-stream, because the browser needs the id
before the first byte to move a new chat onto `/chat/{id}`. It comes back as an
`X-Conversation-Id` response header; `guardedFetch` in `Index.vue` reads it and
calls `history.replaceState`.

The cost of creating it ourselves is that the package skips its own title
generation — hence `GenerateConversationTitle`.

### Conversation titles

A new conversation is named after its opening message (truncated). Once it has
enough substance, `GenerateConversationTitle` replaces that with a real title
from `ConversationTitleAgent` (cheapest model, 30 tokens).

Re-titled at the user-message counts in `GenerateConversationTitle::RETITLE_AT`
(`3, 10, 25, 60`) — a conversation drifts, and a title from turn three stops
describing it by turn twenty. Each run summarises the **most recent**
`TRANSCRIPT_SIZE` messages, not the oldest, or every re-title would re-read the
same opening and produce the same title.

`uniqueId()` includes the milestone. Keyed on the conversation alone,
`ShouldBeUnique` + `$uniqueFor = 3600` would let the run at three messages
suppress the run at ten.

### Sidebar freshness and ordering

The session list is a **shared** Inertia prop, so it only changes when a
response arrives. Two consequences:

- Adopting a new conversation id uses `router.visit(..., { replace: true,
preserveState: true, only: ['chat'] })`, not `history.replaceState`. A bare
  `replaceState` leaves Inertia's `page.url` on `/chat` and never refreshes the
  shared props, so a newly created conversation never appears in the list.
  `only: ['chat']` keeps `initialMessages` out of the response, which is what
  stops the reset watcher wiping a chat that is mid-stream.
- After each reply the page calls `router.reload({ only: ['chat'] })`, and again
  five seconds later if the user-message count hit a milestone — the only moment
  a queued rename can have changed a title. Milestones come from the server as
  `chat.retitle_at` rather than being duplicated in JS.

Ordering is `updated_at` descending, which `laravel/ai` touches on every stored
message. **A rename must therefore not touch it** — `GenerateConversationTitle`
sets `$conversation->timestamps = false` first, or renaming a quiet conversation
would shove it to the top as though someone had just spoken in it.

`TypewriterText` animates a title only when it _changes_, never on first paint.
It fires on a partial reload (props change, same component instances) but not on
a full Inertia visit, which remounts the sidebar.

### The route of thought

Each assistant reply is preceded by a `<ChainOfThought>` block listing what the
model did — the ai-elements component family, labelled "Route of thought" in the
UI. The components keep their upstream names so they still diff against
`registry.ai-elements-vue.com`; only the visible string is ours.

**Adding a kind of thought is one entry in `thoughts/kinds.ts`** and nothing
else. `THOUGHT_KINDS` is keyed by streamed part type — `reasoning`, or
`tool-<name>` — because every type the Vercel protocol can deliver is a literal
string, so a key is already a complete match. A tool with no entry falls through
to `UNKNOWN_TOOL` and renders a plain step rather than vanishing.

`thoughtsFor()` walks parts **in order**, so reasoning and tool calls interleave
the way they streamed.

**Finishing and succeeding are different.** The map tools answer in prose when
they come up empty, so the part still reaches `output-available`. Without a
kind's `succeeded()` the step reads "Found X" for a lookup that found nothing —
which is why kinds carry `doneLabel` _and_ `failedLabel`.

A new **body** shape is the one change costing two edits: a variant on
`ThoughtBody` and a branch in the step template in `Index.vue`.

### Provider tools are invisible to the browser

`Laravel\Ai\Streaming\Events\ProviderToolEvent` does not override
`toVercelProtocolArray()`, and the encoder skips events returning null. So
`WebSearch` and the hosted `tool_search` produce **no stream parts at all** — the
route of thought can never show a search step, and the OpenAI streaming path
emits no `Citation` events either, so `source-url` parts never arrive.

Only local tools implementing `Laravel\Ai\Contracts\Tool` are visible. If
search activity ever needs to appear in the UI, it has to become a local tool.

### The model is pinned, and both reasoning options are load-bearing

`ChatAgent` uses `#[Model('gpt-5.4-mini')]`, not `#[UseCheapestModel]`. OpenAI
rejects the hosted `tool_search` tool on `gpt-5.4-nano` outright (_"Tool
'tool_search' is not supported"_), so deferred tool loading costs that bump;
mini is the cheapest tier that accepts it.

`providerOptions()` sends `['reasoning' => ['effort' => 'low', 'summary' =>
'auto']]`. **Both halves are required.** Without `summary` OpenAI reasons
silently; without `effort` it does not reason at all, so `summary` has nothing to
report and the Thinking step never renders. Verified: `low` yields a few hundred
`reasoning_summary_text.delta` frames, `medium` several times that.

`ToolSearch` also throws on providers that do not support it (Gemini among
them), and needs `store` left at its default on the OpenAI provider. If
`config/ai.php` moves off `openai`, this feature moves with it.

### Ireland is the scope, in two halves

The soft half is `ChatAgent::instructions()`, which tells the model to decline
anything that is not an Irish location. The hard half is `countrycodes=ie` on
Nominatim's `/search` in `ShowOnMap::lookup()` — a model can talk its way around
a prompt, not around a geocoder that returns nothing.

Reverse geocoding (`placeAt()`) is deliberately **not** restricted: naming
wherever the visitor dragged the map is still the honest answer.

The cache key is `geocode:ie:` so entries stored before the restriction are not
served.

### Map tools are listed twice

`ChatController::MAP_TOOLS` and `MAP_TOOLS` in `resources/js/map.ts` must agree.
A map-moving tool missing from the PHP list reopens a saved conversation on the
default view; missing from the JS list, the map never moves during streaming.
Both failures are silent.

### WebMCP

`Index.vue` registers the tools in `chatTools.ts` through the core
`useWebMcpTools()` composable, so a visitor's own AI agent can drive the chat.
Tools run in the page inside the visitor's existing session — no tokens, no
CORS, no second auth surface.

Every `execute` reads live state when called rather than closing over it. That
keeps the tool array constant, which matters because Chrome cannot update a
registered tool: changing the exposed set means aborting every registration and
redeclaring.

## Testing

```bash
php artisan test --compact modules/chat/tests/Feature/
```

## Gotchas

- **Restart the queue worker after adding or changing a job.** `queue:work`
  boots the framework once and holds it in memory, so a worker started before a
  job class existed can never autoload it — the payload deserialises to an
  `__PHP_Incomplete_Class` and the job lands in `failed_jobs` with "tried to
  access a property on an incomplete object". Run `php artisan queue:restart`
  (and restart the container in Docker). This is not a code bug and no test
  catches it.
- Titles need a **running worker**; `QUEUE_CONNECTION=database` here.
- `read_current_chat` returns the whole transcript, which can flood an agent's
  context on a long conversation. It wants a `limit` parameter.
- Module pages resolve through `module-loader.js`, not the PHP view finder, so
  Inertia assertions need `->component('Chat::Index', false)`.
- Message timestamps have **second** precision and the primary key is a UUID, so
  there is no tiebreaker: a `latest()` query returns same-second rows in
  insertion order. `GenerateConversationTitle` reads `oldest()` and slices the
  tail instead of ordering DESC and reversing, which silently scrambled the
  transcript.
- `QUEUE_CONNECTION=sync` under test, so a job dispatched during a request runs
  inline. Tests that call `handle()` themselves need `Queue::fake()` first or the
  inline run consumes the faked agent response.
- **The route of thought is live-only.** `ChatController::show()` flattens the
  transcript to one text part per message, so reopening a conversation shows the
  answer without its steps. `lastMapView()` exists purely to recover the map
  position from that flattening. Persisting the steps means pairing
  `ToolCallMessage` with `ToolResultMessage` on replay.
- `EircodeToGeoLocation` returns **fabricated** coordinates: a routing-key table
  plus a deterministic `crc32` offset, so the same Eircode always lands on the
  same point. Real resolution needs the licensed Eircode Address Database.
  Replacing `pointFor()` is the whole job.
- Eircodes exclude vowels and `B G I J L M O Q S U Z`, and `D6W` is the one
  routing key with a letter in third position. A pattern of "letter, two digits"
  rejects a real Eircode.
- `MessageResponse` renders reasoning prose with `mode="static"`. The streaming
  mode wraps every unit in an `inline-block` span, and `animation-split="char"`
  means hundreds of boxes relaid out per token — that is what produced the
  forced-reflow warnings. Leave the main reply on `"auto"`.
- Tests must not depend on built assets. `tests/TestCase::setUp()` calls
  `withoutVite()` because the `phpunit-raw` CI job runs with `skip-build`, and
  `public/build` is gitignored — a Blade layout calling `@vite` (Filament's admin
  panel) otherwise passes locally, where the dev server is up, and fails in CI.
