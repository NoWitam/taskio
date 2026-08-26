# Taskio "next" — Component State Matrix

> The **contract** for the first tiers of the `/next` design system. Every
> component below must render **all** listed variants × sizes × states in the
> interactive styleguide gallery (`/next/_styleguide`), in **light and dark**.
> A component is not "done" until its full matrix is visible there.
>
> Tokens referenced here are defined in
> [`resources/css/next.css`](../../resources/css/next.css) and explained in
> [`design-foundations.md`](./design-foundations.md). Components reference
> **semantic tokens only** — never raw hex/hsl, never the neutral ramp directly.

## How to read this

- **Variant** = visual intent (e.g. Button `primary` / `secondary` / `ghost`).
- **Size** = scale token set (padding + text + control height).
- **State** = runtime condition that changes appearance/behavior.
- **A11y** = required keyboard + ARIA behavior, verified in the gallery.

Universal states every interactive component must demonstrate:
`default · hover · focus-visible · active/pressed · disabled`.
Plus, where applicable: `loading · invalid/error · readonly · with-icon ·
overflow/truncation · selected/checked · indeterminate`.

Universal a11y rules:
- Keyboard focus uses the `--color-next-ring` token via `:focus-visible` (already
  in base styles); never remove it without a token replacement.
- Disabled controls are non-focusable for actions but their **reason** should be
  explained where useful (tooltip / helper text), per UX rules.
- State is **never** conveyed by color alone — pair with icon, text, or shape.
- Respect `prefers-reduced-motion` (handled globally in `.next-root`).

---

## Tier 1 — Primitives

### Button

| Axis | Values |
| --- | --- |
| Variants | `primary` (solid magenta), `secondary` (neutral fill), `outline` (border + transparent), `ghost` (transparent, hover tint), `subtle` (primary-subtle fill), `danger` (solid danger), `link` (text-only, underline on hover) |
| Sizes | `xs`, `sm`, `md` (default), `lg`, `icon` (square, icon-only) |
| States | default, hover, focus-visible, active, disabled, **loading** (spinner replaces leading icon, label stays, control width stable, `aria-busy`), with-leading-icon, with-trailing-icon, icon-only, full-width |

- **Tokens:** `primary` → `bg-next-primary` / hover `--color-next-primary-hover` /
  active `--color-next-primary-active`, text `primary-foreground`. `outline`/`ghost`
  hover uses `--color-next-accent`. `danger` mirrors primary with danger tokens.
- **Loading:** disable pointer + `aria-busy="true"`; keep min-width to avoid layout
  shift; spinner inherits `currentColor`.
- **A11y:** real `<button>` (or `<a>` for `link`); `disabled` sets `disabled` attr
  (or `aria-disabled` + prevented handler when a reason tooltip is needed); icon-only
  buttons require `aria-label`; Enter/Space activate.

### Link

| Axis | Values |
| --- | --- |
| Variants | `default` (inline, primary color), `muted` (muted-foreground), `standalone` (with optional external/▶ icon) |
| States | default, hover (underline), focus-visible, visited (optional), disabled, external (trailing icon + `rel="noopener"`) |

- **Tokens:** `text-next-primary`; hover underline; muted variant `text-next-muted-foreground`.
- **A11y:** real `<a href>`; external links announce target; never use a link for an
  action that should be a button.

### Badge

| Axis | Values |
| --- | --- |
| Variants | `neutral`, `primary`, `success`, `warning`, `danger`, `info` — each in **solid** and **subtle** tones |
| Sizes | `sm`, `md` |
| States | default, with-leading-icon, with-dot, count (numeric), removable (trailing ✕, focusable), truncated (max-width + ellipsis) |

- **Tokens:** subtle uses `*-subtle` / `*-subtle-foreground`; solid uses `*` / `*-foreground`.
- **A11y:** decorative badges are `aria-hidden` only if duplicated in text; status
  badges must include a text label or `aria-label` (color is not the only signal);
  removable badge ✕ has `aria-label` and is keyboard-removable.

### Avatar

| Axis | Values |
| --- | --- |
| Variants | image, initials (fallback), icon (fallback) |
| Sizes | `xs`, `sm`, `md`, `lg`, `xl` |
| States | loaded, image-error→initials fallback, with status dot (online/away/offline as color **+** shape), grouped/stacked (with `+N` overflow chip) |

- **Tokens:** initials bg `--color-next-muted` / `--color-next-accent`; status dot
  uses status tokens + distinct positions.
- **A11y:** `alt` for image avatars; initials/icon fallback has `aria-label` with the
  full name; status conveyed with text in `aria-label`, not color alone.

### Spinner

| Axis | Values |
| --- | --- |
| Sizes | `xs`, `sm`, `md`, `lg` |
| Variants | `current` (inherits text color), `primary`, `muted`, on-overlay |
| States | indeterminate (default), with-label (e.g. "Loading…"), centered-in-container |

- **A11y:** `role="status"` + visually-hidden label or `aria-label`; honors reduced
  motion (slows/halts spin).

### Text / Heading

| Axis | Values |
| --- | --- |
| Heading levels | `h1`…`h6` mapped to `--text-next-4xl`…`--text-next-base`, semibold + tight |
| Text variants | `body` (base), `ui` (sm, default UI text), `caption` (xs muted), `lead` (lg), `mono` |
| Tones | `default` (`fg`), `muted` (`muted-foreground`), `primary`, `danger`, `success`, `inverted` |
| States | default, truncate (single line), clamp (N lines), no-wrap, balanced (headings) |

- **A11y:** heading **level** is independent from visual **size** (don't skip levels
  for styling — pass an `as`/`level` prop); decorative emphasis never replaces a real
  heading where structure matters.

---

## Tier 2 — Layout

### Card

| Axis | Values |
| --- | --- |
| Variants | `default` (card bg + border), `elevated` (+ shadow), `interactive` (hover/focus affordance, whole-card clickable), `muted` (muted bg), `inset`/`flush` |
| Regions | header (title + actions), body, footer (metadata area), optional media/header-strip |
| States | default, hover (interactive only), focus-visible (interactive only), selected, disabled, loading (skeleton body), empty (slot for EmptyState) |

- **Tokens:** `bg-next-card`, `border-next-border`, `shadow-next-sm/md`,
  `rounded-next-lg`; selected uses `--color-next-accent` ring/tint.
- **A11y:** interactive cards expose a **single** primary action as the accessible
  target (link/button), not nested competing controls; consistent footer/metadata
  region per UX rules.

> Layout primitives without rich visual state (Container, Stack, Grid, Surface,
> AppShell, Sidebar, Navbar) still get a gallery page showing their structural
> props/breakpoints, but are not enumerated here as state matrices.

---

## Tier 3 — Form controls

### FieldShell — the bordered control box (shared)

Every **text-like** control (TextInput, NumberInput, Select trigger, the Slider's
inline number box) renders inside a single shared **FieldShell** that owns the
**border** and the **state line**. Controls only own their inner focusable element
and any affordances; they hand those to the shell's `#leading` / `#trailing` slots.
Textarea does **not** wrap FieldShell (its height is free) but **mirrors** the same
surface + state-line CSS so the whole family looks identical.

**The state line is ON the border (offset 0).** It is drawn as an **inset ring**
(`box-shadow: inset 0 0 0 1.5px <token>`) plus a matching `border-color`, never an
outward `outline` / `outline-offset`. So it sits exactly where the border is.

**States** (each a distinct, token-driven line treatment; all work light + dark):

| State | Border / line treatment | Token |
| --- | --- | --- |
| `default` | 1px neutral border, no ring | `--color-next-input` |
| `hover` | border deepens to ~30% foreground (only when no state line owns the color) | `color-mix(fg 30%)` |
| `readonly` | neutral border, muted fill, not editable (orthogonal — can still be error/success) | `--color-next-input` + `--color-next-muted/50` |
| `disabled` | neutral border, muted fill, dimmed (opacity), inert | `--color-next-muted` |
| `focus` | colored **inset ring + border** ON the border (offset 0) | `--color-next-ring` (primary) |
| `error` | **exactly** the focus treatment, in danger | `--color-next-danger` |
| `success` | **exactly** the focus treatment, in success | `--color-next-success` |
| `dirty` | subtler accent: **border-color only** in primary, **no ring** (reads quieter than a validated/focused field) | `--color-next-primary` |

- `focus` and `error` share the **same shape**, only the color differs — focusing an
  invalid field stays danger (no jarring flip to primary). `success` is the same.
- `dirty` = "value changed from its initial value but **not yet validated**". It is
  tracked automatically by FormField (controls register their value) and only shows
  when there's no error/success verdict and the field isn't focused.

**State precedence (final):** `disabled > error > success > focus > dirty > default`.
`readonly` is **orthogonal** — it only changes fill/cursor and is layered on top of
the resolved colored state (a readonly field can still be flagged error/success).

**Adornments:** named `#leading` / `#trailing` slots render INSIDE the border,
vertically centered (icons, units, a clear button, a password toggle, a stepper).
They never overlap the control text — the control drops its edge padding where an
adornment provides the inset.

**Multiple controls in one field:** the shell's default slot accepts one OR several
bare controls in a row, sharing one border + one state line. `segmented` weights
each child evenly and draws a thin internal rule between segments (split field). The
internal dividers **track the state color**: neutral at rest, primary on dirty, ring
on focus, danger on error, success on success — exactly like the outer line.

> **Divider mechanism (implementation note).** The resting line color is set inline as
> `--field-line-rest`; the ACTIVE `--field-line` is then resolved **in the stylesheet**
> (`--field-line: var(--field-line-rest, …)`). This is deliberate: an inline
> `--field-line` would outrank the `:focus-within` rule and freeze the color at rest, so
> `border-color` (and the dividers, which read `--field-line` via `--field-divider`)
> would never follow focus — even though the box-shadow ring painted the focus color.
> Keeping `--field-line` stylesheet-resolved lets `:focus-within` / state rules override
> it so the dividers track focus/error/success/dirty.

