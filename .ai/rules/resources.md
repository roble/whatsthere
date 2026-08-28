---
paths:
  - 'resources/**'
---

# Resources

## Scrollbars are themed globally, never per component
Browsers paint a light-on-white scrollbar regardless of theme, so any `overflow-auto` element shows a white gutter in dark mode. This is solved once in `resources/css/app.css` under `@layer base`, not per component:

- `html { scrollbar-color: var(--border) transparent; }` — this property **inherits**, so it reaches every scroll container.
- `* { scrollbar-width: thin; }` — this one does **not** inherit, so it must be set on the elements that actually scroll.

Do not add `[scrollbar-color:…]` / `[scrollbar-width:thin]` utilities to individual components. Three separate components accumulated those copies before the global fix landed, and a fourth (the app sidebar) still shipped with a white scrollbar because it had not opted in.
