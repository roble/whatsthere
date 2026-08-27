# How does laravel/ai stream to a browser, and how does it persist a conversation?

- **Type**: `wayfinder:research` (AFK)
- **Status**: closed
- **Assignee**: agent (research session)
- **Blocked by**: —

## Question

`laravel/ai` ^0.11 is already installed and its `agent_conversations` table is already migrated, so
both streaming and persistence are meant to be available. We need the real API surface, not an
assumed one — this package is pre-1.0 and moves fast.

**Consult the `ai-sdk-development` skill first**, then the official docs at
`https://laravel.com/framework/docs/ai-sdk`, then the installed source under `vendor/laravel/ai/`
as the final authority.

Find out:

1. **Streaming a response to HTTP.** How an agent streams — the method names, what object is
   returned, and how it becomes a streamed HTTP response from a controller. Is there a first-party
   helper, or is it a manual `response()->eventStream()` / generator loop?
2. **The wire format.** What the browser actually receives: SSE `data:` frames, newline-delimited
   JSON, or raw text chunks. What a single chunk looks like, and how completion, errors, and usage
   metadata are signalled. **Capture a verbatim sample of the byte stream** if at all possible — this
   is what gets compared against whatever the component library expects.
3. **Conversation persistence.** The API for creating and resuming a conversation backed by
   `agent_conversations`; the schema of that table and its messages; how history is loaded for
   replay into the UI; how a conversation is tied to a user.
4. **Provider specifics for Gemini.** Whether streaming behaves differently on the Gemini driver
   versus OpenAI, and which model ids are valid.
5. **Queues and broadcasting.** Whether the package expects a queue worker or broadcasting for
   streaming, since that would change the local dev and deployment story materially.

## Answer should record

Concrete code snippets for "stream an agent reply to the browser" and "resume a stored
conversation", a verbatim chunk sample, the `agent_conversations` schema, and any queue or
broadcasting requirement.

## Resolution

**Bottom line.** `laravel/ai` v0.11.0 ships a first-party streaming HTTP response: `$agent->stream($text)`
returns a `StreamableAgentResponse`, which is `Responsable`, so you `return` it straight from a
controller and Laravel emits SSE. No `response()->eventStream()`, no generator loop, **no queue worker
and no broadcasting** — those are separate opt-in methods (`queue()`, `broadcast()`,
`broadcastOnQueue()`) that streaming never touches. The default wire format is SSE with one JSON
object per `data:` frame in Laravel's own event vocabulary (`stream_start` / `text_start` /
`text_delta` / `text_end` / `stream_end`), terminated by `data: [DONE]`. A one-line opt-in,
`->usingVercelDataProtocol()`, re-encodes the same stream as the **Vercel AI SDK UI message stream
protocol** — which is what `ai-elements-vue` / `@ai-sdk/vue`'s `useChat` consumes, so that is almost
certainly the format this map wants. Persistence works via `use RemembersConversations`, and
`continueLastConversation($user)` expresses "one conversation per user" exactly. **However: the
already-migrated `agent_conversations` schema in this repo is stale and incompatible with v0.11 —
it has `user_id` where the package writes `participant_type` + `participant_id`, and is missing
`approval_state`. Persistence will fail until a migration fixes this.** Also note the map is wrong on
one point: `GEMINI_API_KEY` *is* set in `.env` and works — every byte sample below from the "live"
sections is a real Gemini response captured during this research.

---

### 1. Streaming an agent reply to the browser

`Laravel\Ai\Promptable::stream()` (`vendor/laravel/ai/src/Promptable.php:105`) returns
`Laravel\Ai\Responses\StreamableAgentResponse`, which implements `IteratorAggregate` **and**
`Illuminate\Contracts\Support\Responsable`
(`vendor/laravel/ai/src/Responses/StreamableAgentResponse.php:18`). Its `toResponse()`
(`:130-148`) wraps `response()->stream(...)` with `Content-Type: text/event-stream`. So returning it
from a controller is the whole integration.