**No-grow rule:** content **never** changes the control's width or height. Height is
fixed per size (`sm` h-8 / `md` h-10 / `lg` h-12); overflowing text **truncates with
an ellipsis** (`min-w-0` + `truncate`); the field never grows to fit content. The
**Textarea is the only height exception** — it may auto-grow in height; its width
stays fixed.

| Axis | Values |
| --- | --- |
| Props | `size` (sm/md/lg), `focused?`, `disabled`, `readonly`, `error`, `success`, `dirty`, `segmented` |
| Slots | `default` (one or several controls), `leading`, `trailing` |
| States | default, hover, focus, error, success, dirty, disabled, readonly (+ all in light + dark) |

### FormField (wrapper)

FormField owns labelling + description + the validation **message**, and forwards a
resolved **validation state** down to the control's FieldShell. It can host one OR
several controls under a single label/description/message.

- Tracks **dirtiness**: controls register a value-reader; the field is `dirty` when
  any registered value differs from its first-seen value (pre-validation).
- Surfaces a `validationState` of `error | success | dirty | none`
  (precedence `error > success > dirty > none`) via provide/inject **and** a scoped
  slot, so context-aware and hand-wired controls both work.
- Renders the right **message styling**: `error` (danger + `alert-circle`,
  `role="alert"`) takes priority; otherwise `success` (success + `check-circle`,
  `role="status"`). Never color alone — always icon + message.
- Controls still work **standalone** (own id/state) with no FormField.

| Axis | Values |
| --- | --- |
| Slots/props | label, required asterisk, description, `error`, `success`, control slot(s) |
| States | default, required, with-description, error (message visible), success (message visible), dirty (subtle line, no message), disabled (dims label + control), readonly, multiple-controls |

### The `ariaInvalid` invariant (project rule)

Every control in this tier resolves its own invalid state the same way:

```ts
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
```

**An explicit `ariaInvalid` prop wins; absence hands the verdict to the surrounding
FormField.** That reading only holds while absence is genuinely `undefined`. A
`boolean`-typed prop declared with no explicit default gets Vue's boolean **cast** — an
absent attribute becomes `false` — and `false ?? x` is `false`, so the `field` branch
becomes unreachable: a FormField carrying a server error hands its control **nothing**.
No `aria-invalid` for assistive technology, no error line on the FieldShell, and a
`focusFirstError()` helper querying `[aria-invalid="true"]` finds nothing to focus. This
was a live, app-wide defect across the `ui/forms` control family (rationale in
`resources/js/next/ui/forms/formField.ts`; pinned by the sweep in
`ui/forms/__tests__/ariaInvalidDelegation.spec.ts`, which covers 18 controls + 7
forwarding wrappers).

**Rule — binding on every control and every wrapper, permanent, not a one-time cleanup:**

- Declare `ariaInvalid: undefined` in the control's `withDefaults` — never `false`, and
  never omit the default and let Vue's boolean cast supply one.
- **Any wrapper that forwards the prop to an inner control** (e.g. `UserSelect`,
  `LabelSelect`, `FormSelect`, `PipelineSelect`, `TemplateSelect`, `BotSelect`,
  `KnowledgeBaseSelect` → `Select`) must repeat `ariaInvalid: undefined` on its **own**
  prop declaration. The fix stops exactly at the first wrapper that redeclares
  `ariaInvalid` with an implicit `false` default — that is how seven wrappers broke the
  first time, silently, with no error at any call site.
- The bare-attribute shorthand (`<TextInput aria-invalid />` → `true`) must keep working
  — opting out of the boolean **cast** is not the same as opting out of the boolean
  **shorthand**.
- A new control or wrapper added anywhere under `ui/forms/` (or `ui/editor/`) must be
  added to the `CONTROLS`/`WRAPPERS` sweep in `ariaInvalidDelegation.spec.ts` — a control
  missing from that list is a control nobody checks.

### TextInput

| Axis | Values |
| --- | --- |
| Types | text, email, password (with show/hide toggle), search (with clear), url, with-prefix/suffix addon |
| States | placeholder, default, hover, focus, filled, disabled, readonly, error, success, dirty, loading (trailing spinner), with-leading-icon, with-clear-button, overflow/truncation (long value truncates, field does not grow) |

- Renders through **FieldShell**; the leading/trailing icon, clear button, password
  toggle, and loading spinner live in the shell's `#leading` / `#trailing` slots.
- Long values **truncate** (`truncate` + `min-w-0`); the field never resizes.

### Textarea

| States | default, focus, filled, disabled, readonly, error, success, dirty, auto-grow (height only), with character counter, resize-none vs resize-y |

- Uses FieldShell's **visuals** (same state line) but may **grow in height**
  (`autoGrow`); **width stays fixed**. Keeps the counter + resize options.

### Select (native + custom)

| Axis | Values |
| --- | --- |
| Variants | single, with placeholder, grouped options, with leading icon per option |
| States | closed/default, hover, focus, open (listbox), option hover/active, **selected**, disabled, disabled-option, error, loading options, empty options ("No results") |

- **A11y (custom):** `role="combobox"`/`listbox`, `aria-expanded`, `aria-activedescendant`;
  full keyboard (↑/↓ move, Enter select, Esc close, type-ahead); focus returns to trigger on close.
- **Trailing control order (project rule):** the conditional clear **✕** renders
  BEFORE the permanent open/close **chevron** (`[✕][chevron]`). The chevron is always
  visible and must never move; the ✕ sits to its left in reserved space (visibility
  toggled, layout stable — no-grow). This ordering applies to ANY control where a
  conditional affordance coexists with a permanent one.
- **Multi chips overflow (dynamic):** chips render in a single clipped row; the number
  shown is **measured from real available width** (refs + `ResizeObserver` via the
  shared `useChipOverflow` composable) — as many WHOLE chips as physically fit, the rest
  collapse into a **`+N`** pill. Recomputed on resize / selection / `chipMaxWidth` change.
  This is **not** a fixed count threshold. Chip labels **never wrap** to a second line
  (`whitespace-nowrap` on the Badge label + the hidden measuring row), so the fit math is
  measured against single-line widths.
- **`+N` overflow affordance (shared `ChipOverflow.vue`):** identical to PillGroupInput.
  Hover/focus → a read-only **Tooltip** of the hidden labels; click / Enter / ArrowDown →
  a **teleported** (`<body>`, `.next-overlay-root`, `--z-next-popover`, anchored via
  `useAnchoredPosition`) interactive **dialog** listing each hidden value with a per-item
  remove ✕. Esc / outside-click close (the teleported panel counts as "inside"); focus
  moves into the panel on open and back to the `+N` pill on close — all with
  `{ preventScroll: true }`. One implementation, used by **both** Select and PillGroupInput.
- **Options popover is teleported (overflow-safe):** the listbox popover is **teleported
  to `<body>`** and positioned `fixed` against the trigger via `useAnchoredPosition`
  (`--z-next-dropdown`, `min-width` matched to the trigger), so an overflow-clipped/
  resizable ancestor can never trap it. Outside-click (incl. the teleported panel), Esc,
  keyboard nav, `aria-controls`/`aria-activedescendant`, and reposition-on-scroll/resize
  all keep working through the teleport.
- **`chipMaxWidth` (opt-in truncation):** when set (number = px, or any CSS length)
  each chip is capped + truncated with an ellipsis; when omitted chips render at their
  **natural width** and are never internally truncated (overflow is handled by `+N`).
- **Async loading uses skeletons:** the initial options-loading state AND the bottom
  "loading more" row render **option-row-shaped skeletons** (icon circle + text line,
  several rows) instead of a Spinner + "Loading…" (per the Skeleton usage rule).
- **Custom-render slots (for global wrappers):** consumers can fully customize how
  options, selected chips, and the single-value display render — without forking Select:
  - `#option="{ option, selected, active }"` — option-row content (defaults to leading
    icon + label; the multi checkbox / single check affordance stays owned by Select).
  - `#chip="{ option, remove }"` — selected-chip content in multiple mode (defaults to the
    removable Badge; `remove()` deselects). The hidden measuring row mirrors this slot so
    the `+N` overflow math stays correct.
  - `#value="{ option }"` — the selected-value display in single mode (defaults to icon +
    label).
  `SelectOption` carries an **index-signature passthrough** (`[key: string]: unknown`) so a
  wrapper can attach arbitrary data (`avatar`, `color`, `icon`, …) and read it back in the
  slots. All existing (slot-less) usages are unchanged — the defaults are identical.

### UserSelect / LabelSelect (global Select wrappers)

| Component | Renders | v-model | Notes |
| --- | --- | --- | --- |
| `UserSelect` | Avatar (initials fallback) + name (+ email in options) via `#option`/`#chip`/`#value` | id (`string \| null`) or `string[]` (`:multiple`) | Loads `GET /users?cursor=&q=&per_page=20`; `:seed` preserves off-page selections. |
| `LabelSelect` | Colored label pill (label `color` as tint+accent — the inline-color **data exception**) + mapped `icon` + name via `#option`/`#chip` | `string[]` label ids; `v-model:operator` `'AND'\|'OR'` | Loads `GET /labels?cursor=&search=`. The **AND/OR operator** is a `SegmentedControl` **inside the dropdown** `#header`, shown only when ≥2 labels are selected and `v-model:operator` is bound — never an external control. |

- **Label icon mapping (`forms/labelIcon.ts`):** the backend `IconEnum` value is mapped to
  the local next Icon set (explicit aliases → exact name → generic `tag` fallback), so an
  unmapped/missing icon still renders a sensible glyph. Icons added for this:
  `tag`, `flag`, `circle`, `bookmark`.
