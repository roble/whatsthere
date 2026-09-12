# Chat Module

The application's landing experience: a streaming AI chat with per-user
conversations, exposed to external AI agents over WebMCP.

## Key Files

| Layer      | Files                                                                                                                                               |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Controller | `ChatController` (index, show, messages, stream, place)                                                                                             |
| Agents     | `ChatAgent` (the assistant), `ConversationTitleAgent` (names conversations)                                                                         |
| Tools      | `Ai/Tools/` — `ShowOnMap` (geocodes one place), `FindPlaces` (up to 40 ranked Overpass results), `SaveItinerary` (the day, in order)                |
| Jobs       | `GenerateConversationTitle`                                                                                                                         |
| Provider   | `ChatServiceProvider` — shares `chat.sessions` via `shareInertiaData()`                                                                             |
| Pages      | `Index` (the whole UI; blank or an existing conversation)                                                                                           |
| Thoughts   | `resources/js/thoughts/` — `kinds.ts` (the registry), `index.ts` (`thoughtsFor()`)                                                                  |
| Components | `ChatSessions` (sidebar), `ContextMap`, `PlanSummary` (plan card rows), `ItineraryPanel` (the day, in order), `ThinkingIndicator`, `TypewriterText` |
| Frontend   | `resources/js/map.ts` — `MapView`, `MAP_TOOLS`, `toMapView()`, `viewKey()`                                                                          |
| WebMCP     | `resources/js/webmcp/chatTools.ts`                                                                                                                  |

Conversations live in `laravel/ai`'s `agent_conversations` /
`agent_conversation_messages` tables. The only module-owned table is
`onboarding_states`, one row per conversation holding the onboarding phase
(`interviewing` → `reviewing` → `mapping`), the open question, recorded answers,
and the map-ready plan. `InterviewVisitor`, `SaveMapReadyPlan` and `SaveItinerary` write it; the
`chat.onboarding` route moves it to `mapping` deterministically for "Skip" and
"Show my map", so neither depends on the model doing the right thing.

## Routes

All require `auth`:

```
GET   chat                          → chat.index     blank session
POST  chat/messages                 → chat.stream    send + stream a reply
POST  chat/place                    → chat.place     geocode for WebMCP
GET   chat/{conversation}           → chat.show      open a conversation
GET   chat/{conversation}/messages  → chat.messages  read one as JSON
PATCH chat/{conversation}/onboarding → chat.onboarding  open the map (skip / show)
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
`WebSearch` produces **no stream parts at all** — the route of thought can never
show a search step, and the OpenAI streaming path
emits no `Citation` events either, so `source-url` parts never arrive.

Only local tools implementing `Laravel\Ai\Contracts\Tool` are visible. If
search activity ever needs to appear in the UI, it has to become a local tool.

### The model and both reasoning options are pinned

`ChatAgent` uses `#[Model('gpt-5.4-mini')]`, not `#[UseCheapestModel]`, so an SDK
update cannot silently change the quality, latency, or cost of the application's
central experience.

`providerOptions()` sends `['reasoning' => ['effort' => 'low', 'summary' =>
'auto']]`. **Both halves are required.** Without `summary` OpenAI reasons
silently; without `effort` it does not reason at all, so `summary` has nothing to
report and the Thinking step never renders. Verified: `low` yields a few hundred
`reasoning_summary_text.delta` frames, `medium` several times that.

### Geographic scope is global

`ChatAgent`, `ShowOnMap`, `FindPlaces`, the WebMCP place tool, and the default
map view all cover the world. Nominatim searches carry no country filter. The
cache key is `geocode:global:` so responses created under the former restricted
contract are never reused accidentally.

### Finding many places at once

`FindPlaces` answers "what is around here" where `ShowOnMap` answers "where is
this". It geocodes the area through `ShowOnMap` first so there remains one place
where a free-text name becomes a bounding box.