```php
// modules/chat/Http/Controllers/ChatController.php (sketch — not written by this ticket)
use Illuminate\Http\Request;
use Modules\Chat\Ai\Agents\ChatAssistant;

public function stream(Request $request)
{
    $validated = $request->validate(['message' => ['required', 'string', 'max:4000']]);

    return ChatAssistant::make()
        ->continueLastConversation($request->user())   // one conversation per user
        ->stream($validated['message'])
        ->usingVercelDataProtocol();                   // for ai-elements-vue / useChat
}
```

Relevant methods on the returned object:

| Method | Source | Purpose |
| --- | --- | --- |
| `toResponse($request)` | `StreamableAgentResponse.php:130` | SSE `Symfony\...\Response`. Called implicitly on `return`. |
| `usingVercelDataProtocol(bool $v = true, ?string $messageId = null)` | `:118` | Switch to the Vercel UI message stream protocol. |
| `then(callable)` | `:68` | Fires once with a `StreamedAgentResponse` after the stream drains (`->text`, `->events`, `->usage`, `->conversationId`). |
| `each(callable)` | `:55` | Per-event callback; return `false` to break. |
| `getIterator()` | `:153` | Manual `foreach` over `StreamEvent` objects. Events are memoised, so a second iteration replays. |
| `conversationId` (property) | `:29` | Populated after the stream completes. |

Only *one* consumer may drain the stream — `getIterator()` caches into `$this->events`, and the
`then` callbacks fire at the end of the first full iteration.

### 2. The wire format

**Default protocol.** SSE. One `data:` frame per event, each frame a single JSON object, blank line
between frames, sentinel `data: [DONE]` at the end. Formatting is literally
`yield 'data: '.($event)."\n\n";` (`StreamableAgentResponse.php:143`), and `StreamEvent::__toString()`
is `json_encode($this->toArray())` (`src/Streaming/Events/StreamEvent.php:73`).

Response headers observed: `content-type: text/event-stream`, `cache-control: no-cache, private`,
`x-accel-buffering: no`.

**Verbatim sample — real Gemini call, `gemini-3.7-flash`, prompt `"Say exactly: hello world"`:**

```
data: {"id":"01a044ab-a9d0-71ab-a659-786ce7fdd532","invocation_id":"01a044ab-a5dd-73ae-9d2a-361b0efb9473","type":"stream_start","provider":"gemini","model":"gemini-3.7-flash","timestamp":1787858495,"metadata":null}

data: {"id":"01a044ab-a9d0-71ab-a659-786ce8dbb14f","invocation_id":"01a044ab-a5dd-73ae-9d2a-361b0efb9473","type":"text_start","message_id":"01a044ab-a9cf-73bf-b643-c2e60c11f1d5","timestamp":1787858495}

data: {"id":"01a044ab-a9d1-7226-9a1b-9775e01197c1","invocation_id":"01a044ab-a5dd-73ae-9d2a-361b0efb9473","type":"text_delta","message_id":"01a044ab-a9cf-73bf-b643-c2e60c11f1d5","delta":"hello world","timestamp":1787858495}

data: {"id":"01a044ab-a9d2-703d-9009-b3b4bccc9ab4","invocation_id":"01a044ab-a5dd-73ae-9d2a-361b0efb9473","type":"text_end","message_id":"01a044ab-a9cf-73bf-b643-c2e60c11f1d5","timestamp":1787858495}

data: {"id":"01a044ab-a9d4-7196-96c7-2f57d4017dbf","invocation_id":"01a044ab-a5dd-73ae-9d2a-361b0efb9473","type":"stream_end","reason":"stop","usage":{"prompt_tokens":10,"completion_tokens":2,"cache_write_input_tokens":0,"cache_read_input_tokens":0,"reasoning_tokens":84},"timestamp":1787858495}

data: [DONE]
```

Structure of a chunk: every frame carries `id` (UUIDv7 per event), `invocation_id` (constant for the
whole run), `type`, `timestamp` (unix seconds), plus type-specific fields. Text deltas add
`message_id` + `delta`. **`delta` is incremental, not cumulative** — concatenate. A multi-step run
(tool calls) produces multiple `message_id`s; `TextDelta::combine()` joins distinct message ids with
`"\n\n"` (`src/Streaming/Events/TextDelta.php:24-37`).