- Both wrappers expose an optional `fetchOptions` loader override (defaults to the real
  endpoint) for the styleguide gallery + tests; everything else inherits Select's a11y,
  keyboard, skeletons, teleported popover, and overflow behavior.

### Checkbox

| States | unchecked, checked, **indeterminate**, hover, focus, disabled (each of un/checked), error, with description |

- **A11y:** real `<input type=checkbox>` or `role="checkbox"` + `aria-checked="mixed"` for indeterminate; Space toggles.

### Radio / RadioGroup

| States | unselected, selected, hover, focus (roving tabindex within group), disabled (item + whole group), error, horizontal vs vertical layout |

- **A11y:** `role="radiogroup"` with label; arrow keys move selection; only the selected (or first) radio is tab-focusable.

### Switch

| States | off, on, hover, focus, disabled (off/on), loading (pending async toggle), **error**, with leading/trailing label |

- **Error skin (mirrors Checkbox/Radio exactly):** the danger border draws on the track
  only while the switch is **off** — an already-**on** switch keeps its primary fill, the
  same idiom Checkbox/Radio use (a checked/selected state outranks invalid too). Never
  color alone: the surrounding FormField still renders the message + alert icon
  underneath. Reads its state from the field the same way every other control does — see
  the `ariaInvalid` invariant above.
- **A11y:** `role="switch"` + `aria-checked`; Space/Enter toggles; label clickable;
  `aria-invalid` set when in the error state.

### Slider

| Axis | Values |
| --- | --- |
| Variants | single value, range (two thumbs), with ticks/marks, with value tooltip/label, **with integrated inline number field** (single + range), with prefix (e.g. `$`) |
| States | default, hover (track/thumb), focus (thumb / number box), dragging, disabled, readonly, error, min/max edge |

- **Integrated number field** (`numberField`): a compact, fixed-width number box
  aligned inline with the track (single mode → one box after the track; range mode →
  low box before, high box after). Commits on `change`, snaps to `step`, clamps to
  `[min,max]`, keeps `low ≤ high`, and stays in sync with the thumb(s). Optional
  `prefix` renders inside the box (budget-style). Box width is fixed (sized to the
  widest value) so it never resizes while typing.
- **A11y:** `role="slider"` per thumb, `aria-valuemin/max/now`, `aria-label`;
  ←/→ step, Home/End jump, PageUp/Down larger step. Each number box has its own
  `aria-label` so it reads as a separate entry path for the same value.

### NumberInput

| Axis | Values |
| --- | --- |
| Variants | with **subtle stepper** (compact stacked chevrons, default), **steppers hidden** (`:steppers="false"`), with unit/affix (prefix/suffix as adornments) |
| States | default, focus, hover, disabled, readonly, error, success, dirty, out-of-range (flags `aria-invalid` + error line, keeps value), at min/max edge (the relevant chevron disables) |

- **Subtle stepper change:** the old large, full-height +/- buttons are **removed**.
  Stepping is now a **compact pair of stacked chevrons** (tiny up/down) in the
  trailing adornment area, and is **optional** via `:steppers="false"`. The default
  look is a clean text field, not a control dominated by buttons. Steppers are
  hidden automatically when disabled/readonly.
- **A11y:** `<input type=number>` with `role="spinbutton"` + `aria-valuenow`; native
  ↑/↓ step; the chevron buttons have `aria-label`s and are `tabindex="-1"` (the field
  is the tab stop); respects min/max/step.

### Specialty inputs — ColorInput · IconInput · FileDropzone · PillGroupInput

Bespoke pickers (no new dependencies) reaching parity with the legacy input set.
Each is light + dark, full keyboard + ARIA, and shows the field states below.

| Component | Model | Shape | Picker / surface |
| --- | --- | --- | --- |
| `ColorInput` | `string \| null` (`#rrggbb`) | FieldShell trigger (swatch + hex) | `FieldPopover`: SV square + hue slider + hex input + preset swatches |
| `IconInput` | `IconName \| null` | FieldShell trigger (Icon + name) | `FieldPopover`: searchable `role="grid"` of `ICON_NAMES` |
| `FileDropzone` | `File[]` or `File \| null` | **dashed dropzone region** (not FieldShell) | inline file list + parent-driven progress |
| `PillGroupInput` | `string[]` | **FieldShell-mirrored** surface (pills + text input; like Textarea) | optional `FieldPopover` suggestion `listbox` |

| Axis | Values |
| --- | --- |
| Sizes | `sm`, `md`, `lg` (ColorInput / IconInput / PillGroupInput — fixed height, no-grow). FileDropzone has no size scale (it's a region). |
| States | default, hover, focus, **error**, **success**, **dirty**, disabled, readonly, **filled** vs **empty**, clearable (where sensible) |

- **ColorInput** — picker = an SV (saturation/value) square + a hue slider +
  a hex text input (validates `#rgb`/`#rrggbb`) + a preset swatch palette (selected
  swatch gets a check **+** ring, color is never the only signal). The SV/hue handles
  are keyboard-operable (arrows; Shift = larger step; hue Home/End).
  **Color-token exception (accepted):** the swatch, SV square, hue track, and preset
  buttons render the user's **chosen arbitrary color** inline (`background`) — that is
  **data being edited, not theming**, so inline color is allowed here. All chrome
  (borders, text, focus ring, panel surface) still uses semantic tokens only.
  **Alpha is deferred** — the model is an opaque `#rrggbb`.
- **IconInput** — popover holds a searchable, scrollable `role="grid"`; each icon is a
  `role="gridcell"` with `aria-selected` and an `aria-label` of its name. Roving
  `tabindex`; ←/→ within a row, ↑/↓ between rows, Home/End row ends, Enter/Space select,
  Esc close. Clearable. Empty state has a text message.
- **FileDropzone** — **shape note:** it is **not** FieldShell-shaped; it is a **dashed
  dropzone region** that **mirrors** the FieldShell state-line tokens (focus = ring,
  error = danger, success = success, disabled = muted/dimmed) so it reads as part of the
  family. Click-to-browse + drag-and-drop (dragover highlight), `multiple`, `accept`
  (mime/ext filter + validation), `maxSize`/`maxFiles` validation with messaging, a
  selected-files list (name, size, type icon, remove; image thumbnails via
  `URL.createObjectURL` revoked on remove/unmount), and **optional per-file progress
  bars driven by a `:progress` prop map** — the component renders progress a parent
  feeds it and **does not upload**. Focusable `role="button"`; Enter/Space open the
  dialog; constraints via `aria-describedby`; added/rejected files announced politely;
  `role="progressbar"` per bar.
- **PillGroupInput** — tag input: type + Enter (and optionally comma / paste) to add a
  pill, Backspace removes the last when empty, each pill removable by mouse + keyboard.
  Dedupe + optional `validate` predicate + `max` count + `allowed`/`suggestions`
  autocomplete (`FieldPopover` `listbox`). Like **Textarea**, it does **not** render
  through FieldShell (whose height is fixed) but **mirrors** its surface + state-line
  (same `--field-line` var + precedence). **No-grow approach (two overflow modes):**
  - **`overflow="scroll"` (default)** — the pill wrap area is capped at a fixed
    **max-height** derived from `maxRows` and **scrolls internally**; the outer box
    never grows unbounded (`maxRows=1` → single scrolling row), and an empty field
    still matches the size scale via a `min-height`. The inner scroll track is **inset
    from the border** and the colored state line is drawn on an **overlay above the
    content** so scrolled pills never bleed over the border and the ring stays fully
    visible around the whole perimeter, scrollbar included (`scrollbar-gutter: stable`).
  - **`overflow="collapse"` / `:collapse`** — the control keeps the **same single-row
    fixed width + height** (standard FieldShell height for its size). Pills that don't
    fit are hidden and replaced by a **`+N` pill**; fit is measured by the **shared
    `useChipOverflow` composable** (`ResizeObserver` + a hidden measuring row, recomputed
    on resize / value changes) — the **same** implementation Select's multi mode uses (no
    duplicated overflow logic). The row **never scrolls horizontally** (overflow is
    clipped and `scrollLeft` is reset on focus) so the first chip's left edge is always
    fully visible. The `+N` pill + its tooltip + its teleported interactive remove panel
    are the **shared `ChipOverflow.vue`** component (one implementation, used identically
    by Select): **hover/focus** shows a read-only Tooltip of the hidden values,
    **click/Enter/ArrowDown** opens a remove panel **teleported to `<body>`** (the
    `.next-overlay-root` wrapper + `--z-next-popover`, anchored to the `+N` pill via
    `useAnchoredPosition`) so it renders ABOVE everything instead of being clipped; Esc /
    outside-click close, with focus moved into the panel (`{ preventScroll: true }`) and
    restored to `+N` on close.

  The text field is a `role="combobox"` wired to the suggestion `listbox`.

---

## Tier 3 — Date & time

The date/time input family — `DatePicker`, `TimePicker`, `DateTimePicker`,
`DateRangePicker`, `MonthPicker` — is **bespoke** (no third-party date library, no
new dependencies). They share two new building blocks plus a pure engine:

- **`date/dateCore.ts`** — a Vue-free engine: date math (add/sub days/months/years,
  start/end of month, 6×7 calendar grid honoring `weekStartsOn`, same-day / in-range
  / clamp / `isDateDisabled`), tolerant parsing + token formatting, and ISO
  (de)serialization. Localized month/weekday names come from `Intl.DateTimeFormat`
  (no bundled locale data). **Pure + unit-testable.**
