<script setup lang="ts">
// WorkflowScheduleWindowField — the shared "od–do" window pattern (§4.5.6), reused by
// all four bounded axes (every_minutes / every_hours / every_n_days / every_n_months)
// so it is learned ONCE. It renders INLINE — the toggle and the "od {from} do {to}"
// fragment sit on the SAME wrapping line as the head sentence.
//
// REV5.1 (user feedback): the fragment is ALWAYS in the DOM. A bare `Switch` (NO visible
// label in the sentence) flips the two inputs between enabled and DISABLED instead of
// mounting/unmounting them — matching the user's original "od Y do Z, where Y and Z are
// disabled by default". Switch is chosen over Checkbox because it exposes a reliable
// accessible name ON the interactive element via its `ariaLabel` prop with NO visible
// text; the bare Checkbox has no aria-label path to its (visually hidden) input. So the
// previously VISIBLE toggle string survives ONLY as the switch's accessible name.
//
// The fragment text comes from a SLOTTED template (`<axis>.card.<mode>.window`, e.g.
// "od {from} do {to} dnia miesiąca") SPLIT so PL/EN word order lives in the string; the
// two inputs are woven at `{from}`/`{to}` via the caller's `#from`/`#to` slots
// (TimePicker² / NumberInput² / Select²). The component owns ONLY the toggle, the split
// layout, the shared `disabled` slot-prop, and the live `from < to` error line — the
// caller keeps its own typed axis slice and drops `window` back to undefined in its
// `toggle` handler.
//
// It uses `display: contents` so its children flow directly into the caller's
// `flex-wrap` sentence line; the error line is `w-full` so it breaks onto its own row.
import { computed } from 'vue';
import Switch from '../../ui/forms/Switch.vue';
import { splitSentenceTemplate } from './workflowSchedule';

const props = withDefaults(
  defineProps<{
    /** Whether the window is active (switch on → the from/to inputs are enabled). */
    enabled: boolean;
    /** The toggle's ACCESSIBLE NAME (`workflows.schedule.window.toggle.<axis>`). It has
     *  NO visible text in the sentence, so this is the switch's aria-label only. */
    toggleLabel: string;
    /** The slotted "od {from} do {to}…" template for THIS axis (`<mode>.window`). */
    windowTemplate: string;
    /** The live `from < to` (or range) error, shown on the fragment when enabled. */
    error?: string;
  }>(),
  { error: undefined },
);

const emit = defineEmits<{ (e: 'toggle', checked: boolean): void }>();

// The two woven inputs receive `disabled` from HERE so the enabled↔disabled mapping is
// owned ONCE by the shared field (callers just spread the slot-prop onto their control).
defineSlots<{
  from(props: { disabled: boolean }): any;
  to(props: { disabled: boolean }): any;
}>();

/** The window sentence split into ordered literal/slot segments (§4.5.12). */
const segments = computed(() => splitSentenceTemplate(props.windowTemplate));
</script>

<template>
  <span class="contents">
    <!-- Bare toggle (no visible label): flips the always-visible from/to inputs between
         enabled and disabled. Its accessible name is the reworded `window.toggle.*`. -->
    <Switch
      :model-value="enabled"
      :aria-label="toggleLabel"
      size="sm"
      @update:model-value="emit('toggle', $event)"
    />

    <!-- The "od {from} do {to}" fragment — ALWAYS rendered; the two inputs are disabled
         (via the `disabled` slot-prop) while the switch is off, and the literals dim to
         match. Order lives in the string, so PL/EN reorder freely. -->
    <template v-for="(seg, i) in segments" :key="i">
      <span
        v-if="seg.type === 'text'"
        class="text-next-sm text-next-muted-foreground"
        :class="{ 'opacity-60': !enabled }"
        >{{ seg.value }}</span
      >
      <slot v-else-if="seg.name === 'from'" name="from" :disabled="!enabled" />
      <slot v-else-if="seg.name === 'to'" name="to" :disabled="!enabled" />
    </template>

    <!-- The live from<to error breaks onto its own row within the sentence flex. -->
    <p v-if="enabled && error" class="w-full text-next-xs text-next-danger" role="alert">{{ error }}</p>
  </span>
</template>
