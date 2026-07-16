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

## Tokens page

`TokensPage.vue` resolves token values **live** from
[`resources/css/next.css`](../../../../resources/css/next.css) using
`getComputedStyle` on hidden `.next-root` / `.next-root.dark` probe elements, so
it can never drift from the stylesheet and shows both light and dark values for
every color token. Mirror this "read from the CSS, don't hardcode" approach for
any future foundations pages.
