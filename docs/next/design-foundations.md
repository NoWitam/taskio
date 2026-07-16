# Taskio "next" — Design Foundations

> Phase 1 token spec for the isolated `/next` frontend.
> Source of truth: [`resources/css/next.css`](../../resources/css/next.css).
> This document explains the **why**; the CSS file is the **what**.

## Isolation contract (read first)

The next frontend is a **separate Vite bundle** served at `/next`. It must not
reuse or leak into the legacy `resources/css/app.css` or
`resources/js/components/ui`.

- The app mounts inside an element with class **`.next-root`**.
- **Every** bespoke token is namespaced with a `next-` segment
  (`--color-next-bg`, `--spacing-next-4`, `--radius-next-lg`, `--z-next-modal`,
  `--breakpoint-next-md`, …). This guarantees that if `next.css` and the legacy
  `app.css` ever load on the same page, no token clobbers another.
- Dark mode is driven by **token overrides** under `.next-root.dark` via a
  scoped `@custom-variant dark`. There is **no ad-hoc color inversion** in
  components — flipping the class swaps tokens, components stay identical.
- The **only** value carried over from the legacy brand is the primary hue
  `hsl(325 60% 45%)` (magenta/pink). Everything else is re-chosen here.

### Utility naming

Because tokens are namespaced, the Tailwind utilities are too:

| Token group | Example token | Example utility |
| --- | --- | --- |
| Color | `--color-next-bg` | `bg-next-bg`, `text-next-fg`, `border-next-border` |
| Spacing | `--spacing-next-4` | `p-next-4`, `gap-next-2`, `mt-next-6` |
| Radius | `--radius-next-lg` | `rounded-next-lg` |
| Type size | `--text-next-sm` | `text-next-sm` |
| Font weight | `--font-weight-next-medium` | `font-next-medium` |
| Shadow | `--shadow-next-md` | `shadow-next-md` |
| Breakpoint | `--breakpoint-next-md` | `next-md:flex` |
| Z-index | `--z-next-modal` | `z-[var(--z-next-modal)]` |
| Motion | `--duration-next-fast` | `duration-[var(--duration-next-fast)]` |

**Usage rule:** components reference semantic tokens (`bg-next-card`,
`text-next-muted-foreground`), **never** raw hex/hsl and **never** the raw
neutral ramp directly. The ramp exists to *derive* semantics, not to be used in
components.

---

## Color

### Primary (carried over)

The magenta primary is the one element of brand continuity. Solid magenta on
white text (`--color-next-primary-foreground`) sits comfortably above AA for UI
text/icons. We add hover/active/subtle variants so buttons and tinted surfaces
don't require per-component color math.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--color-next-primary` | `hsl(325 60% 45%)` | `hsl(325 64% 58%)` | Solid CTA fill, active states, links |
| `--color-next-primary-foreground` | `hsl(0 0% 100%)` | `hsl(324 30% 6%)` | Text/icon on solid primary |
| `--color-next-primary-hover` | `hsl(325 60% 40%)` | `hsl(325 64% 64%)` | Hover on solid primary |
| `--color-next-primary-active` | `hsl(325 62% 34%)` | `hsl(325 66% 70%)` | Pressed primary |
| `--color-next-primary-subtle` | `hsl(325 60% 96%)` | `hsl(325 40% 18%)` | Tinted surface (selected row, soft button) |
| `--color-next-primary-subtle-foreground` | `hsl(325 60% 32%)` | `hsl(325 70% 86%)` | Text on subtle primary |

In **dark mode** the primary lifts to ~58% lightness so it stays vivid against
dark surfaces, and its foreground flips to a near-black magenta-tinted value
(white-on-bright-magenta would vibrate).

### Neutral ramp

The neutral ramp is **not** a pure gray. It carries a faint magenta whisper
(hue ~315–324, saturation 5–20%) so neutrals read as *related* to the primary
rather than a clashing cool gray. This is the "dark-mode color tinting"
principle applied to both modes: tinting greys toward the brand hue makes the
whole UI feel cohesive.

| Step | Light value | Typical role |
| --- | --- | --- |
| `0` | `hsl(320 20% 99%)` | Cards / topmost surface (light) |
| `50` | `hsl(320 16% 97%)` | App canvas (light) |
| `100` | `hsl(320 12% 94%)` | Muted fills, hover rows |
| `200` | `hsl(318 10% 88%)` | Borders / hairlines |
| `300` | `hsl(316 8% 80%)` | Input borders, dividers |
| `400` | `hsl(315 6% 64%)` | Disabled text, placeholders, icons |
| `500` | `hsl(315 5% 48%)` | Secondary/muted text |
| `600` | `hsl(316 6% 38%)` | Body text on tinted surfaces |
| `700` | `hsl(318 8% 28%)` | Dark borders, popover (dark) |
| `800` | `hsl(320 10% 18%)` | Card / muted (dark) |
| `900` | `hsl(322 12% 12%)` | Primary text (light) / card (dark) |
| `950` | `hsl(324 14% 8%)` | App canvas (dark) |