**Unnamed places are named apart, not all alike.** OpenStreetMap is full of
unnamed viewpoints and parks, and calling every one of them "Viewpoint" left
seventeen pins nobody could tell apart -- and left the model with no string
identifying one rather than another, so asked for an itinerary from them it
reached for the name of the search itself and every stop geocoded to the same
point. `nameFor()` uses the street where there is one ("Pub at 17 Quay Street")
and numbers them where there is not ("Pub 1").

**The model does not write the query.** `FindPlaces::CATEGORIES` is an
allow-list of OpenStreetMap tag filters, and the schema exposes its keys as an
enum. Nothing model-authored reaches Overpass QL, and the only other values
interpolated are bounding-box floats. A `filter` parameter the model composed
itself would be an injection surface aimed at somebody else's donated server.
Adding a category is one line in that array.

Overpass is asked for forty results and told to `out center`; everything it
sends back is returned, **ranked rather than truncated** -- named places first,
then the ones carrying the most `DETAIL_TAGS`, which is the best proxy
available for somewhere worth going. A reply that searches twice pools both
sets, so the map can carry more than forty pins. Each marker may carry `details`
(address, hours, website, phone, cuisine, wheelchair, outdoor seating, Wi-Fi,
description) from an allow-list of OpenStreetMap tags. `ContextMap` renders
them into the popup with DOM nodes, never `setHTML`, and only links to
`http(s)` websites. The English name is preferred when the map has one.

Overpass results are cached under a versioned key (`overpass:v4:`), so bump
the version whenever the marker shape changes.

- Ways and relations carry no top-level `lat`/`lon`; `out center` gives them a
  point, and reading only the top-level pair silently drops every castle, hotel
  and supermarket, which are mapped as buildings.

**The search area is a rectangle, not the place.** A box around Kerry reaches
into Clare, so results can sit outside the county named. Fixing it means
resolving the area to an OpenStreetMap boundary and filtering on that, which is
exact but only works for places mapped as boundaries -- free-text areas like
"Douglas, Cork" would stop working.

### The itinerary lives inside the plan

`SaveItinerary` writes an ordered `stops` array into `onboarding_states.plan`
rather than into a table of its own: the plan is the source of truth for the
trip and everything already reads it. There is no migration, because `plan` is
a JSON column.

Two consequences worth knowing:

- **It merges, it does not replace.** `SaveMapReadyPlan` always writes a
  complete plan, so an itinerary written as a whole-plan update would drop the
  goal — and a plan refresh that did not preserve `stops` would drop the day.
- **The coordinates come from the geocoder, never the model**, through
  `ShowOnMap` so there stays one place where a name becomes a point. A stop
  that will not geocode is dropped and named back to the model in `unplaced`.
- **A stop outside the plan's own location is dropped too.** Restaurant and
  cafe names match somewhere of the same name in another town often enough to
  matter, and because the box has to frame every stop, one outlier stretches it
  over the whole region and zooms the map out to nothing. The plan's location
  is geocoded once and padded by half its own width (minimum ~5km) so the edge
  of town still counts. A plan with no location skips the check: a stop shown
  in the wrong place beats one silently dropped.

`save_itinerary` returns a **`MapView`** (`label`, `bbox`, `stops`), not a
plan. That is what makes it a map tool: the pins, the dashed route and the
camera all arrive through the same pipeline as a search, and the browser needs
no second path. `Index.vue` therefore tracks its stops separately from the plan
in `transcriptState` and folds them together in `activePlan`. Anything appended
after the encoded JSON would make the result unparseable and silently leave the
map where it was, which is why `unplaced` is a key rather than a sentence.

In `mergeViews` (both copies) an itinerary **wins outright** over the searches
in the same reply: it is the deliberate answer of the turn, not one lookup
among several.

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

**The tool set follows the onboarding phase.** Each tool may carry `phases`;
the page passes a computed list filtered by the current phase, and the
composable re-registers with the browser whenever that list changes. An agent
inspecting the page mid-interview sees `answer_question` and `skip_interview`;
after "Show my map" it sees `open_map` give way to `read_map_location`,
`show_place_on_map`, `show_trip_plan`, `update_trip_plan`, `read_itinerary`,
`show_itinerary` and `update_itinerary`. `read_trip_plan`, `start_trip`,
`ask_this_assistant`, `read_current_chat`, `list_chat_sessions` and
`open_chat_session` are always offered.

