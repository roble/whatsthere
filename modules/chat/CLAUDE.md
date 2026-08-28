# Chat Module

The application's landing experience: a streaming AI chat with per-user
conversations, exposed to external AI agents over WebMCP.

## Key Files

| Layer      | Files                                                                       |
| ---------- | --------------------------------------------------------------------------- |
| Controller | `ChatController` (index, show, messages, stream)                            |
| Agents     | `ChatAgent` (the assistant), `ConversationTitleAgent` (names conversations) |
| Jobs       | `GenerateConversationTitle`                                                 |
| Provider   | `ChatServiceProvider` — shares `chat.sessions` via `shareInertiaData()`     |
| Pages      | `Index` (the whole UI; blank or an existing conversation)                   |
| Components | `ChatSessions` (sidebar list, hung on the core `sidebar-content` slot)      |
| WebMCP     | `resources/js/webmcp/chatTools.ts`                                          |

**No models or migrations** — conversations live in `laravel/ai`'s
`agent_conversations` / `agent_conversation_messages` tables.

## Routes

All require `auth`:

```
GET   chat                          → chat.index     blank session
POST  chat/messages                 → chat.stream    send + stream a reply
GET   chat/{conversation}           → chat.show      open a conversation
GET   chat/{conversation}/messages  → chat.messages  read one as JSON
```

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