- **`FieldPopover.vue`** — a small anchored dropdown panel (trigger in the default
  slot; panel **teleported to `<body>`** — `.next-overlay-root` wrapper, `--z-next-popover`
  — and positioned `fixed` against the trigger via `useAnchoredPosition`). Teleporting is
  deliberate: an absolutely-positioned panel was clipped by any overflow-hidden/clip
  ancestor; anchoring to `<body>` floats it above the page and fixes **every** consumer at
  once (Date/Time/DateTime/DateRange/Month pickers, ColorInput, IconInput, PillGroupInput
  suggestions). Outside-click + Esc close (the teleported panel counts as "inside"; Esc
  returns focus to the trigger with `{ preventScroll: true }`); panel is positioned BEFORE
  focus moves into it (and `autofocus` uses `{ preventScroll: true }`) so a body-teleported
  panel never scroll-jumps; reposition kept in sync on scroll/resize; optional
  `matchTriggerWidth`; token-driven enter/leave motion; `v-model:open`. Reused by every
  picker.
- **`date/CalendarPanel.vue`** — the month grid: header (month/year + prev/next +
  quick month/year jump menus), 6×7 day grid (always 6 rows so the popover height is
  stable), today marker, selected day, `range` mode (two endpoints + live hover
  preview), and disabled days (min/max + `disabledDate` predicate). Full grid
  keyboard nav; `role="grid"`/`gridcell`, `aria-selected`, `aria-current="date"`.

### Model-serialization contract (IMPORTANT)

Values are carried in `v-model` as **ISO strings, never `Date` objects**, to avoid
timezone/DST drift (a `Date` is a UTC instant; rendering it back to a local calendar
day can shift across the date line). All internal math uses local `Date`s built from
explicit y/m/d fields and serialized **without** `toISOString()`:

| Component | Model type | Serialized as |
| --- | --- | --- |
| `DatePicker` | `string \| null` | `yyyy-mm-dd` (plain calendar day) |
| `MonthPicker` | `string \| null` | `yyyy-mm-01` (1st of the month) |
| `TimePicker` | `string \| null` | `HH:mm` or `HH:mm:ss` (24h wall-clock) |
| `DateTimePicker` | `string \| null` | `yyyy-mm-ddTHH:mm[:ss]` (LOCAL ISO, **no** `Z`/offset) |
| `DateRangePicker` | `{ start, end }` | each endpoint a `yyyy-mm-dd` (or null) |

The backend owns the timezone; the UI never invents one. `DateTimePicker` emits a
zone-less local datetime so a server can attach the user's tz.

### Locale props (all overridable; Polish defaults)

Every picker accepts: `locale` (BCP-47, default `pl`), `weekStartsOn` (`0`–`6`,
default `1` = Monday), `format` (date display/parse, default `dd.mm.yyyy`); time
controls add `hour12` (default `false` → 24h), `minuteStep`, `seconds`. Month/weekday
names render through `Intl` so any locale works without bundled data.

### Shared field behavior

| Axis | Values |
| --- | --- |
| Sizes | `sm`, `md`, `lg` (render through `FieldShell`, fixed height — no-grow rule) |
| States | default, hover, focus, disabled, readonly, error, success, dirty, **filled**, **typed-invalid** (a full but impossible/out-of-range entry flags `aria-invalid`) |
| Affordances | typeable text input + picker popover, clear (✕) button, placeholder, `min`/`max`, `disabledDate` |

- **DatePicker:** typeable `dd.mm.yyyy` with live parse/clamp; calendar popover.
- **TimePicker:** typeable `HH:mm`; steppered hour/minute (+ optional seconds)
  columns; `hour12` AM/PM toggle; `minuteStep` (snaps on open).
- **DateTimePicker:** calendar + time row in one popover; **Apply** commits the
  composed local ISO datetime (picking a day keeps the popover open).
- **DateRangePicker:** two text segments under one label (FormField multi-input
  demo, managed internally); preset shortcuts (Polish: Dzisiaj, Wczoraj, Ostatnie 7
  dni, Ostatnie 30 dni, Bieżący/Poprzedni miesiąc); two months side-by-side ≥
  `next-md`, one stacked below; live range hover preview; order-tolerant endpoints.
- **MonthPicker:** year stepper + 3×4 month grid, no day grid. **Clicking the year
  header opens a paged 12-year grid** to jump directly to a year (prev/next pages,
  `←/→/↑/↓` move, `Enter` selects, `Esc` returns to the months view); years outside
  `min`/`max` are disabled.

- **A11y:** text triggers are `role="combobox"` with `aria-haspopup="dialog"` /
  `aria-expanded` / `aria-controls`; <kbd>↓</kbd> or click opens. Calendar grid:
  arrows = day, <kbd>PageUp/Down</kbd> = month, <kbd>Shift+PageUp/Down</kbd> = year,
  <kbd>Home/End</kbd> = week edges, <kbd>Enter/Space</kbd> = select, <kbd>Esc</kbd> =
  close + return focus. Roving `tabindex` on the focused cell; the focused day is
  announced via a polite live region. Time columns are `role="spinbutton"` with
  <kbd>↑</kbd>/<kbd>↓</kbd>. Disabled days/months are non-selectable and
  `aria-disabled`; today is `aria-current="date"` (never color-only — a marker line
  is drawn too).

---

## Tier — Data

### Skeleton

A token-driven loading placeholder that graphically mimics the element it stands
in for while content loads.

| Axis | Values |
| --- | --- |
| Variants | `text` (line, rounded ends, `width`), `circle` (`diameter`), `rect` (`width`×`height`, `radius` token) |
| Props | `variant`, `width`, `height`, `diameter`, `radius`, `count` (repeat N times), `label` (region), `role` |
| States | single shape, repeated (`count`), composed item layouts (card / option row / table row), light + dark |

- **Tokens:** base fill `--color-next-muted` with a faint `--color-next-accent` sheen;
  subtle pulse via the motion tokens; works light + dark. Color is **decorative only**.
- **A11y:** shapes are `aria-hidden`. For a loading **region**, pass `label` so the
  wrapper becomes a single polite `role="status"` live region. The pulse respects
  `prefers-reduced-motion` (the global `.next-root` rule collapses it; a local fallback
  is included).

**Project rules (permanent design-system rules):**

1. **Skeletons replace Spinner + "Loading…" for cursor-pagination loading.** Async
   lists render item-shaped skeletons for the initial load AND the "loading more" row
   instead of a spinner. (Applied in **Select** async mode: the bottom "loading more"
   row and the initial options-loading state render option-row-shaped skeletons —
   icon circle + text line.)
2. **Every skeleton placement must graphically mimic the actual element that will
   replace it, and usually SEVERAL are shown.** Compose skeletons into the item's real
   layout, and use `count` to repeat the shape.

### Table

A typed `<table>` with a column API, scoped cell slots, external sorting,
selection, and a responsive **stacked-card** strategy. States are mutually
exclusive (loading → error → empty → success).

| Axis | Values |
| --- | --- |
| Column props | `key`, `label`, `align?` (start/center/end), `width?`, `sortable?`, `hidden?` |
| Visual options | `stickyHeader`, `zebra`, `dense`, `hoverable`, `responsive` (`scroll` default / `stack`) |
| Behavior | `selectable` (checkbox column + select-all), `clickableRows` (whole-row action), `v-model:sort` (`{ key, dir }`, external), `v-model:selected` (row keys) |
| Cell slots | `#cell-<key>="{ row, value }"`, `#row-actions="{ row, index }"`, `#empty`, `#error`, `#footer` |
| States | **loading** (skeleton rows mimicking the columns — several), **error** (`#error` slot), **empty** (`#empty` slot), **success** (rows); plus row hover, selected row, sticky header |

- **Tokens:** header `bg-next-muted` / `text-next-muted-foreground`; row dividers
  `border-next-border`; hover `--color-next-accent`; selected row
  `--color-next-primary-subtle`; sticky header rides at `--z-next-raised`.
- **Skeleton-loading rule (applied):** the loading state renders **skeleton rows
  that match the column layout** (a `Skeleton` `text` line per column, a 16px rect
  in the checkbox column, several rows via `loadingRows`) — **never** a Spinner +
  "Loading…". In `responsive="stack"` it renders skeleton **cards** instead.
- **Responsive-stack rule (UX, applied):** below `next-md`, `responsive="stack"`
  re-renders each row as a **label/value card list** (the column label is the field
  label) — a real transform, **not** a shrunken/scrolled desktop table. The default
  `responsive="scroll"` keeps the table and scrolls horizontally; pick `stack` for
  data-heavy mobile views.
- **Sorting is external:** the header cycles `asc → desc → none` and updates
  `v-model:sort`; the **host reorders the rows**. The active column reflects
  `aria-sort` (`ascending`/`descending`/`none`).
- **A11y:** real `<table>` + `<th scope="col">`; sort headers are buttons toggling
  `aria-sort`; the select-all checkbox sets the native `indeterminate` +
  `aria-checked="mixed"` when partial; each row checkbox is labelled; a clickable
  row exposes its first cell as the single accessible row action (a real button);
  an optional visually-hidden `caption`.

### EmptyState

| Axis | Values |
| --- | --- |
| Variants | `default` (empty list), `search` (no-results, "clear filters" affordance via `#action`), `error` (danger tint + retry via `#action`) |
| Sizes | `sm` (in-table / in-card), `md` (page-level) |
| Slots/props | `title`, `description`, `icon?`, `#action` (primary Button), `#secondary` (link) |
| States | default, with-action, with-secondary, search-no-results, error |

- **Tokens:** icon bubble `bg-next-muted` (default/search) or `bg-next-danger-subtle`
  (error); title `text-next-fg`; description `text-next-muted-foreground`.
- **A11y:** icon is decorative; title is a real heading; the `error` variant is a
  `role="alert"` region so a failed load is announced. Actions are real
  Buttons/Links passed into the slots. Wired into **Card's `#empty`** slot.

### StatusBadge

Built **ON** the `Badge` primitive: a semantic mapping layer from a status key to
`{ variant, tone, icon|dot, label }`. **Never color-only** — every status renders
an icon (or a dot) **plus** a text label.