### Semantic surfaces & text

Components only ever reference these.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--color-next-bg` | `hsl(320 16% 97%)` | `hsl(324 14% 8%)` | App canvas |
| `--color-next-fg` | `hsl(322 12% 12%)` | `hsl(320 16% 97%)` | Primary text |
| `--color-next-card` | `hsl(320 20% 99%)` | `hsl(322 12% 12%)` | Raised surface (cards, panels) |
| `--color-next-card-foreground` | `hsl(322 12% 12%)` | `hsl(320 16% 97%)` | Text on cards |
| `--color-next-popover` | `hsl(320 20% 99%)` | `hsl(320 10% 18%)` | Menus, selects, tooltips |
| `--color-next-popover-foreground` | `hsl(322 12% 12%)` | `hsl(320 16% 97%)` | Text in popovers |
| `--color-next-border` | `hsl(318 10% 88%)` | `hsl(318 8% 28%)` | Hairlines, dividers |
| `--color-next-input` | `hsl(316 8% 80%)` | `hsl(316 6% 38%)` | Input/control borders |
| `--color-next-ring` | `hsl(325 60% 45%)` | `hsl(325 64% 62%)` | Focus ring |
| `--color-next-muted` | `hsl(320 12% 94%)` | `hsl(320 10% 18%)` | Muted fill (badges, table stripes) |
| `--color-next-muted-foreground` | `hsl(315 5% 48%)` | `hsl(315 6% 64%)` | Secondary text |
| `--color-next-accent` | `hsl(325 60% 96%)` | `hsl(325 40% 20%)` | Hover tint / selected |
| `--color-next-accent-foreground` | `hsl(325 60% 32%)` | `hsl(325 70% 88%)` | Text on accent |
| `--color-next-overlay` | `hsl(322 24% 8% / 0.55)` | `hsl(324 30% 4% / 0.65)` | Modal scrim |

**Light depth** comes from `card` (99%) sitting on `bg` (97%) + a hairline + a
soft shadow. **Dark depth** inverts the logic: `card` (12%) is *lighter* than
`bg` (8%), so surfaces step up in lightness as they rise — shadows stay subtle.

### Semantic status

Four status families. Each provides a **solid** (filled badge/button), its
**foreground**, a **subtle** surface (alerts/soft badges), and a **subtle
foreground**. Hues are chosen to be mutually distinguishable and distinct from
the magenta primary (so "danger" never reads as "brand").

| Family | Solid (light) | Solid (dark) | Subtle surface (light) | Subtle fg (light) |
| --- | --- | --- | --- | --- |
| Success | `hsl(152 58% 34%)` | `hsl(152 52% 46%)` | `hsl(152 50% 95%)` | `hsl(153 60% 22%)` |
| Warning | `hsl(36 92% 42%)` | `hsl(38 88% 54%)` | `hsl(40 90% 94%)` | `hsl(30 80% 28%)` |
| Danger | `hsl(356 70% 48%)` | `hsl(356 72% 58%)` | `hsl(356 76% 96%)` | `hsl(356 64% 36%)` |
| Info | `hsl(214 78% 47%)` | `hsl(214 80% 60%)` | `hsl(214 80% 96%)` | `hsl(216 70% 34%)` |
| Modified | `hsl(265 60% 48%)` | `hsl(265 72% 70%)` | `hsl(266 78% 96%)` | `hsl(266 55% 40%)` |

> Danger is intentionally pushed toward red-`356` (away from the magenta-`325`
> primary) so destructive actions are unmistakable. All solids hit AA against
> their `-foreground` text. **Never rely on color alone** — pair status with an
> icon and/or label (see StatusBadge in the state matrix).
>
> **Modified** is a fifth, PROJECT-WIDE family (`--color-next-modified` +
> `-foreground`/`-subtle`/`-subtle-foreground`, `Badge variant="modified"`) — not
> a status in the success/warning/danger/info sense, but a distinct semantic for
> "this value has drifted from a captured snapshot" (a diff highlight, an
> unsaved-change marker, a "changed since X" badge). A dedicated violet
> (hue `265`), kept clear of both the magenta primary (`325`) and the blue info
> (`214`) so a "changed" signal never reads as brand or informational. First
> consumer: `SubmissionPreviewDrawer`'s `diff` mode (Workflows run detail →
> form-submission snapshot vs. current). Reach for `warning` for an actual
> caution/attention state, and `modified` only for "this differs from a
> reference value" — the two are not interchangeable.

---

## Spacing & padding

4px base scale. Use the `next-` utilities (`p-next-4`, `gap-next-2`).

| Token | rem | px |
| --- | --- | --- |
| `--spacing-next-0` | 0 | 0 |
| `--spacing-next-px` | — | 1 |
| `--spacing-next-0_5` | 0.125 | 2 |
| `--spacing-next-1` | 0.25 | 4 |
| `--spacing-next-1_5` | 0.375 | 6 |
| `--spacing-next-2` | 0.5 | 8 |
| `--spacing-next-2_5` | 0.625 | 10 |
| `--spacing-next-3` | 0.75 | 12 |
| `--spacing-next-4` | 1 | 16 |
| `--spacing-next-5` | 1.25 | 20 |
| `--spacing-next-6` | 1.5 | 24 |
| `--spacing-next-8` | 2 | 32 |
| `--spacing-next-10` | 2.5 | 40 |
| `--spacing-next-12` | 3 | 48 |
| `--spacing-next-16` | 4 | 64 |
| `--spacing-next-20` | 5 | 80 |
| `--spacing-next-24` | 6 | 96 |

Guidance: control inner padding `2–4`, gap between related items `2–3`, section
gaps `6–8`, page gutters `4` (mobile) → `8` (desktop).

---

## Border radius

| Token | px | Use |
| --- | --- | --- |
| `--radius-next-none` | 0 | Flush / full-bleed |
| `--radius-next-xs` | 2 | Tiny chips, checkboxes |
| `--radius-next-sm` | 4 | Focus-ring rounding, small tags |
| `--radius-next-md` | 6 | **Default for controls** (buttons, inputs) |
| `--radius-next-lg` | 8 | Cards |
| `--radius-next-xl` | 12 | Panels, modals |
| `--radius-next-2xl` | 16 | Large feature surfaces |
| `--radius-next-full` | 9999 | Pills, avatars, switches |

---

## Typography

- **Sans:** Inter (with a robust system fallback stack) — `--font-next-sans`.
- **Mono:** JetBrains Mono fallback stack — `--font-next-mono` (code, IDs, diffs).

Each size pairs a `font-size` with a default `line-height` (Tailwind v4 reads
the `--text-next-*--line-height` companion tokens automatically).

| Token | px | line-height | Typical use |
| --- | --- | --- | --- |
| `--text-next-2xs` | 11 | 16 | Dense table meta, badge counts |
| `--text-next-xs` | 12 | 18 | Captions, helper text |
| `--text-next-sm` | 14 | 20 | **Default UI / body in tables/forms** |
| `--text-next-base` | 16 | 24 | Body copy, prose |
| `--text-next-lg` | 18 | 28 | Lead paragraph, card titles |
| `--text-next-xl` | 20 | 28 | H4 / section heading |
| `--text-next-2xl` | 24 | 32 | H3 / page subtitle |
| `--text-next-3xl` | 30 | 36 | H2 / page title |
| `--text-next-4xl` | 36 | 40 | H1 / hero |

| Weight token | value | Line-height token | value | Tracking token | value |
| --- | --- | --- | --- | --- | --- |
| `--font-weight-next-normal` | 400 | `--leading-next-tight` | 1.2 | `--tracking-next-tight` | -0.01em |
| `--font-weight-next-medium` | 500 | `--leading-next-snug` | 1.35 | `--tracking-next-normal` | 0 |
| `--font-weight-next-semibold` | 600 | `--leading-next-normal` | 1.5 | `--tracking-next-wide` | 0.02em |
| `--font-weight-next-bold` | 700 | `--leading-next-relaxed` | 1.65 | | |

Headings default to **semibold + tight leading + tight tracking** (set in base
styles). Body defaults to normal weight + normal leading.

---

## Shadows / elevation

Shadows are tinted with the neutral hue (`322 20% 10%`) rather than pure black,
so elevation feels part of the palette.

| Token | Use |
| --- | --- |
| `--shadow-next-xs` | Subtle separation (inputs on hover, flat chips) |
| `--shadow-next-sm` | Resting cards, dropdown triggers |
| `--shadow-next-md` | Raised cards, popovers, hovered cards |
| `--shadow-next-lg` | Menus, comboboxes, floating panels |
| `--shadow-next-xl` | Modals, drawers |
| `--shadow-next-focus` | `0 0 0 3px primary/35%` — soft focus glow on controls |

In dark mode, prefer **border + surface lightness** for depth; keep shadows
present but understated (the tokens already use low alphas).

---

## Z-index layers

Single source of truth — never hand-pick a `z-50`. Reference with
`z-[var(--z-next-modal)]`.

| Token | value | Layer |
| --- | --- | --- |
| `--z-next-base` | 0 | Page content |
| `--z-next-raised` | 10 | Sticky table headers, pinned columns |
| `--z-next-dropdown` | 1000 | Menus, selects, autocompletes |
| `--z-next-sticky` | 1010 | Sticky page chrome |
| `--z-next-overlay` | 1020 | Modal scrim |
| `--z-next-modal` | 1030 | Dialogs, drawers |
| `--z-next-popover` | 1040 | Popovers above a modal |
| `--z-next-toast` | 1050 | Toasts |
| `--z-next-tooltip` | 1060 | Tooltips (topmost) |

---

## Motion

| Duration | value | Use |
| --- | --- | --- |
| `--duration-next-instant` | 75ms | Press feedback |
| `--duration-next-fast` | 150ms | Hover, focus, small toggles |
| `--duration-next-normal` | 220ms | Default enter/leave |
| `--duration-next-slow` | 320ms | Modals, drawers, larger surfaces |

| Easing | curve | Use |
| --- | --- | --- |
| `--ease-next-standard` | `cubic-bezier(0.2,0,0,1)` | Most transitions |
| `--ease-next-emphasized` | `cubic-bezier(0.3,0,0,1)` | Entrances |
| `--ease-next-exit` | `cubic-bezier(0.4,0,1,1)` | Exits |

`prefers-reduced-motion: reduce` collapses all token-driven transitions and
animations inside `.next-root` (handled in base styles). Component authors do
not need to repeat this.

---

## Breakpoints

Namespaced so utilities read `next-md:` and never collide with legacy `md:`.

| Token | rem | px |
| --- | --- | --- |
| `--breakpoint-next-sm` | 40 | 640 |
| `--breakpoint-next-md` | 48 | 768 |
| `--breakpoint-next-lg` | 64 | 1024 |
| `--breakpoint-next-xl` | 80 | 1280 |
| `--breakpoint-next-2xl` | 96 | 1536 |

Mobile-first: base styles target small screens, layer up with `next-md:` etc.
Data-heavy tables should **transform** at `next-md` (e.g. card list → table),
not merely shrink (see component-state matrix).

---

## Accessibility baseline

- All solid status/primary colors meet **AA (≥4.5:1)** against their paired
  `-foreground`. Subtle surfaces pair with their `-subtle-foreground` for AA.
- Focus is **keyboard-only** (`:focus-visible`) and uses the `ring` token; never
  remove it without a token-driven replacement.
- Never communicate state with color alone — pair with icon, text, or shape.
- Respect reduced motion (already enforced in base layer).

---

## Do / Don't

- **Do** use `bg-next-card`, `text-next-muted-foreground`, `rounded-next-md`.
- **Do** add a new semantic token here (and in `next.css`) if two components
  need the same new color role.
- **Don't** use raw hex/hsl or the neutral ramp directly in a component.
- **Don't** introduce a non-namespaced token (it could clobber the legacy app).
- **Don't** invert colors per-component for dark mode — override tokens only.

---

## Internationalization (foundation)

The /next app is **Polish + English, switchable at runtime** via its own
dependency-free i18n (resources/js/next/app/i18n/, no vue-i18n, no legacy
import). One reactive useI18n() singleton drives every translated string, so a
language switch re-renders the whole app with no reload.

- ALL user-facing text MUST go through t(key, fallback?, params?) — visible
  labels, placeholders, **aria-labels**, and empty/error/loading copy included.
  Hardcoding a display string is an incomplete change.
- Add every key to **both** catalogs (en.ts + pl.ts). en is the typed source of
  truth (MessageSchema); pl must match it (enforced by vue-tsc and the parity
  unit test app/i18n/__tests__/i18n.spec.ts).
- Locale resolves from localStorage next-locale, then browser language, then en;
  it persists on change, sets the html lang attribute, and best-effort syncs to
  PUT /api/user/locale (failures swallowed so a switch never breaks).
- Date/time pickers default their Intl locale to the active UI language
  (overridable via the locale prop).
- Reference + live demo: **Foundations -> Internationalization** in the styleguide.
