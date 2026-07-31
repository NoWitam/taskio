# Styleguide gallery — authoring guide

The interactive styleguide is the technical documentation for the isolated
`/next` design system. It is served at **`/next/_styleguide`** and renders every
component's full variants × sizes × states matrix in **light and dark**, per
[`docs/next/component-state-matrix.md`](../../../../docs/next/component-state-matrix.md).

## Layout of this folder

```
resources/js/next/docs/
├── StyleguideView.vue   # gallery shell: left nav + theme toggle + content area
├── registry.ts          # the single source of truth for the nav (stories array)
├── pages/               # one story page per component / topic
│   └── TokensPage.vue   # live design-tokens page (reads CSS vars at runtime)
└── README.md            # this file
```

## Adding a story page

1. Create a page under `docs/pages/`, e.g. `ButtonPage.vue`. Use only the
   namespaced `next-` Tailwind utilities (`bg-next-card`, `text-next-fg`,
   `rounded-next-md`, `next-md:` …) and import primitives from
   `../../ui/...`. Do **not** import anything from the legacy `resources/js/`.
2. Register it in [`registry.ts`](./registry.ts) by adding an entry to the
   `stories` array:

   ```ts
   {
     section: 'Primitives',          // one of SECTION_ORDER
     name: 'Button',                 // unique within its section
     component: () => import('./pages/ButtonPage.vue'),
   }
   ```

3. The nav is generated automatically. Sections render in the fixed
   `SECTION_ORDER`; empty sections show a "coming soon" placeholder.

## Story page conventions (acceptance checklist)

Each component story should demonstrate (see the state matrix for specifics):

- every variant × size,
- universal states: default, hover, focus-visible, active, disabled,
- applicable extra states: loading, error, readonly, selected/checked,
  indeterminate, with/without icon, overflow/truncation,
- light **and** dark (the gallery theme toggle drives this globally; the tokens
  page additionally previews both at once via probe elements),
- keyboard interaction notes + ARIA roles,
- a props/events/slots table,
- at least one realistic usage example.

## Internationalization (MANDATORY)

The `/next` app is **Polish + English, switchable at runtime**. ALL user-facing
text — visible labels, button text, placeholders, **aria-labels**, empty/error/
loading copy, toasts — MUST be internationalized. Hardcoding a display string is
treated as an incomplete change.

- Use the singleton composable inside the component:
  `const { t } = useI18n()` (from `app/i18n`). It is safe in NodeViews and
  teleported overlays because the active locale is shared module-level state.
- Render with a fallback: `t('namespace.key', 'English fallback')`. Use
  `{param}` tokens for dynamic values, passed via the third arg
  (`t('pagination.summary', undefined, { from, to, total })`).
- Add every new key to **both** catalogs: `app/i18n/en.ts` and `app/i18n/pl.ts`.
  `en` is the source of truth (`MessageSchema`); `pl` is typed against it, so a
  missing key fails `vue-tsc`, and the parity unit test
  (`app/i18n/__tests__/i18n.spec.ts`) fails on any mismatch.
- Date/time pickers default their `Intl` locale to the active UI language; pass
  an explicit `locale` prop only to override.
- The language switcher (`ui/LocaleSwitcher.vue`) lives in the styleguide top bar
  beside the theme toggle. The full reference + a live demo is the **Foundations →
  Internationalization** gallery page (`pages/I18nPage.vue`).

## Layer boundary: `ui/` never imports `pages/`

`resources/js/next/ui/**` is the design system — the LOWER layer every page
consumes. A page may import down into `ui/`; `ui/` must never import up into
`pages/`, or the design system stops being independently reusable. This was
previously honored only by comment + local duplication, until a real runtime
import slipped into `ui/forms/BotSelect.vue`. It is now **enforced by a test**,
not just documented: `resources/js/next/__tests__/uiLayerImportBoundary.spec.ts`
walks every `.ts`/`.vue` file under `ui/`, collects every `from '…'` and dynamic
`import('…')` specifier (comments don't false-positive — a prose mention has no
`from '…'` shape), and fails on any specifier containing `pages/` — type-only
imports included, since they encode the same wrong direction even though they
erase at build time. The same spec also pins that no file under `ui/` imports
the frozen legacy frontend (`@/…` or a relative escape out of `next/`).

**The fix for a genuinely shared thing is to move it DOWN into `ui/`, never to
copy it.** Two known exceptions currently do copy, and are debt — not a pattern
to imitate:

- `ui/forms/TemplateSelect.vue` keeps a LOCAL content-type → icon map (the same
  three ids `pages/generator/templateMeta.ts` maps), because a design-system
  component may not reach into a page for it. It is presentation-only — an
  unknown/new id falls back to the generic file glyph, same as the page-side map.
- `ui/variables/types.ts` re-declares `VariableSourceVar` LOCALLY, structurally
  identical to the Workflows page's `CatalogVariable`
  (`pages/workflows/types.ts`) — same keys, unions, optionality — so a page can
  pass its catalog arrays straight in with zero mapping. If the backend contract
  ever widens `CatalogVariable`, the local copy has to be mirrored by hand; it
  is not derived from the page type.

Both are candidates for the same DOWN-move the boundary rule asks for
elsewhere (e.g. `ui/data/botStatus.ts`, moved from `pages/bots/` so both the
Bots pages and the design-system `BotSelect` could read one definition).

## Tokens page

`TokensPage.vue` resolves token values **live** from
[`resources/css/next.css`](../../../../resources/css/next.css) using
`getComputedStyle` on hidden `.next-root` / `.next-root.dark` probe elements, so
it can never drift from the stylesheet and shows both light and dark values for
every color token. Mirror this "read from the CSS, don't hardcode" approach for
any future foundations pages.