| Axis | Values |
| --- | --- |
| Statuses | `active`, `inactive`, `pending`, `success`, `warning`, `error`, `info`, `draft`, `archived` (+ any key via `statusMap`) |
| Sizes | `sm`, `md` (forwarded to Badge) |
| Props | `status`, `label?` (override), `statusMap?` (extend/override per domain), `tone?` (force solid/subtle) |
| States | each status (icon/dot + label), label override, tone override, custom-map entry, unknown-status fallback (neutral dot + raw key) |

**Default mapping (icon/dot + tone per status):**

| status | variant · tone | signal |
| --- | --- | --- |
| `active` | success · subtle | `check-circle` |
| `inactive` | neutral · subtle | dot |
| `pending` | warning · subtle | `clock` |
| `success` | success · solid | `check-circle` |
| `warning` | warning · solid | `alert-triangle` |
| `error` | danger · solid | `x-circle` |
| `info` | info · subtle | `info` |
| `draft` | neutral · subtle | `file-text` |
| `archived` | neutral · subtle | `inbox` |

- **A11y:** the label is always real text (never an `aria-label` on a bare colored
  shape); unknown statuses fall back to a neutral dot + the raw key as the label
  (never blank, never color-only). `statusMap` is merged **over** the defaults so a
  domain can override `draft`/etc. and add its own keys.

---

## Tier 5 — Navigation

### Tabs

| Axis | Values |
| --- | --- |
| Variants | `underline` (default — moving underline under the active tab), `pills` (segmented filled chips in a tinted track) |
| Sizes | `sm`, `md` |
| Item props | `value`, `label`, `icon?`, `badge?` (numeric/string → small Badge), `disabled?` |
| States | active, hover, focus-visible, disabled tab, with-icon, with-badge/count, **overflowing tab list** (horizontal scroll + edge fades, never wraps), controlled (`v-model`) vs uncontrolled, lazy panels |
| Activation | `automatic` (default — arrow-move selects), `manual` (arrow-move focuses; Enter/Space commits) |

- **Tokens:** active underline `bg-next-primary`; pills active chip `bg-next-card`
  + `shadow-next-xs` in a `bg-next-muted` track; inactive `text-next-muted-foreground`
  → hover `text-next-fg`; focus ring `--color-next-ring`. Edge fades use
  `from-next-bg` gradients (underline variant).
- **Overflow rule:** the tab list **never wraps** — it scrolls horizontally with
  edge fades; the focused/selected tab scrolls into view.
- **A11y:** `role="tablist"`/`tab`/`tabpanel`; roving tabindex (only the active tab
  is a tab stop); `←`/`→` (and `↑`/`↓`) move, `Home`/`End` jump, skipping disabled;
  `aria-selected`, `aria-controls`/`aria-labelledby`; panel is focusable. Lazy
  panels mount on first activation and stay mounted.

### Breadcrumbs

| Axis | Values |
| --- | --- |
| Items | `{ label, to?, href?, icon? }` — `to` → router-link, `href` → `<a>`; last item is the current page |
| Props | `separator` (icon, default `chevron-right`), `maxVisible` (collapse threshold, 0 = off), `ariaLabel` |
| States | linked crumb, current page (plain text), with-icon, **truncated label**, **collapsed middle** (`…` menu when items > `maxVisible`) |

- **Tokens:** links `text-next-muted-foreground` → hover `text-next-fg` + underline;
  current page `text-next-fg` (medium); separators `text-next-muted-foreground/60`.
- **Collapsing:** the first crumb + the last `maxVisible − 1` crumbs stay visible;
  the middle collapses into a single `…` **DropdownMenu**; hidden crumbs emit
  `navigate` (the host routes — menu items are not links). Long labels truncate.
- **A11y:** `<nav aria-label="Breadcrumbs">` + `<ol>`; the current page is the only
  non-link and carries `aria-current="page"`; separators are `aria-hidden`; the
  collapse trigger is a real menu button.

### Pagination

Classic **offset** (numbered) pagination. (Cursor-pagination UIs use infinite
scroll + skeletons instead — see the Skeleton rules.)

| Axis | Values |
| --- | --- |
| Sizes | `sm`, `md` |
| Props | `pageCount`, `v-model` (current page), `siblingCount`, `boundaryCount`, `disabled`, `total?`, `pageSize?`, `showSummary`, `pageSizes?`, i18n labels |
| States | default, current page (`aria-current="page"`), disabled prev/next at edges, ellipsis windowing, with-summary, with-page-size-Select |

- **Tokens:** prev/next are `outline` Buttons; the current page button is
  `bg-next-primary` / `text-next-primary-foreground`; other pages hover with
  `--color-next-accent`; ellipses are inert `text-next-muted-foreground`.
- **Windowing:** `boundaryCount` pins pages at each end and `siblingCount` shows
  pages either side of the current page; the rest collapse to `…`.
- **A11y:** `<nav aria-label="Pagination">` of real Buttons; ellipses are
  `aria-hidden`; each page button has an `aria-label`; prev/next disable at the
  edges; the optional per-page-size **Select** is labelled.

---

## Tier 6 — Patterns

Composed, higher-level components built **FROM** the existing system (Card, Grid,
Avatar, Badge, StatusBadge, Icon, TextInput, Select, DropdownMenu, ChipOverflow,
Skeleton, EmptyState). They introduce no new visual primitives — they standardize
how the lower tiers compose for recurring screen patterns. All states render in
the gallery, light + dark.

### EntityCard

The standard "domain object" card (a task / user / form), built **ON** Card with
a fixed region layout: leading visual · title · clamped subtitle · status ·
metadata footer · actions.

| Axis | Values |
| --- | --- |
| Regions | `#leading` (Avatar/Icon), title (`title` / `#title`), subtitle (`subtitle` / `#subtitle`, clamped to `subtitleLines`), status (`status` prop → StatusBadge, or `#status`), metadata footer (`meta: EntityMetaItem[]` icon+label[+value] pairs, or `#meta`), `#actions` (kebab DropdownMenu / buttons) |
| Interactive | `to` (router-link) · `href` (anchor) · `@click` (button) — whole-card single accessible action |
| States | default, hover (interactive), focus-visible (interactive, ring via Card `:focus-within`), selected (accent ring + tint), disabled (dim + inert), **loading** (skeleton mirroring avatar circle + title line + meta lines) |

- **Single accessible action (project rule):** when interactive, the title is a
  stretched link covering the whole card (`::after` inset:0) — exactly one tab
  stop + accessible name. The `#actions` cluster is raised (`z-index`) so its
  controls (status badge + kebab) stay independently clickable **above** the
  stretched link. `to` falls back to `<a>` when no router is registered.
- **Trailing order (project rule):** the conditional status badge renders
  **before** the permanent actions kebab; the kebab never moves.
- **Metadata area** is a consistent row of `icon + label [+ value]` pairs so every
  entity card reads the same.
- **A11y:** `actionLabel` names the whole-card action when the title isn't
  enough; disabled removes the action from the tab order; status is never
  color-only (StatusBadge).

### StatCard

A KPI / metric card built **ON** Card.

| Axis | Values |
| --- | --- |
| Sizes | `md` (default), `lg` (bigger value + bubble) |
| Content | `label`, `value` (big, **tabular-nums**), `icon` (tinted bubble), `delta` (signed → arrow + sign + tone), `deltaSuffix`/`deltaLabel`, `helper` footnote, `#sparkline` slot (bare — no chart lib), `#delta` slot |
| Interactive | `to` / `href` — whole-card link (stretched over the label) |
| States | default, hover/focus (interactive), **loading** (skeleton mirroring icon bubble + label + big value + delta line) |

- **Trend is never color-only (project rule):** a delta pairs the success/danger
  **tone** with a direction **arrow** (`arrow-up`/`arrow-down`) **and** an explicit
  **sign** (`+` / `−`). A zero/absent delta is neutral (flat `minus`).
- **`invertTrend`** flips only the *tone* mapping (for "down is good" metrics like
  error rate / cost): a negative delta reads success, positive reads danger — the
  arrow still follows the raw sign.
- **Tokens:** value `text-next-fg` + `tabular-nums`; icon bubble `bg-next-muted`;
  tones `text-next-success` / `text-next-danger` / `text-next-muted-foreground`.

### StatsGrid

A responsive wrapper for StatCards, built **ON** Grid.

| Axis | Values |
| --- | --- |
| Props | `cols` (number → ramp base 1 / sm 2 / N, or a per-breakpoint object), `gap`, `loading`, `count`, `loadingSize` |
| States | success (StatCards in equal-height cells), **loading** (renders `count` skeleton StatCards in the same grid — a passthrough) |

- **Equal heights:** every direct child fills its cell (`height: 100%`) so rows
  stay visually even.
- **Loading passthrough** keeps the loading layout identical to the real one
  (skeleton rule).

### Timeline (+ TimelineItem)

A vertical activity / history feed. Timeline owns the `<ol>` + the loading/empty
states; TimelineItem owns one node + content.

| Axis | Values |
| --- | --- |
| Variants | default, **compact** (dense — smaller nodes, tighter spacing; injected to items) |
| Node | a status-toned icon circle (`icon` + `tone`) OR an Avatar (`#node` slot) |
| Item content | title (`title` / `#title`), `#afterTitle`, `time` + `datetime` (`<time datetime>`), description (clamped via `clampLines` + Show more, or default slot), `#actions` |
| Item states | default, **highlighted** (accent ring on the node), **pending** (in-progress — soft pulsing node), **last** (drops the connector) |
| Feed states | success (items), **loading** (several skeleton items mimicking node + title + lines), **empty** (EmptyState `sm`) |

- **A11y:** `<ol aria-label>`; each entry an `<li>`; timestamps in a real
  `<time datetime>`; tone is decorative (meaning lives in the text). The pulse on
  `pending` respects reduced motion.

### FilterBar