Every `execute` reads live state when called rather than closing over it, so
nothing but a phase change rebuilds the list. Tools that send a message await
`chat.sendMessage()`, which resolves when the reply has finished streaming, so
an agent can chain `answer_question` calls and read the plan at the end.

## Test mode

`CHAT_TEST_MODE=true` answers with canned replies from
`Testing/CannedReplies` instead of calling the model, so the front end can be
worked on for nothing — and so the states that are awkward to reach on purpose
(a tool finding nothing, a tool erroring, no reply at all) are one refresh
away. `?scenario=places` on the chat page pins which one comes back; without it
they are picked at random. `X-Chat-Test-Scenario` on the response says which
one you got.

Refused in production whatever the flag says, and pinned **off** in
`phpunit.xml` so a local `.env` cannot change what the suite tests — a test
that wants canned replies sets `config(['chat.test_mode' => true])` itself.

Nothing is persisted: the conversation row exists but reopening it is empty,
because none of it came from the model and none of it belongs in the history
the model later reads back.

The frames are copies of the shapes in `Laravel\Ai\Streaming\Events\*`, which
will go stale silently if the package changes its protocol. `CannedRepliesTest`
holds every frame against the types actually found in those classes, so that
shows up as a failure rather than as mocks that quietly describe a protocol
nobody speaks.

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
  from the repository root. This is not a code bug and no test catches it.
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
- A `MapView` carries `marker` (one located place) or `markers` (a whole
  search). `viewKey()` includes the marker count, because two searches of one
  town share a bounding box and a key without it would leave the first set of
  pins on the map.
- **A failed reply still creates an assistant message.** The stream opens with a
  `start` part before the model has produced anything, so a generation that
  fails leaves an empty assistant message _after_ the question. Two things fall
  out of that, and both were live bugs: "Not delivered" must anchor to the last
  **user** message (`lastUserMessageId`), not the last message, or it looks for
  the failure on the stub and never renders; and the stub itself must not be
  drawn (`isEmptyReply`), or it is an empty bubble where the answer should be —
  which is what "the route of thought just disappears" actually was.
- A server-side failure arrives _inside_ the stream as an `error` part, so the
  request itself succeeds. The SDK still sets `status` to `'error'` from it, so
  the status check is enough and no separate flag is needed. What it does not
  do is unwind the assistant message the `start` part already created.
- **The map popup has to be dressed by us.** MapLibre paints it white and sets
  no text colour, so it inherits the app's foreground — near-white on white in
  dark mode. The close button has the same problem and the CSS reset takes away
  the padding it sizes itself with, leaving a 7px sliver. `ContextMap` styles
  both from the popover tokens in a deliberately **unscoped** block: the popup
  is built outside Vue, so it carries no scope attribute. The tip is a CSS
  triangle made of borders, so its colour has to follow too, per anchor.
- Popups are opened with `focusAfterOpen: false`. MapLibre otherwise moves
  focus to the close button as the popup opens, so every pin clicked comes up
  with a focus ring already on it.
- **The streaming reply runs with `:enable-animate="false"`.** With animation
  on, `vue-stream-markdown` wraps every word in a Vue `TransitionGroup`, and on
  each streamed token Vue calls `getBoundingClientRect` on every word and
  `getComputedStyle` on each entering one. Cost grows with the reply: profiled
  at 8.8 s in `getTransitionInfo` plus 3.4 s in rect reads for one long answer,
  with single main-thread freezes over 10 s, and the animation never visibly
  ran. Reasoning prose stays on `mode="static"`. `followAnchor` is coalesced to
  one run per animation frame for the same reason: it reads layout.
- Tests must not depend on built assets. `tests/TestCase::setUp()` calls
  `withoutVite()` because the `phpunit-raw` CI job runs with `skip-build`, and
  `public/build` is gitignored — a Blade layout calling `@vite` (Filament's admin
  panel) otherwise passes locally, where the dev server is up, and fails in CI.
