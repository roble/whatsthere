# How does ai-elements-vue install, and what message shape does it expect?

- **Type**: `wayfinder:research` (AFK)
- **Status**: closed
- **Assignee**: agent (research session)
- **Blocked by**: —

## Question

`ai-elements-vue` is a shadcn-vue registry library — copy-and-own components, not a versioned
dependency. Only `@shadcn` is registered in this project's `components.json` today. Before anything
can be built we need its actual shape, from its docs and its GitHub repo (`vuepont/ai-elements-vue`),
not from guesswork.

Find out:

1. **Install mechanism.** The registry URL, how it is added to `components.json`, and the exact CLI
   command to pull components. Can the install target a custom directory, or is it hard-wired to the
   `components.json` aliases? (This directly decides a downstream ticket.)
2. **Component inventory.** What actually ships — conversation, message, prompt input, response
   streaming, reasoning, sources, code blocks, attachments, and so on. Names and purposes.
3. **The data contract — the critical question.** Does it assume the Vercel AI SDK
   (`ai` / `@ai-sdk/vue`, the `useChat` composable and its message shape), or is it transport
   agnostic and driven purely by props? If it assumes Vercel's protocol, we have a mismatch with
   `laravel/ai` to resolve, and that is the single most important fact this ticket returns.
4. **Dependencies it drags in.** New npm packages, and whether they conflict with what is installed:
   Tailwind v4, `reka-ui` 2.x, Vue 3.5, `@lucide/vue`, shadcn-vue "new-york" style, `neutral` base.
5. **Theming and licence.** Whether it respects this project's CSS variables and dark mode, and what
   licence the copied source carries (it lands in our tree, so it matters).

## Answer should record

Registry URL and install command verbatim, the component list, a clear yes/no on the Vercel AI SDK
dependency with evidence, and any version conflicts against `package.json`.

## Resolution

**Bottom line.** There is **no runtime dependency on the Vercel AI SDK**. Every single reference to
the `ai` package across all 48 components in `packages/elements/src` is an `import type` — TypeScript
types only, erased at compile time. There are **zero** imports of `@ai-sdk/vue`, zero uses of
`useChat`, zero uses of `DefaultChatTransport`, anywhere in the library or in its own official
examples. The components are prop- and slot-driven and transport-agnostic. The `laravel/ai` mismatch
the ticket feared does not exist at the component layer; the only cost of the SDK coupling is that
`ai` must be installed (it can be a `devDependency`) so `vue-tsc` can resolve four type aliases:
`UIMessage['role']`, `ChatStatus`, `FileUIPart`, and `ToolUIPart`.

### 3. The data contract — evidence

Sources read from `https://raw.githubusercontent.com/vuepont/ai-elements-vue/main/...` and from a
shallow clone of `github.com/vuepont/ai-elements-vue` at `main` (v1.5.2, pushed 2026-08-27).

An exhaustive grep of `packages/elements/src` for `from 'ai'` returns 18 hits, and **all 18 are
`import type`**:

```
agent/AgentTool.vue:2:              import type { Tool } from 'ai'
attachments/types.ts:1:             import type { FileUIPart, SourceDocumentUIPart } from 'ai'
audio-player/AudioPlayerElement.vue:2: import type { Experimental_SpeechResult as SpeechResult } from 'ai'
confirmation/Confirmation.vue:2:    import type { ToolUIPart } from 'ai'
confirmation/context.ts:1:          import type { ToolUIPart } from 'ai'
context/Context.vue:2:              import type { LanguageModelUsage } from 'ai'
context/context.ts:1:               import type { LanguageModelUsage } from 'ai'
image/Image.vue:2:                  import type { Experimental_GeneratedImage } from 'ai'
message/Message.vue:2:              import type { UIMessage } from 'ai'
message/MessageBranchSelector.vue:2: import type { UIMessage } from 'ai'
prompt-input/PromptInputSubmit.vue:3: import type { ChatStatus } from 'ai'
prompt-input/types.ts:1:            import type { FileUIPart } from 'ai'
sandbox/SandboxHeader.vue:2:        import type { ToolUIPart } from 'ai'
tool/ToolHeader.vue:2:              import type { DynamicToolUIPart, ToolUIPart } from 'ai'
tool/ToolInput.vue:2:               import type { DynamicToolUIPart, ToolUIPart } from 'ai'
tool/ToolOutput.vue:2:              import type { DynamicToolUIPart, ToolUIPart } from 'ai'
tool/ToolStatusBadge.vue:3:         import type { DynamicToolUIPart, ToolUIPart } from 'ai'
transcription/context.ts:1:         import type { Experimental_TranscriptionResult as TranscriptionResult } from 'ai'
```