The standard list-screen filter row: a slot-driven horizontal bar that wraps on
narrow screens.

| Axis | Values |
| --- | --- |
| Search | `v-model:search` (DEBOUNCED committed value), `searchable`, `searchPlaceholder`, `searchDebounce`, `searchLabel` — TextInput `type="search"` with search icon + clear |
| Controls | the default slot (Selects, DateRangePicker, switches…) — wraps per `next-` breakpoints |
| Active filters | `activeFilters: { key, label }[]` → removable Badge chips (`remove-filter`); a **Clear all** Button appears when > 1; overflowing chips collapse into the shared **`+N` ChipOverflow** pill (its remove also emits `remove-filter`) |
| Other slots | `#results` (count text), `#actions` (trailing, e.g. a New button) |
| Options | `sticky`, `noFiltersLabel`, i18n-friendly `clearAllLabel` / `searchLabel` / `ariaLabel` |
| States | empty (no chips → `noFiltersLabel` or nothing), with-chips, overflow (`+N`), sticky |

- **Debounce:** the input updates instantly (`local` ref) but only commits to
  `v-model:search` after `searchDebounce` ms (so list refetches don't fire on every
  keystroke); clearing commits immediately. Uses `useDebounce`
  (`app/composables/useDebounce.ts`).
- **Overflow (project rule):** the visible chip count is measured from real
  available width via the shared `useChipOverflow` + `ChipOverflow.vue` (the same
  mechanism Select / PillGroupInput use — no duplicated logic). A hidden measuring
  row holds every chip at natural width.
- **A11y:** `role="search"` (labelled by `searchLabel`) when search is present,
  else a labelled `group`. Chips are keyboard-removable (real Badge remove
  buttons); "Clear all" is a real Button.

> Tier 6 adds one composable — `useDebounce` (`app/composables/useDebounce.ts`):
> a `cancel()`/`flush()`-able debounce that auto-cancels on scope dispose — and two
> icons to the registry (`arrow-up`, `arrow-down`) for the StatCard trend arrows.

---

## Tier 7 — Editor (PART 1: core · PART 2: app nodes)

The flagship **advanced Markdown editor**. `v-model` is a **markdown string**;
Tiptap edits a ProseMirror doc and the bespoke `markdown.ts` (de)serializer
bridges the two. Lives in `resources/js/next/ui/editor/`. **PART 2 (mentions,
variables, if-blocks, AI chips) is implemented** — enabled per-feature via typed
props, OFF by default — see "PART 2 (implemented)" below.

### MarkdownEditor

| Axis | Values |
| --- | --- |
| Surface | FieldShell-**mirror** (like Textarea — same state line, but height grows with content; width fixed). `minHeight`/`maxHeight` bound the content; it scrolls internally past `maxHeight`. |
| Toolbar groups | history (undo/redo) · block type (paragraph / H1–H3 dropdown) · marks (bold, italic, underline, strike, inline code) · lists (bullet, ordered) · blockquote · code block · link (add/edit/remove popover) · table (insert + contextual ops menu) · horizontal rule · clear formatting |
| States | default, focus (ring on border), filled, **disabled** (not editable + toolbar disabled), **readonly** (not editable + toolbar hidden), **error**, **success**, **dirty**, **loading** (paragraph-shaped skeleton), with-counter, over-`maxlength` (soft, counter turns danger) |
| Toolbar control states | default, hover, **active/pressed** (`aria-pressed` + accent tint), **disabled** (when `editor.can()` is false), tooltip-with-shortcut |
| Block-type / table menus | full DropdownMenu (roving keyboard nav, type-ahead, Esc/return-focus); table menu only shown when the selection is **inside a table** |

- **v-model loop prevention:** `onUpdate` serializes and only emits when the
  markdown changed; `watch(modelValue)` re-parses only genuinely external values
  (≠ live serialization AND ≠ last emitted), using `setContent(…, false)` under a
  `syncingFromModel` guard.
- **FormField integration:** consumes the FormField context for
  `id`/`aria-describedby`/`aria-invalid`/disabled/readonly/dirty, exactly like the
  other controls; works standalone too.
- **A11y:** content is `role="textbox"` + `aria-multiline`; toolbar is
  `role="toolbar"`; icon buttons carry `aria-label`s, toggles `aria-pressed`;
  Tooltips expose the shortcut. Loading is a `role="status"` skeleton region.
- **Skeleton rule:** loading renders several paragraph-shaped lines (+ toolbar
  shapes), mimicking the real element — never a spinner.

### MarkdownViewer

Read-only renderer for a markdown string: `marked` → **DOMPurify** sanitize →
link post-processing (only `http(s)`/`mailto`; `rel="noopener noreferrer nofollow"`,
optional `target=_blank`). Token-styled via the shared `.next-md-prose` typography
(code/mono uses `--font-next-mono`). Used for previews and anywhere markdown is
displayed.

### FORMAT contract (the base dialect)

The authoritative spec is `resources/js/next/ui/editor/README.md` +
`markdown.ts`. The legacy `FORMAT.md` only pinned the **directive layer**; PART 1
commits the **base grammar**: headings (H1–H3), bold/italic, strikethrough
(`~~`), **underline as `<u>…</u>`** (no CommonMark syntax — legacy convention),
inline + fenced code (language preserved), links (`http(s)`/`mailto`), bullet/
ordered lists (`start` preserved), blockquote, hr, **GFM tables** with alignment.
**Task lists** (`- [ ]`) are parsed but **degrade** to bullet items (no task node
in core — legacy parity + the no-new-deps rule). Round-trip is **idempotent after
one normalization pass** (canonical forms, not byte-for-byte). **Paste:** rich
text/HTML maps onto the schema; pasted *plain markdown* stays literal (input rules
fire on typing only) — see README "Paste behavior".

### PART 2 (implemented)