Event type vocabulary (`src/Streaming/Events/`): `stream_start`, `text_start`, `text_delta`,
`text_end`, `reasoning_start`, `reasoning_delta`, `reasoning_end`, `tool_call`, `tool_result`,
`tool_approval_request`, `citation`, `stream_end`, plus `Error`.

- **Completion** is signalled by exactly one `stream_end` frame (emitted once per run by
  `src/Gateway/TextGenerationLoop.php:328`, not per provider step), carrying `reason`
  (`"stop"`, `"length"`, …), then the literal `data: [DONE]\n\n`.
- **Usage metadata** rides on that same `stream_end` frame under `usage`, with the fixed shape
  `{prompt_tokens, completion_tokens, cache_write_input_tokens, cache_read_input_tokens,
  reasoning_tokens}` (`src/Responses/Data/Usage.php:33-43`). There is no separate usage event.
- **Errors** split into two very different paths, and the UI must handle both:
  1. **Before the first byte** (bad model id, auth failure, connection refused) the exception
     propagates out of `toResponse()` before any output — the browser gets a plain HTTP 500, *not* an
     SSE frame. Confirmed live: streaming `gemini-9-nope` threw
     `Illuminate\Http\Client\RequestException: HTTP request returned status code 404`.
  2. **Mid-stream**, the provider's error becomes an `Error` frame and then the run throws
     `StreamErrorException` (`TextGenerationLoop.php:271-277`) on an already-committed 200 response.
     Gotcha: `Error::toArray()` puts the *provider error code* in the `type` field, not the string
     `"error"` (`src/Streaming/Events/Error.php:23-33`; Gemini sets it from
     `$data['error']['code']` at `src/Gateway/Gemini/Concerns/HandlesTextStreaming.php:48`). Client
     code cannot detect errors by `type === 'error'` on the default protocol; it must match on the
     presence of `recoverable` / `message`. **The Vercel protocol does not have this problem** — it
     normalises to `{"type":"error","errorText":"..."}`.

**Vercel protocol.** `->usingVercelDataProtocol()` (`src/Responses/Concerns/CanStreamUsingVercelProtocol.php`)
emits the same SSE envelope with camelCase Vercel part names and adds the header
`x-vercel-ai-ui-message-stream: v1` plus `cache-control: no-cache, no-transform`.

**Verbatim sample — Vercel protocol** (captured via the package's fake gateway, which streams the
identical formatter):

```
data: {"type":"start","messageId":"01m12ant41abe9bqee764nsgx5"}

data: {"type":"text-start","id":"01m12ant41abe9bqee764nsgx4"}

data: {"type":"text-delta","id":"01m12ant41abe9bqee764nsgx4","delta":"Fake"}

data: {"type":"text-delta","id":"01m12ant41abe9bqee764nsgx4","delta":" response"}

data: {"type":"text-delta","id":"01m12ant41abe9bqee764nsgx4","delta":" for"}

data: {"type":"text-delta","id":"01m12ant41abe9bqee764nsgx4","delta":" prompt:"}

data: {"type":"text-delta","id":"01m12ant41abe9bqee764nsgx4","delta":" hi"}

data: {"type":"text-end","id":"01m12ant41abe9bqee764nsgx4"}

data: {"type":"finish"}

data: [DONE]
```

Note the tradeoff: the Vercel `finish` part is bare — **usage metadata is dropped** (`StreamEnd::toVercelProtocolArray()`
returns only `['type' => 'finish']`). If token counts need to reach the UI live, either use the
default protocol or read `usage` from the persisted message row after the stream.

Vercel mappings, for the record: `stream_start`→`start`, `text_*`→`text-start`/`text-delta`/`text-end`,
`reasoning_*`→`reasoning-*`, `tool_call`→`tool-input-available`, `tool_result`→`tool-output-available`
(or `tool-output-error` / `tool-output-denied`), `citation`→`source-url`, `Error`→`error`.

### 3. Conversation persistence

