<script setup lang="ts">
// Gallery: Banner — a page/app-level full-width announcement bar (distinct from
// the inline Alert). Variants, actions, dismiss, and a sticky demo. Text via t().
import { ref } from 'vue';
import Banner from '../../ui/feedback/Banner.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

type Variant = 'neutral' | 'info' | 'primary' | 'warning' | 'danger';
const variants: Variant[] = ['neutral', 'info', 'primary', 'warning', 'danger'];

const dismissed = ref<Record<string, boolean>>({});
function reset(): void {
  dismissed.value = {};
}

const propRows: ApiRow[] = [
  { name: 'variant', type: "'neutral' | 'info' | 'primary' | 'warning' | 'danger'", default: "'info'", description: 'Intent → surface tint + icon + ARIA role.' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Override the default per-variant icon.' },
  { name: 'hideIcon', type: 'boolean', default: 'false', description: 'Hide the leading icon entirely.' },
  { name: 'dismissible', type: 'boolean', default: 'false', description: 'Render a ✕ that emits `dismiss`.' },
  { name: 'sticky', type: 'boolean', default: 'false', description: 'Pin to the top of the scroll container.' },
  { name: 'dismissLabel', type: 'string', default: "t('banner.dismiss')", description: 'aria-label for the ✕.' },
];
const eventRows: ApiRow[] = [
  { name: 'dismiss', type: '—', description: 'Emitted when the ✕ is activated.' },
];
const slotRows: ApiRow[] = [
  { name: 'default', type: '—', description: 'Banner message.' },
  { name: 'actions', type: '—', description: 'Trailing action(s) (Buttons / Links).' },
];
</script>

<template>
  <StoryPage
    title="Banner"
    description="A full-width, page/app-level announcement bar — for maintenance notices, trial reminders, or a new feature. Distinct from the inline Alert: it spans its container edge-to-edge, reads denser, and can be pinned to the top with `sticky`."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>neutral / info / primary → <code>role="status"</code> (polite); warning / danger → <code>role="alert"</code> (assertive).</li>
        <li>The leading icon is decorative; pair color with the icon + text.</li>
        <li>The dismiss ✕ is a real button with a translated <code>aria-label</code>.</li>
      </ul>
    </template>

    <StorySection title="Variants" description="neutral / info / primary / warning / danger (full-bleed within the section).">
      <div class="-mx-next-4 -my-next-4 flex flex-col">
        <Banner v-for="v in variants" :key="v" :variant="v">
          <span class="font-next-medium">{{ v }}:</span> an app-wide announcement bar.
        </Banner>
      </div>
    </StorySection>

    <StorySection title="With actions">
      <div class="-mx-next-4 -my-next-4">
        <Banner variant="primary">
          A new dashboard is available.
          <template #actions>
            <Button variant="subtle" size="sm">{{ t('common.open', 'Open') }}</Button>
          </template>
        </Banner>
      </div>
    </StorySection>

    <StorySection title="Dismissible">
      <div class="-mx-next-4 -my-next-4 flex flex-col">
        <Banner
          v-for="v in variants"
          v-show="!dismissed[v]"
          :key="`d-${v}`"
          :variant="v"
          dismissible
          @dismiss="dismissed[v] = true"
        >
          Dismiss me with the ✕.
        </Banner>
        <div class="p-next-4">
          <Button variant="outline" size="sm" @click="reset">{{ t('common.reset', 'Reset') }}</Button>
        </div>
      </div>
    </StorySection>

    <StorySection title="Sticky" description="Pinned to the top of a scroll container (scroll the box).">
      <div class="h-48 overflow-y-auto rounded-next-md border border-next-border">
        <Banner variant="warning" sticky>Scheduled maintenance tonight at 22:00.</Banner>
        <div class="space-y-next-3 p-next-4 text-next-sm text-next-muted-foreground">
          <p v-for="i in 12" :key="i">Scrollable content line {{ i }}.</p>
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
