<script setup lang="ts" generic="V extends string = string">
// Stepper — a multi-step progress / wizard indicator for the "next" frontend.
//
// Item-driven API: pass `steps` ({ value, label, description?, status?, disabled? }).
// Orientation horizontal|vertical. Status complete|current|upcoming|error is shown
// via an icon/number INSIDE a status-toned node PLUS color (never color alone).
// Connector lines join steps; a completed connector reads in the primary tone.
//
// Two modes:
//   - display-only (default): the steps are an ordered list with `aria-current`.
//   - `clickable`: each step is a real <button> (emits `step-click` + `update:active`),
//     with its status in the accessible name; disabled/upcoming gating via `disabled`
//     and `linear` (block jumping ahead of the current step).
//
// Optional wizard body: the `#content` scoped slot renders the active step's panel
// (receives `{ value, index, step }`). The active step is `v-model:active`.
//
// A11y: an ordered `<ol>`; the current step carries `aria-current="step"`; when
// clickable, each step is a button whose label includes the status word so the
// state is conveyed without color. Connectors + number/icon nodes are decorative.
import { computed } from 'vue';
import Icon from '../primitives/Icon.vue';
import { useI18n } from '../../app/i18n';

export type StepStatus = 'complete' | 'current' | 'upcoming' | 'error';

export interface StepItem<T extends string = string> {
  value: T;
  label: string;
  description?: string;
  /** Explicit status; when omitted it's derived from the active step. */
  status?: StepStatus;
  disabled?: boolean;
}

type Orientation = 'horizontal' | 'vertical';

const props = withDefaults(
  defineProps<{
    steps: StepItem<V>[];
    orientation?: Orientation;
    /** Make steps clickable (real buttons that emit navigation). */
    clickable?: boolean;
    /** When clickable, block navigating to steps AFTER the current one. */
    linear?: boolean;
    /** Accessible label for the step list. */
    ariaLabel?: string;
  }>(),
  {
    orientation: 'horizontal',
    clickable: false,
    linear: false,
  },
);

const emit = defineEmits<{
  (e: 'step-click', value: V): void;
}>();

const { t } = useI18n();

// The active step (v-model:active). Used to DERIVE status when a step doesn't set
// its own, and to drive the optional #content panel.
const active = defineModel<V | null>('active', { default: null });

const activeIndex = computed(() =>
  props.steps.findIndex((s) => s.value === active.value),
);

/** Derive a status for a step from the active index when not explicitly set. */
function statusOf(step: StepItem<V>, index: number): StepStatus {
  if (step.status) return step.status;
  const ai = activeIndex.value;
  if (ai < 0) return index === 0 ? 'current' : 'upcoming';
  if (index < ai) return 'complete';
  if (index === ai) return 'current';
  return 'upcoming';
}

// Resolved at render time so a locale switch re-translates the status word.
function statusWord(status: StepStatus): string {
  switch (status) {
    case 'complete':
      return t('stepper.statusComplete', 'completed');
    case 'current':
      return t('stepper.statusCurrent', 'current');
    case 'upcoming':
      return t('stepper.statusUpcoming', 'upcoming');
    case 'error':
      return t('stepper.statusError', 'error');
  }
}

// Node (the numbered/icon circle) treatment per status.
const NODE_CLASS: Record<StepStatus, string> = {
  complete: 'bg-next-primary text-next-primary-foreground border-next-primary',
  current: 'bg-next-primary-subtle text-next-primary-subtle-foreground border-next-primary',
  upcoming: 'bg-next-card text-next-muted-foreground border-next-border',
  error: 'bg-next-danger text-next-danger-foreground border-next-danger',
};

const LABEL_CLASS: Record<StepStatus, string> = {
  complete: 'text-next-fg',
  current: 'text-next-fg font-next-semibold',
  upcoming: 'text-next-muted-foreground',
  error: 'text-next-danger',
};

function canClick(step: StepItem<V>, index: number): boolean {
  if (!props.clickable || step.disabled) return false;
  if (props.linear && activeIndex.value >= 0 && index > activeIndex.value) return false;
  return true;
}

function onClick(step: StepItem<V>, index: number): void {
  if (!canClick(step, index)) return;
  active.value = step.value;
  emit('step-click', step.value);
}

