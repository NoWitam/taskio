<script setup lang="ts">
// Gallery: Progress (linear) + CircularProgress (ring). Determinate (slider-driven)
// + indeterminate, sizes, tones, labels/percentage. Text via t().
import { ref } from 'vue';
import Progress from '../../ui/feedback/Progress.vue';
import CircularProgress from '../../ui/feedback/CircularProgress.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

type Tone = 'primary' | 'success' | 'warning' | 'danger';
const tones: Tone[] = ['primary', 'success', 'warning', 'danger'];

const value = ref(40);

const propRows: ApiRow[] = [
  { name: 'value', type: 'number', default: '0', description: 'Current value (ignored when indeterminate).' },
  { name: 'max', type: 'number', default: '100', description: 'Maximum value.' },
  { name: 'indeterminate', type: 'boolean', default: 'false', description: 'Animated travelling bar; omits aria-valuenow.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Track height.' },
  { name: 'tone', type: "'primary' | 'success' | 'warning' | 'danger'", default: "'primary'", description: 'Fill color.' },
  { name: 'label', type: 'string', default: '—', description: 'Visible label above the track.' },
  { name: 'showPercentage', type: 'boolean', default: 'false', description: 'Show the computed % (determinate only).' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label when no visible label.' },
];

const circularPropRows: ApiRow[] = [
  { name: 'value / max', type: 'number', default: '0 / 100', description: 'Determinate arc length.' },
  { name: 'indeterminate', type: 'boolean', default: 'false', description: 'Spinning partial arc; omits aria-valuenow.' },
  { name: 'size', type: "'sm' | 'md' | 'lg' | 'xl'", default: "'md'", description: 'Ring diameter + stroke width.' },
  { name: 'tone', type: "'primary' | 'success' | 'warning' | 'danger'", default: "'primary'", description: 'Arc color.' },
  { name: 'showPercentage', type: 'boolean', default: 'false', description: 'Center % label (determinate only).' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the ring.' },
];
</script>

<template>
  <StoryPage
    title="Progress"
    description="Linear and circular progress. Both support determinate (value/max with a smooth transition) and indeterminate (animated, reduced-motion aware) modes, with sizes and tones. Use linear for inline/page progress and circular for compact, contained indicators."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>role="progressbar"</code> with <code>aria-valuemin</code> / <code>aria-valuemax</code>; <code>aria-valuenow</code> is set only when determinate (omitted while indeterminate).</li>
        <li>The percentage / center text is decorative; the value lives on the progressbar.</li>
        <li>Indeterminate animations collapse under <code>prefers-reduced-motion</code>.</li>
      </ul>
    </template>

    <StorySection title="Linear — determinate (interactive)" description="Drag the slider to drive the bar.">
      <div class="flex flex-col gap-next-4">
        <input
          type="range"
          min="0"
          max="100"
          v-model.number="value"
          :aria-label="t('common.select', 'Select')"
          class="w-64 accent-[var(--color-next-primary)]"
        />
        <Progress :value="value" label="Upload" show-percentage class="max-w-md" />
      </div>
    </StorySection>

    <StorySection title="Linear — tones & sizes">
      <div class="flex max-w-md flex-col gap-next-4">
        <Progress v-for="tn in tones" :key="tn" :value="60" :tone="tn" :label="tn" show-percentage />
        <div class="flex flex-col gap-next-2 pt-next-2">
          <Progress :value="50" size="sm" aria-label="sm progress" />
          <Progress :value="50" size="md" aria-label="md progress" />
        </div>
      </div>
    </StorySection>

    <StorySection title="Linear — indeterminate">
      <div class="flex max-w-md flex-col gap-next-3">
        <Progress indeterminate aria-label="Loading" />
        <Progress indeterminate tone="success" size="sm" aria-label="Loading" />
      </div>
    </StorySection>

    <StorySection title="Circular — determinate">
      <StoryGrid align="center">
        <StoryCell label="sm"><CircularProgress :value="value" size="sm" /></StoryCell>
        <StoryCell label="md %"><CircularProgress :value="value" size="md" show-percentage /></StoryCell>
        <StoryCell label="lg %"><CircularProgress :value="value" size="lg" show-percentage /></StoryCell>
        <StoryCell label="xl %"><CircularProgress :value="value" size="xl" show-percentage /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Circular — tones">
      <StoryGrid align="center">
        <StoryCell v-for="tn in tones" :key="tn" :label="tn">
          <CircularProgress :value="70" :tone="tn" size="md" show-percentage />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Circular — indeterminate">
      <StoryGrid align="center">
        <StoryCell label="sm"><CircularProgress indeterminate size="sm" aria-label="Loading" /></StoryCell>
        <StoryCell label="md"><CircularProgress indeterminate size="md" aria-label="Loading" /></StoryCell>
        <StoryCell label="lg"><CircularProgress indeterminate size="lg" tone="success" aria-label="Loading" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Progress props" :rows="propRows" show-default />
        <ApiTable title="CircularProgress props" :rows="circularPropRows" show-default />
      </div>
    </StorySection>
  </StoryPage>
</template>