**Actual migrated schema in this repo** (`database/migrations/0001_01_01_000007_create_agent_conversations_table.php`,
verified live against MySQL with `php artisan db:table`):

```
agent_conversations                  agent_conversation_messages
  id           varchar(36) PK          id               varchar(36) PK
  user_id      bigint unsigned         conversation_id  varchar(36)  (index)
  title        varchar(255)            user_id          bigint unsigned (index)
  created_at   timestamp               agent            varchar(255)
  updated_at   timestamp               role             varchar(25)
                                       content          text
  index (user_id, updated_at)          attachments      text   (JSON, cast array)
                                       tool_calls       text   (JSON, cast array)
                                       tool_results     text   (JSON, cast array)
                                       usage            text   (JSON, cast array)
                                       meta             text   (JSON, cast array)
                                       created_at/updated_at timestamps
                                       index conversation_index (conversation_id, user_id, updated_at)
```

**This schema is stale and will break v0.11.** The package's own migration
(`vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php`)
and its writer, `Laravel\Ai\Storage\DatabaseConversationStore`, use a **polymorphic participant**, not
`user_id`:

| Repo migration | Package v0.11 expects |
| --- | --- |
| `user_id` (bigint, NOT NULL) | `participant_type` (string, nullable) + `participant_id` (bigint, nullable) |
| — | `approval_state` (text, nullable) on the messages table |
| index `(user_id, updated_at)` | index `(participant_type, participant_id, updated_at)` |

`DatabaseConversationStore::storeConversation()` inserts `participant_type`/`participant_id`
(`src/Storage/DatabaseConversationStore.php:47-62`) and `latestConversationId()` queries them
(`:35-42`). Against the current tables that is an unknown-column SQL error on the first prompt, and
`user_id` is NOT NULL with no default. **A migration to reconcile this is a hard prerequisite for the
build tickets.** Cleanest route: `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`
(the provider registers `publishesMigrations` at `src/AiServiceProvider.php:170`) after dropping/
replacing `0001_01_01_000007`, since both tables are empty.

Note there are two other, harmless gaps: `config/ai.php` in this repo has no `conversations` section
at all, and neither does the package's own default config, so all conversation config reads fall back
to defaults — table names `agent_conversations` / `agent_conversation_messages`, default DB
connection, and `ai.conversations.generate_title` defaults to **`true`**.

**API surface.** Add the trait; do *not* hand-write `messages()`, it would shadow the trait's.

```php
// modules/chat/Ai/Agents/ChatAssistant.php (sketch)
use Laravel\Ai\Attributes\{Model, Provider};
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\{Agent, Conversational};
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Gemini)]
#[Model('gemini-3.5-flash-lite')]
class ChatAssistant implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'You are a helpful general assistant.';
    }
}
```

Methods from `src/Concerns/RemembersConversations.php`:

- `forUser($user)` / `forParticipant($model)` — start a **new** conversation (nulls the id).
- `continue(string $conversationId, ?object $as = null)` — resume a specific one.
- `continueLastConversation(object $as)` — resume the participant's most recent conversation, or start
  one if none exists (`latestConversationId()` returns `null` and the middleware then creates it).
  **This is the direct expression of the map's "one conversation per user".**
- `currentConversation(): ?string`, `conversationParticipant(): ?object`.
- `maxConversationMessages()` — `protected`, defaults to **100**; override to widen/narrow replay context.

**Resuming a stored conversation, streaming the reply:**

```php
return ChatAssistant::make()
    ->continueLastConversation($request->user())
    ->stream($request->string('message')->toString())
    ->usingVercelDataProtocol();
```

**How persistence actually happens.** `Laravel\Ai\Middleware\RememberConversation` is auto-injected
whenever the agent uses the trait (`src/Providers/Concerns/GeneratesText.php:154`), and the streaming
path runs the **same** middleware pipeline (`src/Providers/Concerns/StreamsText.php:41-44`). The
middleware hangs off `->then(...)`, so rows are written **synchronously, in-process, after the stream
finishes draining** (`src/Middleware/RememberConversation.php:31-76`). Order: create conversation if
absent → insert `role = 'user'` row → insert `role = 'assistant'` row → touch
`conversations.updated_at`. If the client aborts mid-stream, the `then` never runs and **nothing is
persisted for that turn**.

