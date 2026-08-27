# Where do the ai-elements-vue components live?

- **Type**: `wayfinder:grilling` (HITL)
- **Status**: closed
- **Assignee**: unclaimed
- **Blocked by**: — (was `02-research-ai-elements-vue`, now closed)

## Question

These components are copied into our tree and owned by us, exactly like the Saucebase modules
themselves. So this is a real, hard-to-reverse placement decision, not a formality: it sets the
import paths every later ticket writes, and moving them afterwards touches every file.

The candidates:

- **App-level `resources/js/components/ui/`.** Where `shadcn-vue` installs by default, given the
  `@/components/ui` alias in `components.json`. Zero fighting with the CLI. Reusable by any future
  module. But it puts chat-shaped components in the shared design system, and this repo is a
  *starter kit* whose `resources/` is meant to stay generic.
- **Inside the module, `modules/chat/resources/js/components/`.** Keeps the module genuinely
  self-contained and copy-and-own, matching the Saucebase convention that a module carries its own
  assets through `module-loader.js`. But the shadcn-vue CLI may not be able to target it (blocked
  research tells us), meaning install-then-move, which fights the tool on every update.

Decide, and record how a future update to the library is applied under the choice made.

## Answer should record

The chosen directory, the exact alias/config changes needed to make the CLI cooperate, and the
re-install/update procedure.

## Resolution

**App-level / root, alongside the shared resources.** Decided by the map owner on explicit grounds:
this project exists to validate an idea, so the cost of moving things later is accepted deliberately
rather than overlooked.

This is the option that fights the tooling least. Components land in
`resources/js/components/ai-elements/` by default, which means:

- No `--path` flag, so the basename-flattening trap found by the research is sidestepped entirely
  (`resolveFilePath` in `shadcn-vue` 2.7.4 joins `basename(file.path)` onto the target directory,
  which would silently clobber sibling `index.ts` files).
- No `git mv`, so no re-import pass.
- Library updates are a plain re-run of the `add` command.

Trade-off consciously accepted: the `chat` module is no longer fully self-contained, so extracting it
as a standalone Saucebase module later would require moving these components with it. Revisit only if
this stops being a validation project.

Install command (no `components.json` change needed):

```
npx shadcn-vue@latest add https://registry.ai-elements-vue.com/<name>.json
```

Avoid `all.json` — it drags in VueFlow, rive, and media-chrome for components this map does not use.