Four app nodes layer on top of the core, each enabled by a typed prop
(`mentions` / `variables` / `ifBlocks` / `aiText`), **OFF by default**. They are
assembled by `buildPart2Extensions()` and merged after the core via
`mergeExtensions`; each self-registers its markdown form with the `markdown.ts`
**node registry** (`registerMarkdownNode` / `registerInlineDirective` /
`registerIfBlockParser`), following the legacy `FORMAT.md` directive encoding
(`@[type]("{\"v\":1,\"data\":{…}}")` inline, ```` ```if-block ```` fenced) **byte-for-byte**
so content stays portable between the old and new editors. The core never imports
them; a registry-less `markdown.ts` still degrades these nodes gracefully (no crash).

| Node | Surface | States |
| --- | --- | --- |
| **Mention** (`@`) | inline atomic avatar+label chip; caret-anchored suggestion popup (no tippy — `useAnchoredPosition` + Teleport) | chip: default / **selected** (ring, keyboard-deletable as one unit). popup: **open** · **loading** (option-shaped Skeleton rows — never a spinner) · **empty** ("No matches") · results with active row (`aria-activedescendant`). Keyboard ↑/↓/Enter/Esc + type-to-filter. |
| **Variable** (`{` trigger) | inline atomic `name <type>` chip (icon reflects `resultType`); inserted via the `{` **trigger** (shared caret-anchored listbox, local fuzzy filter over the predefined list, type-icon rows); chip opens a **Modal** with the full operations-pipeline editor | chip: default / **selected** / **editing** (Modal open). popup: open / results / empty. Modal: display name + lock toggle, source variable, **operations pipeline** (add-op dropdown filtered by the running type → step Selects + per-arg inputs → computed `resultType`). Serializes byte-compatibly **including the pipeline**. |
| **If-block** | bordered token-styled **container** (`ifBlock`) of `ifBranch` children; each branch header (kind + condition summary + boolean-valid icon + controls) over an **inline-editable body** (real editor region — marks/mentions/variables/nested if-blocks) | container: default / **selected**; add Else-if (≤ maxElseIf) / Else (max one). branch: editable body + **condition Modal** (variable Select + the **shared** pipeline editor; **save blocked unless `resultType === boolean`**). **Depth cap** (default 3): toolbar insert disabled at cap + a `filterTransaction` rejects paste/drop past it. Toolbar git-branch button to insert. Serializes to the fenced form (nested directives / if-blocks / lists round-trip). |
| **AI text** | inline atomic "AI <persona\|label summary>" chip + **Modal** (persona Select + a **nested MarkdownEditor** for the prompt + labels multi-select) | chip: default / **selected** / **editing** (Modal open). Toolbar sparkles button to insert. The nested prompt editor re-enables variables / if-blocks (recursively serialized per FORMAT). |

- **A11y:** chips are atomic selectable nodes — arrow to select (ring), then
  Backspace/Delete removes the whole unit; variable/AI chips expose
  `aria-haspopup="dialog"` and open their **Modal** (focus-trapped) on
  Enter/click. The mention + variable popups are `role="listbox"` +
  `role="option"` + `aria-activedescendant`; every edit Modal is a
  `role="dialog"` (`aria-modal`) with Esc + scrim close and focus return.
- **Skeleton rule:** async suggestion loading renders **option-row skeletons**
  (an avatar circle + a text line per row, several rows), never a spinner.
- **No tippy / no new deps:** the suggestion popup and all edit popovers use the
  shared `useAnchoredPosition` + `Teleport` + `useOutsideClick` conventions. The
  suggestion popup anchors against a synthetic element returning the caret rect.

> Tier 7 adds the editor icons to the registry (`bold`, `italic`, `underline`,
> `strikethrough`, `code`, `code-block`, `list`, `list-ordered`, `list-checks`,
> `quote`, `heading`/`heading-1..3`, `pilcrow`, `link`, `link-2`, `unlink`,
> `table`, `undo`, `redo`, `remove-formatting`, plus the PART 2 app-node icons
> `braces`, `git-branch`, `sparkles`, `at-sign`, `type`, `hash`, `lock`,
> `lock-open`) and a tiny local `Placeholder`
> extension (avoids a new npm dependency). It uses ONLY Tiptap packages already in
> `package.json`.

---

## Tier 8 — Extended

Gap-filling components for current + future modules. They are built **FROM** the
existing system (the overlay composables, the primitives, the status tokens) and
introduce no new dependencies. All states render in the gallery, light + dark.

- **Tier 8A** — `Overlay` (Drawer), a new **Feedback** section (Alert, Banner,
  Progress), a new **Disclosure** section (Accordion), and `Navigation` (Stepper).
- **Tier 8B** — `Primitives` (Kbd), `Overlay` (Command palette), `Forms`
  (SegmentedControl), `Data` (Tree, DescriptionList), and `Patterns` (PageHeader).

### Drawer (Sheet)

A side panel that **mirrors Modal's overlay mechanics** — same translucent scrim
(`--color-next-overlay`), the shared `useOverlayStack` (Esc / scrim dismiss the
**topmost** overlay only), `useFocusTrap`, the reference-counted body-scroll lock
(shared counter with Modal), and stacking-aware z-index (`--z-next-modal` +
`depth * 10`) so a Drawer can open **above** a Modal. Use it for detail panels.

| Axis | Values |
| --- | --- |
| Sides | `left`, `right` (default), `top`, `bottom` (slides in from that edge) |
| Sizes | `sm`, `md` (default), `lg`, `xl`, `full` (cross-axis: width for left/right, height for top/bottom; capped to the viewport) |
| Props | `side`, `size`, `closeOnEsc`, `closeOnScrim`, `showClose`, `ariaLabel`, `v-model:open` |
| Slots | `title`, `description`, default (body), `footer` ({ close }) |
| States | closed, open, with-header, with-footer, scrolling-body, stacked-over-modal, light + dark |

- **CRITICAL teleport rule (applied):** the slide **transform** is on the **panel**
  (a real panel, not a teleport wrapper), and the teleported wrapper holding the
  `position: fixed` children animates **opacity only** on the scrim — never a
  transform. This is the same rule Modal/Select/FieldPopover follow.
- **A11y:** `role="dialog"` + `aria-modal="true"`, labelled by the `#title` id and
  described by the `#description` id when present (else `ariaLabel`); focus trapped
  while open and returned to the trigger on close; Esc + scrim close the topmost
  overlay only.

### Alert vs Banner vs Toast (distinction)

- **Alert** = an **inline** message block that stays **in document flow** (e.g.
  a form-level validation summary, an empty-form notice). Not transient.
- **Banner** = a **page/app-level full-width** announcement bar (maintenance,
  trial expiry, a new feature), optionally `sticky`. Spans its container.
- **Toast** = a **transient, teleported** notification (Tier 4+, top-right,
  auto-dismissing) that rides above modals. Not in flow.

### Alert (inline)

| Axis | Values |
| --- | --- |
| Variants | `info`, `success`, `warning`, `danger` — each a `*-subtle` surface + `*-subtle-foreground` text + a matching icon |
| Sizes | `sm`, `md` |
| Props | `variant`, `size`, `title?`, `dismissible`, `icon?` (override), `dismissLabel?` |
| Slots | default (body), `actions` |
| States | each variant, title-only, body-only, with-actions, dismissible, sm/md, light + dark |

- **Never color-only:** each variant pairs its tint with a distinct icon
  (`info` / `check-circle` / `alert-triangle` / `alert-circle`).
- **A11y:** info/success → `role="status"` (polite); warning/danger →
  `role="alert"` (assertive). The icon is decorative; the dismiss ✕ is a real
  button with a translated `aria-label` and emits `dismiss`.

### Banner (page/app-level)

| Axis | Values |
| --- | --- |
| Variants | `neutral`, `info` (default), `primary`, `warning`, `danger` |
| Props | `variant`, `icon?` (override), `hideIcon`, `dismissible`, `sticky`, `dismissLabel?` |
| Slots | default (message), `actions` |
| States | each variant, with-actions, dismissible, sticky (pinned at `--z-next-sticky`), light + dark |

- **Full-bleed** within its container; denser than an inline Alert.
- **A11y:** neutral/info/primary → `role="status"`; warning/danger →
  `role="alert"`. Dismiss ✕ is a real button (translated label), emits `dismiss`.

### Progress (linear) + CircularProgress (ring)

| Axis | Values |
| --- | --- |
| Modes | **determinate** (`value`/`max`, smooth transition) + **indeterminate** (animated, reduced-motion aware) |
| Linear sizes | `sm`, `md` |
| Circular sizes | `sm`, `md`, `lg`, `xl` (SVG ring) |
| Tones | `primary` (default), `success`, `warning`, `danger` |
| Linear extras | `label?`, `showPercentage`, `ariaLabel?` |
| Circular extras | `showPercentage`, center label slot, `ariaLabel?` |
| States | determinate (0–100%), indeterminate, each tone, each size, with-label / with-%, light + dark |

- **A11y:** `role="progressbar"` with `aria-valuemin`/`aria-valuemax`;
  `aria-valuenow` is set **only** when determinate (omitted while indeterminate,
  which also sets `aria-busy`). The percentage / center text is decorative.
- **Reduced motion:** the linear travelling bar + the circular spin collapse under
  `prefers-reduced-motion` (local fallbacks + the global `.next-root` rule).

### Accordion (+ AccordionItem)

| Axis | Values |
| --- | --- |
| Type | `single` (model `string \| null`) or `multiple` (model `string[]`) |
| Control | controlled (`v-model`) or uncontrolled (`defaultValue`) |
| Item props | `value`, `title?` / `#header`, `icon?`, `disabled` |
| Item slots | `header`, default (region body) |
| Item states | default, hover, focus-visible, open (chevron rotated), disabled |

- **Smooth height transition** via a `grid-template-rows: 0fr → 1fr` collapse
  (auto content height) + `overflow: hidden`; respects reduced motion. A collapsed
  region is `inert` (out of the tab order + a11y tree) — **not** `hidden`, so the
  transition still runs.
- **A11y:** header is a real `<button aria-expanded aria-controls>`; the region is
  `role="region" aria-labelledby` the header. Keyboard: `↑`/`↓` move between
  headers (parent-owned header registry), `Home`/`End` jump, `Enter`/`Space`
  toggle. The disclosure chevron rotates on open.

### Stepper

| Axis | Values |
| --- | --- |
| Orientation | `horizontal` (default), `vertical` |
| Step status | `complete`, `current`, `upcoming`, `error` (explicit per-step, or derived from `v-model:active`) |
| Mode | display-only (default) or `clickable` (+ `linear` to block jumping ahead) |
| Item props | `value`, `label`, `description?`, `status?`, `disabled?` |
| Slots | `content` ({ value, index, step }) — the active step's wizard body |
| States | each status (number / check / x in a status-toned node), connector complete vs pending, clickable + focus-visible, disabled, with-content (wizard), light + dark |

- **Never color-only:** the status node shows a **number** (current/upcoming),
  a **check** (complete), or an **x** (error) inside its toned circle; the
  connector reads primary when complete.
- **A11y:** an ordered `<ol>`; the current step carries `aria-current="step"`.
  When `clickable`, each step is a real button whose accessible name includes the
  **status word** (e.g. “Payment, step 3: error”) so the state is conveyed without
  color. Nodes + connectors are decorative.

### Kbd (keyboard key hint)

| Axis | Values |
| --- | --- |
| Input | `keys` (string[], normalized) **or** the default slot (single raw cap) |
| Sizes | `sm` (inline default), `md` |
| Normalized | `mod` → ⌘ on macOS / `Ctrl` elsewhere (platform-detected); `enter` (↵), `esc`, `shift` (⇧), `alt`/`option` (⌥ on mac), `tab` (⇥), `space` (␣), `backspace` (⌫), arrows (↑↓←→); single letters upper-case |
| States | single key, multi-key combination, sm/md, slot mode, inline-in-text, light + dark |

- **Platform-aware:** `IS_MAC` is detected once at module load; `mod`/`alt`
  render the macOS glyph there and the named key elsewhere.
- **A11y:** the visible caps are decorative (`aria-hidden`); the whole group
  exposes a readable `aria-label` (e.g. “Command K”) derived from the **spoken**
  key names (all translated), or an explicit `ariaLabel` override. Used by the
  Command palette (search hint + per-command shortcuts) and tooltips.

### Command palette (⌘K / Ctrl+K)

A teleported, centered **command launcher** that **reuses the Modal mechanics** —
scrim (`--color-next-overlay`), the shared `useOverlayStack` (topmost-only Esc),
`useFocusTrap`, the reference-counted body-scroll lock, and stacking-aware
z-index — wrapping an autofocused search input + a grouped, scrollable command
list.

| Axis | Values |
| --- | --- |
| Data | static `commands` (filtered locally over label + keywords) **or** async `fetchCommands(query)` (debounced) |
| Command | `{ id, label, group?, icon?, keywords?, shortcut?, disabled?, perform }` |
| Props | `v-model:open`, `commands`, `fetchCommands`, `placeholder`, `ariaLabel`, `debounce` |
| Events | `select` (before run), `open`, `close` |
| States | closed, open (autofocused), typing/filtered, grouped results, active row (highlight), disabled command, **loading** (option-row skeletons — async), **empty** ("No results"), empty-query, stacked-over-modal, light + dark |

- **Mechanics (applied):** identical scrim/overlay-stack/focus-trap/body-lock/
  teleport/z to Modal; the panel transform is on a real panel (the teleported
  wrapper animates opacity only — the teleport transform rule).
- **Skeleton rule:** async loading renders **option-row skeletons** (icon circle +
  text line, several rows) — never a Spinner + "Loading…".
- **Keyboard:** ↑/↓ move the active command (skipping disabled, wrapping), Enter
  runs it + closes, Esc closes (topmost only), typing filters.
- **A11y:** `role="dialog"` + `aria-label`; the input is `role="combobox"` with
  `aria-controls` / `aria-activedescendant`; the list is `role="listbox"` of
  `role="option"` (`aria-selected`); each command's `shortcut` renders via **Kbd**.
- **`useCommandPalette()` helper:** registers a global ⌘K / Ctrl+K listener
  (macOS vs others) returning a reactive `open` + open/close/toggle; the host
  mounts **one** palette and binds `v-model:open` to it.

### SegmentedControl (vs Tabs)

A compact, mutually-exclusive **single-select toggle** — a *choice* among a few
options (`role="radiogroup"`), **distinct from Tabs** which switch *panels*
(`role="tablist"`). Use it for view / filter toggles (List/Board, All/Active).

| Axis | Values |
| --- | --- |
| Options | `{ value, label, icon?, disabled? }[]` |
| Sizes | `sm`, `md` |
| Props | `options`, `v-model` (`string \| null`), `size`, `equalWidth`, `iconOnly`, `disabled`, `ariaLabel` |
| States | default, selected (sliding thumb), hover, focus-visible, disabled-option, disabled-control, with-icon, icon-only, equal-width, sm/md, light + dark |

- **Selected segment** is highlighted by a token-tinted **thumb** (a `bg-next-card`
  surface + `shadow-next-xs`) that **slides** behind the active option (measured
  from the DOM, animated via the motion tokens, recomputed on resize). Selection
  also sets medium weight + `text-next-fg` — **never color alone**.
- **A11y:** `role="radiogroup"` + `role="radio"` per option with `aria-checked`;
  **roving tabindex** (only the selected option is a tab stop); ←/↑ and →/↓ move
  the selection (skip disabled, wrap); Home/End jump; Space/Enter (re)select.
  `iconOnly` keeps each option's label as its `aria-label`.

### Tree (+ TreeItem)

A hierarchical tree view following the **WAI-ARIA tree pattern**. Tree.vue owns
**all** state (expansion / selection / focus / lazy-load) and the keyboard logic;
TreeItem is a thin recursive renderer reading the provided context (one source of
truth, no duplicated logic per level).

| Axis | Values |
| --- | --- |
| Node | `{ id, label, icon?, children?, disabled?, badge?, loadable? }` |
| Props | `nodes`, `selectable` (`'single' \| 'multiple' \| null`), `v-model:expanded` (id[]), `v-model:selected` (id[]), `loadChildren(node)`, `size`, `guides`, `ariaLabel` |
| Events | `select`, `expand`, `collapse` (each → the node) |
| States | collapsed, expanded, selected (single / multiple), focused (roving), disabled node, with-icon, with-badge, **lazy-loading** (per-node spinner), with / without guide lines, sm/md, light + dark |

- **Expansion** is controlled via `v-model:expanded` (uncontrolled if unbound).
  **Selection** is opt-in (`selectable`) via `v-model:selected`. **Lazy children:**
  a `loadable` node (no `children`) calls `loadChildren(node)` on first expand; a
  per-node **spinner** shows while it resolves and the result is merged in (the
  `nodes` prop is never mutated).
- **Keyboard (WAI-ARIA tree):** ↑/↓ move the previous/next **visible** node;
  → expands a closed node or moves to the first child (no-op on a leaf); ← collapses
  an open node or moves to the parent; Home/End jump to the first/last visible node;
  Enter/Space select (or toggle when not selectable); **type-ahead** jumps to the
  next visible node whose label starts with the typed characters.
- **A11y:** `role="tree"` (+ `aria-multiselectable` when multiple); rows are
  `role="treeitem"` with `aria-level`, `aria-expanded` (only when expandable),
  `aria-selected` (when selectable), and `aria-disabled`; exactly **one** row owns
  the roving `tabindex=0`; children sit in a `role="group"`. Selection adds a
  subtle surface + token text and the chevron rotates — **never color alone**.

### PageHeader

The standard page-top header every page uses, composed **FROM** Breadcrumbs +
Heading + Icon/Avatar (no new visual primitive). Responsive: the actions cluster
wraps below the title on small screens.

| Axis | Values |
| --- | --- |
| Props | `title?`, `description?`, `level` (1/2/3), `breadcrumbs?` (BreadcrumbItem[]), `icon?` |
| Slots | `breadcrumbs`, `leading` (e.g. an Avatar), `title`, `description`, `actions`, `tabs` |
| Events | `breadcrumb-navigate` (BreadcrumbItem) |
| States | breadcrumbs + title + description + actions + tabs (full), with-avatar (leading slot), with-icon bubble, title-only (minimal), sub-page (level 2), responsive stack (narrow), light + dark |

- **A11y:** renders a real `<header>` and a single configurable `<h1>` (`level`
  so a sub-page can use h2/h3). The leading icon bubble is decorative; an Avatar
  carries its own label; Breadcrumbs / Tabs keep their own ARIA.

### DescriptionList (+ DescriptionItem)

Key→value pairs for detail panels, rendered as a proper `<dl>` / `<dt>` / `<dd>`.

| Axis | Values |
| --- | --- |
| Items | `{ key, label, value? }[]` |
| Layouts | `stacked` (default), `horizontal` (label column + value), `grid` (responsive columns) |
| Props | `items`, `layout`, `columns` (1–4, grid only), `emptyValue` (`'—'`), `size` (`sm`/`md`) |
| Slots | `value-<key>` ({ item, value }) per-item override (badges/links/status), default (free-form rows) |
| States | stacked, horizontal, grid (2/3/4 cols), value slot (badge/link), empty value (em dash), sm/md, light + dark |

- **Semantics:** a real `<dl>` of `<dt>` labels + `<dd>` values; the `grid` layout
  is 1 column at the base breakpoint and `columns` at `next-md`. Empty / nullish
  values render a visible **em dash** so a row never reads as blank/broken.

> Tier 8B adds two i18n namespaces (`kbd.*`, `commandPalette.*`, `tree.*`) to
> **both** catalogs, the `useCommandPalette()` global-shortcut helper
> (`ui/overlay/commandPalette.ts`), and shared Tree types/context
> (`ui/data/tree.ts`). It adds **no** new icons (Kbd uses glyphs/labels) and
> **no** new dependencies.

---

## Tier 4+ (referenced, specced in their own tier docs)

Navigation (Tabs, Breadcrumbs, Pagination), the rest of data (Table, EmptyState,
StatusBadge), and patterns (EntityCard, StatCard, StatsGrid, Timeline, FilterBar)
are now specced above (Tier 5 — Navigation, the Data section, and Tier 6 —
Patterns). The **advanced Markdown editor** core is specced above (Tier 7 —
Editor); its directive layer (mentions/variables/if-blocks/AI) is **implemented**
there (PART 2).

**`ui/recurrence/` — the shared recurrence editor (added `62a73e4`, 2026-08-26).**
Not given its own Tier here, the same way `ui/variables/` (`VariableBrowser`,
`TypedLiteralInput`, …) is not: both are multi-file clusters with a pure core,
consumed by exactly the pages that need them, and fully specced where they are
used rather than duplicated in this matrix. Home: `resources/js/next/ui/recurrence/`
— seven files (`recurrenceAxes.ts` the pure axis grammar + `RecurrenceAxisEditor.vue`
the tabbed host + three axis panels + `RecurrenceOptionCards.vue` +
`RecurrenceWindowField.vue`). It exists here, under `ui/**`, for the same reason
`ui/editor/` and `ui/variables/` do: two callers — the Calendar event drawer's
repeat control and the Workflows schedule trigger's builder — need the identical
grammar with only their accepted subset differing, and `ui/**` is the layer both
may import DOWN from (enforced, not just documented — see
`__tests__/uiLayerImportBoundary.spec.ts`: no file under `ui/` may import from
`pages/`, whether the import is type-only or a runtime one — a type-only escape
was the actual hole the test was written to close). The real constraint this
enforces is **`ui/` never imports from a page** — there is no rule, and never was
one, against Calendar's pages and Workflows' pages importing from each other's
page-local files; they simply have no reason to, since what they'd share already
lives one layer down. States/variants live in the pages that consume it:
`docs/next/calendar-uxui-spec.md` §24.5 (the Calendar profile — day/month axes
only, no time axis) and `docs/next/workflows-uxui-spec.md` §4.5 (the Workflows
profile — the full grammar; that doc's REV5 component names/files are now stale,
superseded by this move — not yet corrected in that file, see the recurrence
refactor's documentation-agent report for what's still open).

**Implemented overlay behavior (locked in):**

- **Modal / ConfirmDialog:** the scrim is **translucent** (`--color-next-overlay`,
  alpha) so the dimmed app stays visible. The teleported full-screen wrapper carries
  `.next-root` only for tokens/dark scoping and uses `.next-overlay-root` to stay
  **background-transparent** (otherwise `.next-root`'s opaque app background would make
  the scrim look solid). Scrim `--z-next-overlay` (1020) < panel `--z-next-modal` (1030).
- **Toast / ToastViewport:** default position **`top-right`**, **newest-first** stacking
  (the newest toast renders at the top and slides in from above). The viewport is
  `.next-overlay-root` (transparent) + `pointer-events-none` so it never shows a stray
  rectangle and never blocks the page when empty. It teleports to `<body>` at
  `--z-next-toast` (1050) so toasts ride **above** modals and are **not** dimmed by the
  modal scrim.

---

## Gallery acceptance checklist (per component)

- [ ] Every variant × size rendered.
- [ ] Universal states shown: default, hover, focus-visible, active, disabled.
- [ ] Applicable extra states shown: loading, error, readonly, selected/checked,
      indeterminate, with/without icon, overflow/truncation.
- [ ] Light **and** dark mode.
- [ ] Keyboard interaction notes + ARIA roles documented on the page.
- [ ] Props/events/slots table.
- [ ] At least one realistic usage example.