`grep -rn "useChat\|@ai-sdk" packages/` returns nothing.

Now the four components that matter for this map, verbatim.

`conversation/Conversation.vue` — no `ai` import at all. Pure props, wraps `vue-stick-to-bottom`:

```vue
<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@repo/shadcn-vue/lib/utils'
import { reactiveOmit } from '@vueuse/core'
import { StickToBottom } from 'vue-stick-to-bottom'

interface Props {
  ariaLabel?: string
  class?: HTMLAttributes['class']
  initial?: boolean | 'instant' | { damping?: number, stiffness?: number, mass?: number }
  resize?: 'instant' | { damping?: number, stiffness?: number, mass?: number }
  damping?: number
  stiffness?: number
  mass?: number
  anchor?: 'auto' | 'none'
}
```

`message/Message.vue` — the one `ai` touch, and it is a string union:

```vue
<script setup lang="ts">
import type { UIMessage } from 'ai'
import type { HTMLAttributes } from 'vue'
import { cn } from '@repo/shadcn-vue/lib/utils'

interface Props {
  from: UIMessage['role']
  class?: HTMLAttributes['class']
}

const props = defineProps<Props>()
</script>
```

`UIMessage['role']` resolves to `'system' | 'user' | 'assistant'`. It is used only for a class
toggle: `props.from === 'user' ? 'is-user ml-auto justify-end' : 'is-assistant'`. Nothing else about
`UIMessage` is consumed. `MessageBranchSelector.vue` uses the identical `from: UIMessage['role']`.

`message/MessageContent.vue` — no `ai` import; a `<div>` with a class slot. `message/MessageResponse.vue`
is the "response"/streaming-markdown component (there is **no** separate `response` component in this
Vue port; `https://registry.ai-elements-vue.com/response.json` 404s). It takes a plain string:

```vue
interface Props {
  content?: string
  class?: HTMLAttributes['class']
}
```

…and renders it through `<Markdown :content="md" />` from `vue-stream-markdown`, accepting either the
`content` prop or plain text in the default slot. That is exactly the contract a `laravel/ai` stream
needs: append tokens to a local `ref<string>` and bind it.

`prompt-input/PromptInput.vue` — no `ai` import. It is a `<form>` that emits:

```ts
const emit = defineEmits<{
  (e: 'submit', payload: PromptInputMessage): void
  (e: 'error', payload: { code: string, message: string }): void
}>()
```

with `PromptInputMessage` defined locally in `prompt-input/types.ts`:

```ts
import type { FileUIPart } from 'ai'
import type { Ref } from 'vue'

export interface PromptInputMessage {
  text: string
  files: FileUIPart[]
}
```

So the submit payload is `{ text: string, files: FileUIPart[] }`. If attachments are not used —
and this map does not use them — `files` is always `[]` and `FileUIPart` never materialises.

`prompt-input/PromptInputSubmit.vue` takes `status?: ChatStatus`, where `ChatStatus` is the string
union `'submitted' | 'streaming' | 'ready' | 'error'`. It is a pure `computed` icon switch:

```ts
const icon = computed(() => {
  if (props.status === 'submitted') { return Loader2Icon }
  else if (props.status === 'streaming') { return SquareIcon }
  else if (props.status === 'error') { return XIcon }
  return CornerDownLeftIcon
})
```

Any local `ref` holding one of those four strings satisfies it.

**Corroborating evidence.** The library's own reference implementation,
`packages/examples/src/chatbot.vue`, drives the whole chat from plain local `ref`s and a hand-rolled
`MessageType` interface. Its only AI-SDK line is
`import type { ChatStatus, ToolUIPart } from 'ai'`. No `useChat`, no transport.

