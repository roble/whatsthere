# The wire contract between the chat page and Laravel

- **Type**: `wayfinder:grilling` (HITL)
- **Status**: closed
- **Assignee**: unclaimed
- **Blocked by**: — (both research tickets now closed)

## Question

**Narrowed substantially by the research — the risk this ticket was written to contain did not
materialise.** It was framed around a feared protocol mismatch between a Vercel-ecosystem component
library and a Laravel-first streaming package. Both halves came back friendlier than assumed:

- `ai-elements-vue` is **transport-agnostic**. All 18 references to the `ai` package are `import
  type`; there is no `useChat`, no `DefaultChatTransport`, no runtime coupling. Components are
  prop/slot-driven, and the type imports are trivial string unions.
- `laravel/ai` can emit the Vercel UI message stream protocol anyway, via
  `usingVercelDataProtocol()` (`vendor/laravel/ai/src/Responses/StreamableAgentResponse.php:121`).

So no adapter is required in either direction. What survives is a smaller but real choice.

**Which stream protocol does the endpoint emit?**

- **Laravel's default vocabulary** (`stream_start` / `text_delta` / `stream_end`). Richer: the
  `stream_end` frame carries `reason` plus a full `usage` object with token counts. Cost: mid-stream
  error frames are typed with the *provider's* error code rather than a uniform `"error"`, so the
  client has to handle a open-ended set.
- **Vercel protocol** via `usingVercelDataProtocol()`. Normalises errors, and matches the shape the
  component library's own examples assume. Cost: its `finish` part is bare, so live usage and token
  counts are lost.

The deciding consideration: since the components do **not** use `useChat`, the usual reason to prefer
the Vercel protocol — free client-side parsing — largely disappears. We are hand-parsing SSE either
way, which makes the richer default protocol more attractive than it would otherwise be. Weigh that
against whether token/usage display is actually wanted.

Also settle in the same session:

- The endpoint: route, method, and middleware. `auth` is required — `modules/chat/routes/web.php`
  currently applies only `web`. Module `routes/web.php` or `routes/api.php`?
- How initial history reaches the page: an Inertia prop from `ChatController@index`, or a fetch after
  mount. Note `continueLastConversation($user)` is the exact expression of this map's one-conversation-
  per-user destination.
- **Security constraint, not a preference:** `continue($id, as: $user)` performs *no* ownership check.
  Conversation ids must never be accepted from the client. Design the endpoint so the server resolves
  the conversation from the authenticated user alone.
- CSRF alongside streaming, given Inertia v3 dropped axios for a built-in XHR client.
- Client aborts mid-stream: `then()` never fires, so nothing persists for that turn. Decide whether
  that is acceptable or needs handling.

## Answer should record

The chosen protocol with its reasoning, the endpoint signature, and the message shape crossing the
wire in both directions. Still worth an ADR — hard to reverse, and the protocol trade-off is exactly
the kind of thing a future reader will want the reasoning for.

---

## Update: the official chatbot example tips this decision

The map owner proposed <https://www.ai-elements-vue.com/examples/chatbot> as the starting point.
Reading it changes the calculus recorded above.

The example imports **`Chat` from `@ai-sdk/vue`** — a *runtime* import, not a type import. This does
not contradict the research (which established the component library itself has only `import type`
references to `ai`), but it clarifies the real choice: the components are transport-agnostic, yet
the vendor's own reference implementation drives them with the Vercel AI SDK's state machine.

`new Chat({})` owns messages, status, and the streaming lifecycle, and it consumes the **Vercel UI
message stream protocol** — precisely what `usingVercelDataProtocol()` emits on the Laravel side. So
adopting the example makes both ends speak a common protocol natively, with no hand-written SSE
parsing and no adapter in either direction.

This **inverts the recommendation written above**, which argued for Laravel's native protocol on the
grounds that we would be hand-parsing SSE anyway. If `Chat` does the parsing, that argument
evaporates.

Revised trade-off:

- **Adopt the example: `@ai-sdk/vue` + `usingVercelDataProtocol()`.** Least code by a wide margin,
  and the example is a working reference to copy rather than a design to invent. Costs: a runtime npm
  dependency, and the Vercel `finish` part is bare, so live usage/token counts are lost.
- **Hand-rolled: local refs + Laravel's native protocol.** Keeps token/usage data and drops the
  dependency, but we write and maintain the SSE parsing and status state machine ourselves.

The example's message shape is `{ id, role, parts[] }` where each part carries a `type`
(`text` / `reasoning` / `source-url`). Note it also demonstrates reasoning parts, which matters
because Gemini 3.x reasons by default — that latency has somewhere to render.

Still to settle regardless of choice: the endpoint route and `auth` middleware, how initial history
reaches the page, CSRF alongside streaming, and the ownership-check constraint on `continue()`.

## Resolution

**Vercel UI message stream protocol, with `@ai-sdk/vue` on the client.**

Server emits via `->usingVercelDataProtocol()`
(`vendor/laravel/ai/src/Responses/StreamableAgentResponse.php:121`); client drives state with the
`Chat` class as the vendor's chatbot example does.

### Why

The deciding argument was the *next* map, not this one. The house/location assistant will need tool
calling to query property data, and the Vercel protocol already carries `tool-call`, `tool-result`,
and `tool-approval-request` frames that the components render. Hand-rolling streaming today is
manageable; hand-rolling streaming *plus* the tool-call lifecycle *plus* approval UI later is a
different scale of work. Secondary: error frames are normalised, whereas the native protocol types
mid-stream errors with the *provider's* error code — an open-ended set the client would have to
handle.

### Correction to the analysis above

The earlier claim that this protocol "loses usage/token counts" was overstated. Verified in source:
`StreamEnd::toVercelProtocolArray()` returns literally `['type' => 'finish']`, so usage is absent
**from the wire** — but `then()` receives a `StreamedAgentResponse` carrying usage regardless of
protocol, so it remains available server-side for logging or persistence. Only a *live in-browser*
token counter is given up, which this map does not want.

### The contract

- **Endpoint**: `POST /chat/messages`, in the module's `routes/web.php` (not `api.php`) — the `web`
  middleware group gives session auth and CSRF, both of which this needs.
- **Middleware**: `web` + `auth`. The route group currently applies only `web`; `auth` is required
  because persistence is per-user.
- **Request**: the user's message text only. **No conversation id.** This is a security constraint,
  not a style choice: `continue($id, as: $user)` performs *no* ownership check
  (`vendor/laravel/ai/src/Concerns/RemembersConversations.php:36`), so accepting an id from the client
  would let any user read any conversation. The server resolves the conversation solely from the
  authenticated user via `continueLastConversation($user)`.
- **Response**: SSE, `Content-Type: text/event-stream`, header `x-vercel-ai-ui-message-stream: v1`,
  terminated by `data: [DONE]`.
- **Initial history**: an Inertia prop from `ChatController@index`, seeded into `Chat`'s initial
  messages. One round trip, no post-mount fetch, no loading flash.
- **CSRF**: the `Chat` instance must send the CSRF token as a header, since Inertia v3 dropped axios
  and its automatic token handling.
- **Aborted streams**: accepted as-is for now. If the client disconnects mid-stream `then()` never
  fires and that turn persists nothing — acceptable at validation scale, noted as a known gap.
