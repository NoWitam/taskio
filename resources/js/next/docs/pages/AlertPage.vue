<script setup lang="ts">
// Gallery: Alert — an inline message block (NOT a toast). Variants, sizes,
// title + body, actions, and dismiss. All visible text via t().
import { ref } from 'vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

type Variant = 'info' | 'success' | 'warning' | 'danger';
const variants: Variant[] = ['info', 'success', 'warning', 'danger'];

const dismissed = ref<Record<string, boolean>>({});
function reset(): void {
  dismissed.value = {};
}

const propRows: ApiRow[] = [
  { name: 'variant', type: "'info' | 'success' | 'warning' | 'danger'", default: "'info'", description: 'Intent → surface tint + icon + ARIA role.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Padding + text scale.' },
  { name: 'title', type: 'string', default: '—', description: 'Optional bold title above the body.' },
  { name: 'dismissible', type: 'boolean', default: 'false', description: 'Render a ✕ that emits `dismiss`.' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Override the default per-variant icon.' },
  { name: 'dismissLabel', type: 'string', default: "t('alert.dismiss')", description: 'aria-label for the ✕.' },
];
const eventRows: ApiRow[] = [
  { name: 'dismiss', type: '—', description: 'Emitted when the ✕ is activated.' },
];
const slotRows: ApiRow[] = [
  { name: 'default', type: '—', description: 'Message body.' },
  { name: 'actions', type: '—', description: 'Optional action row (Buttons / Links).' },
];
</script>

<template>
  <StoryPage
    title="Alert"
    description="An inline message block that stays in document flow (not a toast). Four variants pair a subtle surface with a matching icon — color is never the only signal. Optional title, body, actions, and a dismiss ✕. info/success are polite (role=status); warning/danger are assertive (role=alert)."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>info / success → <code>role="status"</code> (polite); warning / danger → <code>role="alert"</code> (assertive).</li>
        <li>The leading icon is decorative; meaning lives in the icon shape + text, never color alone.</li>
        <li>The dismiss ✕ is a real button with a translated <code>aria-label</code>.</li>
      </ul>
    </template>

    <StorySection title="Variants" description="info / success / warning / danger.">
      <div class="flex flex-col gap-next-3">
        <Alert v-for="v in variants" :key="v" :variant="v" :title="v">
          A short {{ v }} message that explains what happened.
        </Alert>
      </div>
    </StorySection>

    <StorySection title="Sizes" description="sm and md.">
      <div class="flex flex-col gap-next-3">
        <Alert variant="info" size="sm" title="Small">Compact inline note.</Alert>
        <Alert variant="info" size="md" title="Medium">Default inline note.</Alert>
      </div>
    </StorySection>

    <StorySection title="Title only / body only">
      <StoryGrid :cols="1">
        <StoryCell label="title only">
          <Alert variant="success" title="Saved successfully" class="w-full" />
        </StoryCell>
        <StoryCell label="body only">
          <Alert variant="warning" class="w-full">Your trial ends in 3 days.</Alert>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="With actions" description="An #actions slot for inline buttons.">
      <Alert variant="danger" title="Couldn’t connect">
        We lost the connection to the server.
        <template #actions>
          <Button variant="danger" size="sm">{{ t('common.retry', 'Retry') }}</Button>
          <Button variant="ghost" size="sm">{{ t('common.cancel', 'Cancel') }}</Button>
        </template>
      </Alert>
    </StorySection>

    <StorySection title="Dismissible" description="Emits `dismiss`; the host removes it.">
      <div class="flex flex-col gap-next-3">
        <Alert
          v-for="v in variants"
          v-show="!dismissed[v]"
          :key="`d-${v}`"
          :variant="v"
          :title="v"
          dismissible
          @dismiss="dismissed[v] = true"
        >
          Dismiss me with the ✕.
        </Alert>
        <Button variant="outline" size="sm" class="self-start" @click="reset">{{ t('common.reset', 'Reset') }}</Button>
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