function stepAria(step: StepItem<V>, index: number): string {
  const status = statusOf(step, index);
  return t('stepper.stepLabel', '{label}, step {index}: {status}', {
    label: step.label,
    index: index + 1,
    status: statusWord(status),
  });
}

const isHorizontal = computed(() => props.orientation === 'horizontal');

const activeStep = computed(() => props.steps[activeIndex.value] ?? null);
</script>

<template>
  <div class="next-stepper flex flex-col gap-next-6">
    <ol
      class="next-stepper__list flex"
      :class="isHorizontal ? 'flex-row items-start' : 'flex-col'"
      :aria-label="ariaLabel"
    >
      <li
        v-for="(step, index) in steps"
        :key="step.value"
        class="next-stepper__item relative flex min-w-0"
        :class="[
          isHorizontal ? 'flex-1 flex-col items-center text-center' : 'flex-row items-stretch gap-next-3',
          index === steps.length - 1 ? '' : isHorizontal ? '' : 'pb-next-6',
        ]"
        :aria-current="statusOf(step, index) === 'current' ? 'step' : undefined"
      >
        <!-- Connector BEFORE the node (horizontal: a line spanning toward the
             previous step). Drawn behind the node row. -->
        <span
          v-if="isHorizontal && index > 0"
          class="next-stepper__connector pointer-events-none absolute top-4 right-1/2 left-[-50%] h-0.5 -translate-y-1/2"
          :class="statusOf(step, index) === 'complete' || statusOf(step, index) === 'current' ? 'bg-next-primary' : 'bg-next-border'"
          aria-hidden="true"
        />

        <!-- Node + label cluster. -->
        <component
          :is="canClick(step, index) ? 'button' : 'div'"
          :type="canClick(step, index) ? 'button' : undefined"
          :disabled="canClick(step, index) ? undefined : (clickable && step.disabled ? true : undefined)"
          :aria-label="clickable ? stepAria(step, index) : undefined"
          class="next-stepper__control relative flex outline-none focus-visible:ring-2 focus-visible:ring-next-ring focus-visible:ring-offset-2 focus-visible:ring-offset-next-bg rounded-next-md"
          :class="[
            isHorizontal ? 'flex-col items-center gap-next-2' : 'flex-row items-start gap-next-3 text-left',
            canClick(step, index) ? 'cursor-pointer' : '',
            clickable && step.disabled ? 'cursor-not-allowed opacity-60' : '',
          ]"
          @click="onClick(step, index)"
        >
          <!-- Vertical connector running down from this node. -->
          <span
            v-if="!isHorizontal && index < steps.length - 1"
            class="next-stepper__connector pointer-events-none absolute top-8 left-4 bottom-[-1.5rem] w-0.5 -translate-x-1/2"
            :class="statusOf(step, index) === 'complete' ? 'bg-next-primary' : 'bg-next-border'"
            aria-hidden="true"
          />

          <!-- Node: number, or a check/x icon for complete/error. -->
          <span
            class="next-stepper__node relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-next-full border text-next-sm font-next-semibold tabular-nums"
            :class="NODE_CLASS[statusOf(step, index)]"
            aria-hidden="true"
          >
            <Icon v-if="statusOf(step, index) === 'complete'" name="check" :stroke-width="3" class="text-[1em]" />
            <Icon v-else-if="statusOf(step, index) === 'error'" name="x" :stroke-width="3" class="text-[1em]" />
            <template v-else>{{ index + 1 }}</template>
          </span>

          <!-- Label + description. -->
          <span class="flex min-w-0 flex-col gap-next-0_5" :class="isHorizontal ? 'items-center' : 'items-start pt-next-1'">
            <span class="text-next-sm" :class="LABEL_CLASS[statusOf(step, index)]">{{ step.label }}</span>
            <span v-if="step.description" class="text-next-xs text-next-muted-foreground">{{ step.description }}</span>
          </span>
        </component>
      </li>
    </ol>

    <!-- Optional wizard body: renders the active step's panel. -->
    <div v-if="$slots.content && activeStep" class="next-stepper__content">
      <slot name="content" :value="activeStep.value" :index="activeIndex" :step="activeStep" />
    </div>
  </div>
</template>
