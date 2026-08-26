<script setup lang="ts">
// Gallery: Internationalization (Foundations tier).
//
// Documents the isolated "next" i18n layer: the `t()` signature, adding keys to
// BOTH catalogs, the LocaleSwitcher, runtime locale persistence/sync, and the
// project rule that ALL user-facing text must be internationalized. Includes a
// LIVE demo whose strings re-render instantly when the language is switched
// (the same shared `useI18n()` singleton the whole app uses).
import { computed } from 'vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import LocaleSwitcher from '../../ui/LocaleSwitcher.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';

const { t, currentLocale } = useI18n();

// A live, reactive sample drawn from real catalog keys — flip the switcher in the
// top bar (or the inline one below) and watch these update.
const samples = computed(() => [
  { key: 'common.save', value: t('common.save') },
  { key: 'common.cancel', value: t('common.cancel') },
  { key: 'select.empty', value: t('select.empty') },
  { key: 'pickers.presets.today', value: t('pickers.presets.today') },
  {
    key: 'pagination.summary',
    value: t('pagination.summary', undefined, { from: 1, to: 10, total: 42 }),
  },
  {
    key: 'editor.pipeline.editStep',
    value: t('editor.pipeline.editStep', undefined, { index: 2, label: 'uppercase' }),
  },
]);

const apiRows: ApiRow[] = [
  { name: 't(key)', type: '(key: string) => string', description: 'Translate a dot-path key in the active locale.' },
  { name: 't(key, default)', type: '(key, defaultValue?) => string', description: 'Fall back to defaultValue (then the key) on a miss.' },
  { name: 't(key, default, params)', type: '(key, default?, params?) => string', description: 'Interpolate {param} tokens from the params record.' },
  { name: 'locale', type: 'Readonly<Ref<NextLocale>>', description: 'Reactive active locale (read-only).' },
  { name: 'currentLocale', type: 'ComputedRef<NextLocale>', description: 'Alias of locale (legacy-compatible name).' },
  { name: 'setLocale(l)', type: '(l: NextLocale) => void', description: 'Switch + persist (next-locale) + set <html lang> + best-effort PUT /api/user/locale. Re-runs even when l is already the active locale (a deliberate re-assert, not a no-op) and skips the PUT entirely when nobody is logged in.' },
  { name: 'activeLocale()', type: '() => NextLocale', description: 'Non-reactive read of the rendered locale, for callers outside the component tree. lib/api.ts calls this per request to set X-Client-Locale.' },
  { name: 'availableLocales', type: 'readonly NextLocale[]', description: 'The locales offered by the switcher (pl, en).' },
];
</script>

<template>
  <StoryPage
    title="Internationalization"
    description="The next frontend ships its own dependency-free i18n (no vue-i18n, no legacy import). One reactive useI18n() singleton drives every translated string, so switching language re-renders the whole app instantly. ALL user-facing text MUST go through t()."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The LocaleSwitcher is a labelled <code>role="group"</code>; each segment is a real button with <code>aria-pressed</code>.</li>
        <li>Translate aria-labels, placeholders, and empty/error copy too — not just visible text.</li>
        <li><code>setLocale()</code> updates <code>&lt;html lang&gt;</code> so assistive tech announces the right language.</li>
      </ul>
    </template>

    <StorySection title="Live demo" description="Flip the language — these strings update with no reload (shared singleton state).">
      <div class="flex flex-col gap-next-4 rounded-next-lg border border-next-border bg-next-card p-next-4">
        <div class="flex items-center gap-next-3">
          <span class="text-next-sm text-next-muted-foreground">{{ t('language.label', 'Language') }}:</span>
          <LocaleSwitcher />
          <span class="text-next-xs text-next-muted-foreground">({{ currentLocale }})</span>
        </div>

        <table class="w-full text-left text-next-sm">
          <thead>
            <tr class="border-b border-next-border text-next-muted-foreground">
              <th class="py-next-1_5 pr-next-4 font-next-medium">Key</th>
              <th class="py-next-1_5 font-next-medium">{{ currentLocale.toUpperCase() }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in samples" :key="row.key" class="border-b border-next-border/60">
              <td class="py-next-1_5 pr-next-4"><code class="text-next-xs">{{ row.key }}</code></td>
              <td class="py-next-1_5">{{ row.value }}</td>
            </tr>
          </tbody>
        </table>

        <div class="flex flex-wrap gap-next-2">
          <Button size="sm">{{ t('common.save', 'Save') }}</Button>
          <Button size="sm" variant="ghost">{{ t('common.cancel', 'Cancel') }}</Button>
          <Button size="sm" variant="outline" leading-icon="trash">{{ t('common.delete', 'Delete') }}</Button>
        </div>
      </div>
    </StorySection>

    <StorySection title="Authoring rule" description="Every new user-facing string must be internationalized.">
      <div class="rounded-next-lg border border-next-border bg-next-card p-next-4 text-next-sm">
        <ol class="ml-next-4 list-decimal space-y-next-2 text-next-fg">
          <li>
            Call the singleton in your component:
            <code class="text-next-xs">const &#123; t &#125; = useI18n()</code>
            — safe in NodeViews and teleported overlays.
          </li>
          <li>
            Add the key to <strong>both</strong> catalogs:
            <code class="text-next-xs">app/i18n/en.ts</code> and <code class="text-next-xs">app/i18n/pl.ts</code>.
            <code class="text-next-xs">pl</code> is typed against <code class="text-next-xs">MessageSchema</code>, so a
            missing key fails <code class="text-next-xs">vue-tsc</code>; the parity unit test fails on a mismatch.
          </li>
          <li>
            Render it with a fallback:
            <code class="text-next-xs">t('namespace.key', 'English fallback')</code>.
            Use <code class="text-next-xs">&#123;param&#125;</code> tokens for dynamic values.
          </li>
          <li>Never hardcode display strings — including aria-labels, placeholders, and empty/error states.</li>
        </ol>
      </div>
    </StorySection>

    <StorySection title="Locale resolution & persistence">
      <ul class="ml-next-4 list-disc space-y-next-1 text-next-sm text-next-fg">
        <li>Initial locale: <code>localStorage('next-locale')</code> → browser language (<code>pl*</code> → pl) → <code>'en'</code>.</li>
        <li><code>setLocale()</code> persists to <code>next-locale</code>, sets <code>&lt;html lang&gt;</code>, and — only when someone is logged in — best-effort PUTs <code>/api/user/locale</code> (failures swallowed — never breaks the switch). It deliberately does <strong>not</strong> early-return when the clicked locale is already active: re-asserting the language still writes the choice to the server, which is what fixes an account whose stored locale disagrees with the language on screen.</li>
        <li>Every request from this client also sends its current locale as the <code>X-Client-Locale</code> header (<code>lib/api.ts</code>, read from <code>activeLocale()</code>). The server (<code>App\Http\Middleware\SetUserLocale</code>) treats it as a fallback fact — "this is what the screen is rendering" — consulted only when the user has no stored locale choice; it is never persisted from the header alone. See ADR-0053.</li>
        <li><code>initI18n()</code> runs in <code>main.ts</code> before first paint to apply <code>&lt;html lang&gt;</code>.</li>
        <li>Date/time pickers default their <code>Intl</code> locale to the active UI language (overridable via the <code>locale</code> prop).</li>
      </ul>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="useI18n()" type-header="Type" :rows="apiRows" />
    </StorySection>
  </StoryPage>
</template>
