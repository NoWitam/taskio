<script setup lang="ts">
// Gallery: Kbd — keyboard key hints. Normalized keys (mod/enter/esc/arrows),
// single keys, combinations, sizes, and inline usage. All text via t().
import Kbd, { IS_MAC } from '../../ui/primitives/Kbd.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const platformNote = IS_MAC
  ? t('story.kbd.combosMac', 'Platform-aware: `mod` shows ⌘ (macOS detected).')
  : t('story.kbd.combosOther', 'Platform-aware: `mod` shows Ctrl (non-macOS detected).');

const propRows: ApiRow[] = [
  { name: 'keys', type: 'string[]', default: '—', description: 'Keys to render in order; normalized to glyphs/labels. Omit to use the default slot.' },
  { name: 'size', type: "'sm' | 'md'", default: "'sm'", description: 'Visual scale.' },
  { name: 'ariaLabel', type: 'string', default: '(derived)', description: 'Override the spoken combination label.' },
];
const slotRows: ApiRow[] = [
  { name: 'default', type: '—', description: 'Single-cap content when `keys` is omitted (e.g. a raw key).' },
];
</script>

<template>
  <StoryPage
    title="Kbd"
    :description="t('story.kbd.desc', 'A keyboard key hint. Renders one or more keys as subtle bordered caps; common tokens normalize to glyphs (mod → ⌘ on macOS / Ctrl elsewhere; Enter, Esc, Shift, arrows). Inline and platform-aware.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.kbd.a11y1', 'The visible caps are decorative (aria-hidden); the whole combination is exposed via an aria-label (e.g. “Command K”).') }}</li>
        <li>{{ t('story.kbd.a11y2', 'Platform detection maps the `mod` token to ⌘ on macOS and Ctrl elsewhere.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.kbd.single', 'Single keys')">
      <StoryGrid align="center">
        <Kbd :keys="['k']" />
        <Kbd :keys="['enter']" />
        <Kbd :keys="['esc']" />
        <Kbd :keys="['shift']" />
        <Kbd :keys="['tab']" />
        <Kbd :keys="['space']" />
        <Kbd :keys="['up']" />
        <Kbd :keys="['down']" />
        <Kbd :keys="['left']" />
        <Kbd :keys="['right']" />
      </StoryGrid>
    </StorySection>

    <StorySection
      :title="t('story.kbd.combos', 'Combinations')"
      :description="platformNote"
    >
      <StoryGrid align="center">
        <Kbd :keys="['mod', 'k']" />
        <Kbd :keys="['mod', 'shift', 'p']" />
        <Kbd :keys="['mod', 'enter']" />
        <Kbd :keys="['shift', 'tab']" />
        <Kbd :keys="['alt', 'left']" />
      </StoryGrid>
    </StorySection>

    <StorySection :title="t('story.kbd.sizes', 'Sizes')">
      <StoryGrid align="center">
        <Kbd :keys="['mod', 'k']" size="sm" />
        <Kbd :keys="['mod', 'k']" size="md" />
      </StoryGrid>
    </StorySection>

    <StorySection :title="t('story.kbd.slot', 'Slot mode')" :description="t('story.kbd.slotDesc', 'A single cap wrapping arbitrary text.')">
      <StoryGrid align="center">
        <Kbd>F1</Kbd>
        <Kbd>PgUp</Kbd>
        <Kbd ariaLabel="Function key F5">F5</Kbd>
      </StoryGrid>
    </StorySection>

    <StorySection :title="t('story.kbd.inline', 'Inline in text')">
      <p class="text-next-sm text-next-fg">
        {{ t('story.kbd.inlineBefore', 'Press') }} <Kbd :keys="['mod', 'k']" /> {{ t('story.kbd.inlineAfter', 'to open the command palette, then') }} <Kbd :keys="['enter']" /> {{ t('story.kbd.inlineRun', 'to run.') }}
      </p>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Slot props" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