One cost surprise: on the *first* message of a conversation the middleware makes a **second LLM call**
to generate the title, using the provider's cheapest text model
(`RememberConversation::generateTitle()`, `:92-110`). Set `ai.conversations.generate_title => false`
to fall back to a 50-char truncation of the prompt.

**Loading history for UI replay.** Two options:

- Package API: `resolve(Laravel\Ai\Contracts\ConversationStore::class)->getLatestConversationMessages($id, $limit)`
  returns a `Collection<Laravel\Ai\Messages\Message>`. This is shaped for *model context*, not UI — it
  reconstructs/splits tool turns, drops leading orphan tool results, and carries no ids or timestamps.
- **Preferred for the Inertia page:** query the Eloquent model directly.

```php
use Laravel\Ai\Models\{Conversation, ConversationMessage};

$conversation = Conversation::query()
    ->where('participant_type', $user->getMorphClass())
    ->where('participant_id', $user->getKey())
    ->latest('updated_at')
    ->first();

$messages = $conversation
    ? ConversationMessage::where('conversation_id', $conversation->id)
        ->whereIn('role', ['user', 'assistant'])
        ->orderBy('id')                       // UUIDv7 PKs sort chronologically
        ->get(['id', 'role', 'content', 'created_at'])
    : collect();
```

`Conversation` / `ConversationMessage` (`src/Models/`) resolve their table names from config and cast
`attachments`, `tool_calls`, `tool_results`, `usage`, `meta`, `approval_state` to arrays.

**Tying a conversation to a user.** `Conversation::participantType()` stores `$user->getMorphClass()`
and `participantKey()` stores the primary key (`src/Models/Conversation.php:63-84`). Optionally add
`Laravel\Ai\Concerns\HasConversations` to `App\Models\User` for a `conversations()` morphMany — **it is
not currently on the User model**, and it is not required for `continueLastConversation()` to work.

**Security note from the docs, confirmed in source:** `continue($id, as: $user)` performs **no
ownership check** — it trusts the given id. Since this map uses `continueLastConversation()`, which
scopes by participant, that hazard is avoided as long as no conversation id is ever accepted from the
client. Recommend never exposing the id to the browser at all.

### 4. Gemini specifics

**Streaming is not structurally different from OpenAI.** All gateways emit the same
`Laravel\Ai\Streaming\Events\*` types, so the wire bytes are provider-independent; only
`stream_start.provider` / `.model` differ. Gemini hits
`POST models/{model}:streamGenerateContent?alt=sse` against
`https://generativelanguage.googleapis.com/v1beta/` (`src/Gateway/Gemini/GeminiGateway.php:102`,
`src/Gateway/Gemini/Concerns/CreatesGeminiClient.php:31`) and translates in
`src/Gateway/Gemini/Concerns/HandlesTextStreaming.php`. Error frames use the identical shape as
OpenAI's (compare `HandlesTextStreaming.php:48` with `src/Gateway/OpenAi/Concerns/HandlesTextGeneration.php:49`).

Two Gemini-flavoured details worth knowing:

- Gemini 3.x models **reason by default**. The live `gemini-3.7-flash` call above burned
  `reasoning_tokens: 84` to answer "Say exactly: hello world" — real latency and cost before the first
  `text_delta` arrives. `gemini-3.5-flash-lite` returned `reasoning_tokens: 0`. The UI needs a
  "thinking" affordance, or pick the lite model.
- When Gemini *does* return thought summaries, they arrive as `reasoning_start` / `reasoning_delta` /
  `reasoning_end` events interleaved before the text, gated by `isThinkingPart()`
  (`src/Gateway/Gemini/Concerns/ParsesTextResponses.php:66`). Thinking text is excluded from the
  persisted assistant `content`.

**Valid model ids** (defaults from `src/Providers/GeminiProvider.php:92-116`), both verified live:

| Role | Model id | Verified |
| --- | --- | --- |
| `defaultTextModel()` | `gemini-3.7-flash` | yes — streamed successfully |
| `cheapestTextModel()` | `gemini-3.5-flash-lite` | yes — streamed successfully, no reasoning tokens |
| `smartestTextModel()` | `gemini-3.7-flash` | same as default |
| image | `gemini-3.1-flash-image-preview` | not tested (out of scope) |
| embeddings | `gemini-embedding-2` (3072 dims) | not tested (out of scope) |

An unknown model id fails hard with a 404 `RequestException` before any bytes are written.

Config: `config/ai.php` in this repo has a `gemini` provider block with `key => env('GEMINI_API_KEY')`
but is **missing the `url` key** the package's own default config carries; the gateway falls back to
the correct hardcoded base URL, so this is cosmetic. `'default' => 'openai'` still needs changing, or
pin the provider per-agent with `#[Provider(Lab::Gemini)]` (recommended — keeps the decision next to
the agent).

**Correction to the map:** `GEMINI_API_KEY` *is* populated in `.env` and is valid. Every "live" sample
in this resolution is a real Gemini response.

### 5. Queues and broadcasting — **not required. Definitive: no.**

Streaming over HTTP needs neither a queue worker nor a broadcasting driver. `stream()` →
`toResponse()` is a synchronous `response()->stream()` over the request's own PHP process
(`StreamableAgentResponse.php:130-148`); conversation rows are written inline in the same process
(`RememberConversation` hangs off `then()`). This was demonstrated end-to-end above with a plain CLI
script — no worker running, no broadcaster.

Queues and broadcasting are strictly opt-in alternatives, all on `Promptable`
(`src/Promptable.php:199-265`):

- `queue()` → dispatches `Laravel\Ai\Jobs\InvokeAgent`; **needs a worker**. Not streaming.
- `broadcast()` / `broadcastNow()` → iterates the stream in-process and pushes each event to a channel;
  needs a broadcaster, and `broadcast()` (not `Now`) queues each event.
- `broadcastOnQueue()` → dispatches `Laravel\Ai\Jobs\BroadcastAgent`; needs **both** a worker and a
  broadcaster.

None of these is on the `return $agent->stream(...)` path. Current env (`QUEUE_CONNECTION=database`,
`BROADCAST_CONNECTION=log`) is fine as-is for this map.

Deployment caveats that *do* apply to SSE, independent of this package: the response must not be
buffered or gzipped by a reverse proxy (the package already sends `x-accel-buffering: no`, and the
Vercel protocol adds `no-transform`), PHP-FPM holds a worker for the life of each stream, and
`max_execution_time` must exceed the longest expected reply.

### Files cited

- `vendor/laravel/ai/src/Promptable.php` — `stream()`, `queue()`, `broadcast*()`
- `vendor/laravel/ai/src/Responses/StreamableAgentResponse.php` — `toResponse()`, `then()`, `usingVercelDataProtocol()`
- `vendor/laravel/ai/src/Responses/Concerns/CanStreamUsingVercelProtocol.php` — Vercel encoder
- `vendor/laravel/ai/src/Streaming/Events/*.php` — event vocabulary and JSON shapes
- `vendor/laravel/ai/src/Gateway/TextGenerationLoop.php` — single `stream_end`, `StreamErrorException`
- `vendor/laravel/ai/src/Providers/Concerns/StreamsText.php` / `GeneratesText.php` — middleware on the stream path
- `vendor/laravel/ai/src/Middleware/RememberConversation.php` — persistence + title generation
- `vendor/laravel/ai/src/Storage/DatabaseConversationStore.php` — participant columns, history reconstruction
- `vendor/laravel/ai/src/Models/Conversation.php`, `ConversationMessage.php`
- `vendor/laravel/ai/src/Concerns/RemembersConversations.php`, `HasConversations.php`
- `vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php` — correct schema
- `vendor/laravel/ai/src/Providers/GeminiProvider.php`, `src/Gateway/Gemini/**` — Gemini models and SSE
- `database/migrations/0001_01_01_000007_create_agent_conversations_table.php` — the stale local schema
