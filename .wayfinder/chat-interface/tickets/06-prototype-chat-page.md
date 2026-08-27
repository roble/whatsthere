# What does the chat page look like?

- **Type**: `wayfinder:prototype` (HITL)
- **Status**: closed
- **Assignee**: unclaimed
- **Blocked by**: — (`04-where-components-live` now closed)

## Question

`modules/chat/resources/js/pages/Index.vue` is still the stock scaffold — a heading and a "Get
Started" rocket card. It gets replaced wholesale. Build a cheap, rough, clickable version to react
to, with stubbed replies rather than a live provider, so layout is settled before streaming lands.

The open questions it should force answers to:

- **Height and scroll.** A chat wants a full-height column with a pinned composer and a scrolling
  transcript. `AppLayout` currently hosts pages that scroll normally in a `p-6` container. Does the
  chat fill the viewport, and what does that require of the layout?
- **Breadcrumbs and page chrome.** Keep the `AppLayout` title and breadcrumbs, or go chromeless for
  more vertical room?
- **Empty state.** What a user sees before the first message — suggestions, a greeting, or nothing.
- **Message presentation.** User versus assistant treatment, markdown rendering, streaming cursor,
  timestamps, copy/regenerate affordances — constrained by whatever the library actually ships.
- **Light and dark.** Mandatory in this codebase, both verified.
- **Mobile.** The composer must survive a virtual keyboard.

Every interactive element needs a stable `data-testid` (never text or role selectors) so the e2e
suite at `modules/chat/tests/e2e/` can drive it. All copy goes through `$t()`.

## Answer should record

A link to the prototype branch or files, screenshots in both themes, and the layout decisions the
real implementation must honour.

---

## Starting point provided

<https://www.ai-elements-vue.com/examples/chatbot> — the vendor's reference chatbot, proposed by the
map owner as the basis rather than designing from scratch.

Components it uses, which is effectively the install list for this map: `conversation`
(`Conversation`, `ConversationContent`, `ConversationScrollButton`), `message` (`Message`,
`MessageContent`, `MessageResponse`, `MessageActions`, `MessageAction`), `prompt-input`
(`PromptInput`, `PromptInputTextarea`, `PromptInputSubmit`, and attachment/select subcomponents),
`loader`, `reasoning`, and `sources`.

`reasoning` is more relevant here than it first appears: Gemini 3.x reasons by default, so there is
real pre-first-token latency that needs somewhere to go. `sources` is likely unnecessary until the
house/location map introduces retrieval — skip it for now.

Trimming decisions the prototype should make: attachments, model-picker, and web-search toggle are
all in the example but out of scope for this map's destination.

## Resolution

Built for real rather than prototyped, per the map's execution override, and verified end to end in a
browser against live Gemini: message sent, reply streamed in, page reloaded, history restored.
Confirmed in both light and dark themes.

### What shipped

- `modules/chat/resources/js/pages/Index.vue` replaces the stock scaffold. Split layout via
  `ResizablePanelGroup` (added later at the map owner's request): chat on the left, an empty
  placeholder pane on the right, with `auto-save-id="chat-split"` persisting the divider position.
  Verified the handle resizes by keyboard (`aria-valuenow` 55 → 45), so it is accessible.
- `modules/chat/src/Ai/ChatAgent.php` — implements `Agent` + `RemembersConversations`.
- `ChatController@index` seeds history as an Inertia prop; `@stream` returns the Vercel-protocol SSE
  stream. Routes now behind `web` + `auth`.
- Components installed to `resources/js/components/ai-elements/` per the placement decision.
- Seven feature tests in `modules/chat/tests/Feature/ChatStreamTest.php`, all passing, including one
  that reassembles the reply from `text-delta` frames — that is the real proof the protocol
  round-trips.

### Three defects found and fixed during verification

1. **`gemini-3.7-flash` returns a persistent 503** (`ProviderOverloadedException`) on this key, while
   `gemini-3.5-flash-lite` works. Pinned via a `models.text.default` entry in `config/ai.php` with a
   `GEMINI_TEXT_MODEL` env override, so switching back is a one-line change.
2. **`calc()` without spaces is invalid CSS.** `h-[calc(100svh-3.5rem)]` silently did nothing —
   Tailwind needs `calc(100svh_-_3.5rem)`. The layout only looked correct because the flex parent
   happened to size it. Final value subtracts a further 6px, which the map owner chose over chasing
   sub-pixel exactness.
3. **Auto-scroll during streaming was broken.** `vue-stick-to-bottom` only re-pins when its internal
   `isAtBottom` is already true, and its resize path passes `preserveScrollPosition: true`, which
   never sets that flag; the default spring (`stiffness: 0.05`) is too slow to land exactly at the
   bottom, so the flag never became true. A stiff spring fixed the *initial* scroll but not
   streaming, so the page now drives the scroll itself, releasing when the user scrolls up (verified:
   pinned within 1px while streaming; stayed put 3000px up after a manual scroll).

### Theming changes to the vendored components

The research predicted this. `MessageContent.vue` shipped `is-user:dark` plus
`group-[.is-user]:bg-secondary`, which renders as saturated teal with mismatched text in this theme —
the map owner reported it as unreadable. Now uses the contrast-paired `bg-primary` /
`text-primary-foreground`. `Conversation.vue` also gained a thin `var(--border)` scrollbar to match
the site's `ScrollBar` treatment.

### Known gaps

Attachments, model picker, web search, and the `sources` component were all deliberately left out of
scope. The right-hand pane is intentionally empty.