**The one place the SDK does appear** is the *documentation prose*, not the code. The docs
prerequisites say "A Vue.js or Nuxt.js project with the AI SDK installed"
(https://www.ai-elements-vue.com/overview/introduction), and the Usage page example opens with
`import { useChat } from '@ai-sdk/vue'`
(https://www.ai-elements-vue.com/overview/usage). That is the documented *happy path*, not a
requirement of the components. Do not let the README mislead a later session.

**Verdict: NO.** Not coupled. It is transport-agnostic. Install `ai` as a devDependency purely to
satisfy `vue-tsc`, or — if a leaner tree is wanted — replace the four type imports by hand after
copy-and-own, since they are all trivially inlinable string unions. `@ai-sdk/vue` is never needed.

### 1. Install mechanism

Registry base URL: `https://registry.ai-elements-vue.com`, one JSON per component at
`/<name>.json`, plus a `/all.json` bundle. There is no `/index.json` (it returns
`{"error":"Component \"index\" not found."}`). The registry is generated by
`apps/registry/server/utils/registry-builder.ts` and served by Nitro on Cloudflare.

Three equivalent ways in, in increasing order of fit for this repo.

The vendor CLI, which is a 60-line shim (`packages/cli/index.js`) that literally shells out to
`shadcn-vue add` with the resolved URLs:

```bash
npx ai-elements-vue@latest add conversation message prompt-input
```

The shadcn-vue CLI with raw URLs, no `components.json` change required at all:

```bash
npx shadcn-vue@latest add \
  https://registry.ai-elements-vue.com/conversation.json \
  https://registry.ai-elements-vue.com/message.json \
  https://registry.ai-elements-vue.com/prompt-input.json
```

Or register a namespace. shadcn-vue 2.7.4 (installed here) supports a `registries` key in
`components.json`; its Zod schema (`node_modules/shadcn-vue/dist/schema-CjAXtlQ7.js`) requires the
key to start with `@` and the URL to contain a literal `{name}` placeholder:

```js
const registryConfigItemSchema = z.union([z.string().refine((s) => s.includes("{name}"), ...
const registryConfigSchema = z.record(z.string().refine((key) => key.startsWith("@"), ...
```

So the `components.json` change is exactly this one added top-level key:

```json
"registries": {
  "@ai-elements-vue": "https://registry.ai-elements-vue.com/{name}.json"
}
```

after which:

```bash
npx shadcn-vue@latest add @ai-elements-vue/conversation @ai-elements-vue/message @ai-elements-vue/prompt-input
```

Nothing else in `components.json` needs to change. `style: "new-york"`, `baseColor: "neutral"`,
`cssVariables: true`, `iconLibrary: "lucide"` are all already correct — see §5.

Framework detection is safe here: `detectFrameworkConfigFiles` matches `composer.json` and returns
`FRAMEWORKS.laravel` *before* it reaches the Inertia branch, so the CLI reads the root
`./tsconfig.json` and not a non-existent `./inertia/tsconfig.json`.

### 1b. Can the install target a custom directory? — read this before writing the downstream ticket

**Default behaviour.** Every file in the registry has `"type": "registry:component"` and **no
`target`** field. In shadcn-vue's `resolveFilePath`, a `registry:component` with no target goes to
`config.resolvedPaths.components`, which is `resolveImport(aliases.components, tsConfig)`. This
project's `aliases.components` is `@/components` and `tsconfig.json` maps `"@/*": ["./resources/js/*"]`,
so **out of the box everything lands in `/Volumes/Work/dev/whatsthere/resources/js/components/ai-elements/<group>/`** —
not in the module. That is confirmed by `resolveNestedFilePath`, which strips the longest matching
alias segment (`components`) from the registry path `components/ai-elements/message/Message.vue`,
leaving `ai-elements/message/Message.vue`.

**`--path` exists but is a trap.** `shadcn-vue add --help` lists `-p, --path <path>`, and it is
honoured, but look at what it does:

```js
if (options.path) {
  const resolvedPath = path.isAbsolute(options.path) ? options.path : path.join(config.resolvedPaths.cwd, options.path);
  if (/\.[^/\\]+$/.test(resolvedPath)) {
    if (options.fileIndex === 0) return resolvedPath;
  } else {
    const fileName = path.basename(file.path);
    return path.join(resolvedPath, fileName);
  }
}
```

When `--path` is a directory it takes **only the basename** and **flattens the entire tree into that
one folder**. Installing `conversation`, `message` and `prompt-input` with a single `--path` would
have three files all called `index.ts` (and `context.ts`, `types.ts`) overwrite each other. `--path`
is therefore unusable for multi-component installs, and `all.json` under `--path` would be a
catastrophe. **Do not build the downstream ticket on `--path`.**

**What actually works for `modules/chat/`.** Two viable options.

*(a) Repoint the alias for the duration of the install.* `resolvedPaths.components` goes through
tsconfig path resolution, and this repo already has `"@modules/*": ["./modules/*"]`. Temporarily
setting `aliases.components` to `@modules/chat/resources/js/components` resolves correctly and nests
per group — but it drops the `ai-elements/` segment (the alias-stripping branch in
`resolveNestedFilePath` only fires for aliases starting with `@/`, so it falls back to
`commonRoot.split("/").pop()`, giving `modules/chat/resources/js/components/message/Message.vue`).
It also changes where any co-installed `@shadcn` ui deps land. Fiddly.

*(b) Install to the default location, then `git mv`. This is the recommended path.* The registry
rewrites `@repo/shadcn-vue/` to `@/` server-side, so the copied source only ever contains **absolute
`@/…` imports** (`@/lib/utils`, `@/components/ui/input-group`) plus **relative `./` imports within a
component group**. Since `@/*` resolves from anywhere in the repo, moving
`resources/js/components/ai-elements/` wholesale into `modules/chat/resources/js/components/`
**requires zero import rewriting**. Tailwind also already scans it —
`resources/css/app.css` line 10 has `@source '../../modules/*/resources/js/**/*.{vue,jsx,tsx}';`.
This is the shortest correct route: run the CLI at repo root, move the directory, done.

Caveat for either route: cross-component registry deps are expressed as absolute registry URLs
(`reasoning.json` depends on `https://registry.ai-elements-vue.com/shimmer.json`), so pulling one
component can pull siblings. Install the whole set you want in one command.

### 2. Component inventory

48 components (`ls -d */` in `packages/elements/src`). Grouped as the docs group them.

**Chatbot** — the set this map cares about.

- `conversation` — scroll container for the message list, auto-sticks to the bottom, with
  `ConversationContent`, `ConversationEmptyState`, `ConversationScrollButton`.
- `message` — one chat turn. `Message` (role wrapper), `MessageContent`, `MessageResponse`
  (streaming markdown renderer — this is the "response" component), `MessageAvatar`,
  `MessageActions`/`MessageAction`, `MessageToolbar`, and the `MessageBranch*` family for
  regenerate/alternate-version paging.
- `prompt-input` — the composer. 38 files: `PromptInput` (the form + emits), `PromptInputTextarea`,
  `PromptInputSubmit`, `PromptInputBody`/`Header`/`Footer`/`Tools`, an action menu for attachments, a
  speech button, select/command/hover-card sub-primitives, and tabs.
- `reasoning` — collapsible "thinking" panel with a shimmer trigger.
- `sources` — collapsible source-attribution list.
- `suggestion` — horizontally scrolling quick-action chips.
- `loader` — spinner for AI operations.
- `shimmer` — text shimmer animation, used by `reasoning`.
- `task` — task-completion tracking list.
- `tool` — tool-call visualisation (header, input, output, status badge).
- `chain-of-thought` — stepwise reasoning with search results and images.
- `checkpoint` — a conversation restore-point marker.
- `confirmation` — human-in-the-loop approve/reject for a tool call.
- `context` — token/cost consumption readout (input, output, reasoning, cache).
- `inline-citation` — inline citation chips with a hover carousel of sources.
- `model-selector` — command-palette model picker with provider logos.
- `plan` — plan/task-planning display.
- `queue` — pending message/todo queue with attachments.
- `attachments` — file attachment chips, previews and hover cards.

**Code / vibe-coding** — `agent`, `artifact`, `code-block` (Shiki-highlighted, copy button, language
selector), `commit`, `environment-variables`, `file-tree`, `package-info`, `sandbox`,
`schema-display`, `snippet`, `stack-trace`, `terminal`, `test-results`, `web-preview`.

**Voice** — `audio-player`, `mic-selector`, `persona`, `speech-input`, `transcription`,
`voice-selector`.

**Workflow (VueFlow-based)** — `canvas`, `connection`, `controls`, `edge`, `node`, `panel`, `toolbar`.

**Utilities** — `image`, `open-in-chat`.

Minimum viable set for this map: `conversation`, `message`, `prompt-input`, `loader`, `suggestion`.
Add `reasoning` (pulls `shimmer`) and `code-block` only if wanted.

### 4. Dependencies, checked against this project

Declared npm dependencies per registry item (from the live JSON):

| Component | `dependencies` | `registryDependencies` (shadcn ui) |
|---|---|---|
| `conversation` | `@vueuse/core`, `vue-stick-to-bottom`, `@lucide/vue` | `button` |
| `message` | `ai`, `@lucide/vue`, `vue-stream-markdown` | `button`, `tooltip`, `avatar`, `button-group` |
| `prompt-input` | `@lucide/vue`, `ai`, `nanoid` | `input-group`, `dropdown-menu`, `command`, `hover-card`, `select` |
| `reasoning` | `@vueuse/core`, `vue-stream-markdown`, `@lucide/vue` | `collapsible`, + `shimmer.json` |
| `sources` | `@lucide/vue` | `collapsible` |
| `suggestion` | — | `button`, `scroll-area` |
| `loader` | — | — |
| `code-block` | `shiki`, `@lucide/vue`, `@vueuse/core` | `button`, `select` |

`all.json` would additionally drag in `media-chrome`, `@vue-flow/core`, `@vue-flow/background`,
`@vue-flow/controls`, `@vue-flow/node-toolbar`, `tokenlens`, `@rive-app/webgl2`, `motion-v`,
`ansi-to-vue3`. **Do not install `all.json` here** — it is ~9 packages of dead weight for a generic
chat page.

Conflict check against `/Volumes/Work/dev/whatsthere/package.json`:

- **`@lucide/vue` ^1.18.0 — already installed, exact match.** This is important: the registry uses
  the v1 `@lucide/vue` package name, not the older `lucide-vue-next`. `components.json` already has
  `"iconLibrary": "lucide"`. No conflict.
- **`@vueuse/core` ^14.3.0 — already installed.** Latest is 14.4.0, inside the range. No conflict.
- **`vue` ^3.5.34 — no conflict.** Every new package's Vue peer is satisfied: `vue-stick-to-bottom`
  `>=3.3.0`, `vue-stream-markdown` `>=3.0.0`, `@lucide/vue` `>=3.0.1`, `@vueuse/core` `^3.5.0`.
- **`reka-ui` ^2.9.10 — no conflict.** Nothing in the ai-elements registry depends on reka-ui
  directly. The reka-ui surface comes only via the `@shadcn` ui primitives it pulls, and shadcn-vue
  2.7.x targets reka-ui 2.x.
- **Tailwind v4 (^4.3.0) — no conflict.** The registry items carry `cssVars: null` and `css: null`;
  they ship no Tailwind config, no `@theme` block, no plugin. They are plain v4 utility classes over
  the standard shadcn token names.
- **`typescript` ^6.0.3 / `vue-tsc` ^3.3.1 — no conflict expected**, but this is the one thing to
  smoke-test after install, since `ai@7` ships its own `.d.ts` and TS 6 is new.
- **`shadcn-vue` ^2.7.3 (2.7.4 resolved) — no conflict.** It has the `registries` support and the
  `--path` flag; both were read out of the installed `dist/`.

Genuinely new packages for the minimum set: **`ai`** (7.0.83 — type-only, put it in
`devDependencies`; note it peer-depends on `zod ^3.25.76 || ^4.1.8`, so npm will also want zod),
**`vue-stick-to-bottom`** (1.0.1, tiny), **`vue-stream-markdown`** (1.0.4), **`nanoid`** (6.0.1).
Add `shiki` (4.4.3) only if `code-block` is installed.

`vue-stream-markdown` is the heaviest addition. Its runtime deps are `@markmend/ast`,
`@markmend/core`, `@stream-markdown/{core,code,math,mermaid}`, `@floating-ui/dom` and
`@vueuse/core ^14.4.0`. Its `mermaid`, `katex`, `shiki` and `beautiful-mermaid` peers are all marked
`optional: true` in `peerDependenciesMeta`, so they will not be installed. `MessageResponse.vue`
also does `import 'vue-stream-markdown/index.css'` — fine under Vite, but it is a CSS side-effect
import landing in a component file, worth knowing about for SSR (`npm run build:ssr` builds an SSR
bundle here).

shadcn ui primitives this project is **missing** and that the CLI would auto-add from `@shadcn`:
**`command`, `hover-card`, `select`** (all three for `prompt-input`), plus `spinner` if `all.json`.
Already present and reused: `button`, `button-group`, `avatar`, `tooltip`, `input-group`,
`dropdown-menu`, `collapsible`, `scroll-area`, `separator`, `badge`, `card`, `dialog`, `popover`.
Verified that this repo's `resources/js/components/ui/input-group/index.ts` already exports
`InputGroupButton`, which `PromptInputSubmit.vue` imports.

### 5. Theming and licence

**CSS variables.** Compatible, no work needed. The components use only standard shadcn token
utilities — `bg-secondary`, `text-foreground`, `text-muted-foreground`, `border-border`,
`outline-ring`, `bg-muted`. This project's `resources/css/theme.css` defines all of them as oklch
values under `:root` and `.dark`. The registry items contribute no `cssVars` and no `css` of their
own, so nothing has to be merged into `app.css`.

**Dark mode.** Supported, and it works with this project's setup. The library relies on the ambient
shadcn `dark` variant rather than shipping its own; this repo declares
`@custom-variant dark (&:is(.dark *, [data-theme="dark"] *));` at `resources/css/app.css:12`, which
covers both the `.dark` class and the `[data-theme="dark"]` attribute the library's docs mention.
The upstream troubleshooting page confirms the expectation:
"The default implementation toggles a data-theme attribute on the `<html>` element."

**One cosmetic warning.** `MessageContent.vue` styles the user bubble with
`group-[.is-user]:bg-secondary`. In stock shadcn `--secondary` is a near-grey; in this project
`theme.css` sets `--secondary: oklch(0.65 0.1229 217.1824)` (light) and
`oklch(0.75 0.1563 184.617)` (dark) — a saturated teal. User message bubbles will render teal out of
the box. Not a bug, but expect to override that class.

The same file carries a stray `is-user:dark` class, which under Tailwind v4 parses as an undefined
`is-user:` variant and is a silent no-op. Harmless; upstream quirk.

**Licence.** `LICENSE` at the repo root is **Apache License 2.0**, `Copyright 2025 cwandev`. GitHub's
API reports the licence key as `other`/`NOASSERTION` only because the file lacks the standard full
Apache text preamble it fingerprints — the file itself is unambiguous. The separately published CLI
package (`packages/cli/package.json`) declares `"license": "MIT"`. Apache-2.0 is permissive and
copy-and-own friendly; it carries an attribution requirement (retain the notice) and an express
patent grant. Since the source lands in our tree, the clean move is to keep a short attribution note
somewhere in `modules/chat/` pointing at `https://github.com/vuepont/ai-elements-vue` and Apache-2.0.

### Sources

- https://www.ai-elements-vue.com/overview/introduction
- https://www.ai-elements-vue.com/overview/usage
- https://www.ai-elements-vue.com/overview/troubleshooting
- https://github.com/vuepont/ai-elements-vue (README, LICENSE, `packages/elements/src/**`,
  `packages/examples/src/chatbot.vue`, `packages/cli/index.js`,
  `apps/registry/server/utils/registry-builder.ts`), `main` @ v1.5.2
- https://registry.ai-elements-vue.com/{all,conversation,message,prompt-input,reasoning,sources,loader,suggestion,code-block}.json
- Local: `node_modules/shadcn-vue@2.7.4/dist/registry-BZ439r4u.js` (`resolveFilePath`,
  `resolveFileTargetDirectory`, `resolveNestedFilePath`, `resolveConfigPaths`,
  `detectFrameworkConfigFiles`), `dist/schema-CjAXtlQ7.js` (`registryConfigSchema`)
- Local: `/Volumes/Work/dev/whatsthere/{package.json,components.json,tsconfig.json}`,
  `resources/css/app.css`, `resources/css/theme.css`,
  `resources/js/components/ui/input-group/index.ts`
