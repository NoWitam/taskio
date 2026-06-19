<script setup lang="ts">
// FormField — the wrapper that owns labelling + description + validation message
// wiring for every "next" form control, and forwards a resolved validation state
// down to the control's FieldShell.
//
// Responsibilities:
//  - renders the <label> (with an optional required asterisk) wired to the
//    control id,
//  - renders optional description/help text and a validation message (error OR
//    success), each with a stable id referenced via `aria-describedby`,
//  - tracks DIRTINESS: controls register a value-reader; when any registered value
//    differs from its first-seen value the field is "dirty" (changed, not yet
//    validated) and the FieldShell shows the subtle dirty accent,
//  - provides the FormField context (id, describedById, invalid, valid, dirty,
//    validationState, disabled, readonly, required) consumed by the control(s),
//    and exposes the same values via a scoped slot for non-aware controls.
//
// One FormField can host MULTIPLE controls under a single label/description/
// message. Each control still works standalone (own id/state) outside a FormField.
//
// Validation state precedence surfaced to controls:
//   error > success > dirty > none   (mirrors the FieldShell color precedence).
// State is never color-only — error/success render an icon + message too.
import { computed, ref, toRef } from 'vue';
import Icon from '../primitives/Icon.vue';
import {
  provideFormField,
  nextId,
  type FieldValidationState,
} from './formField';

const props = withDefaults(
  defineProps<{
    /** Visible field label. */
    label?: string;
    /** Marks the field required (asterisk + aria-required on the control). */
    required?: boolean;
    /** Help/description text shown under the label, before the control. */
    description?: string;
    /** Error message; when set the field is invalid (danger line + message). */
    error?: string;
    /** Success message; when set (and no error) the field shows the success line. */
    success?: string;
    /** Dims the label + control(s) and disables them. */
    disabled?: boolean;
    /** Marks the control(s) read-only (passed through to the shell). */
    readonly?: boolean;
    /** Explicit control id. Auto-generated when omitted. */
    id?: string;
    /** Hide the label visually but keep it for screen readers. */
    hideLabel?: boolean;
  }>(),
  {
    required: false,
    disabled: false,
    readonly: false,
    hideLabel: false,
  },
);

const generatedId = nextId();
const controlId = computed(() => props.id ?? generatedId);

const descriptionId = computed(() =>
  props.description ? `${controlId.value}-description` : undefined,
);
const messageId = computed(() =>
  props.error || props.success ? `${controlId.value}-message` : undefined,
);

const invalid = computed(() => !!props.error);
const valid = computed(() => !invalid.value && !!props.success);

// --- Dirty tracking -------------------------------------------------------
// Controls register a value-reader; we snapshot the first value seen for each and
// mark the field dirty once any current value differs from its snapshot. This lets
// one FormField track several controls. Snapshots are compared via JSON for the
// simple scalar/array values our controls hold.
const readers = ref<Array<() => unknown>>([]);
const baselines = new WeakMap<() => unknown, string>();

function registerValue(read: () => unknown): () => void {
  baselines.set(read, JSON.stringify(read()));
  readers.value = [...readers.value, read];
  return () => {
    readers.value = readers.value.filter((r) => r !== read);
  };
}

const dirty = computed(() =>
  readers.value.some((read) => {
    const base = baselines.get(read);
    return base !== undefined && JSON.stringify(read()) !== base;
  }),
);

// Resolved state surfaced to controls + the FieldShell. error/success are
// caller-driven verdicts and outrank the automatic dirty signal.
const validationState = computed<FieldValidationState>(() => {
  if (invalid.value) return 'error';
  if (valid.value) return 'success';
  if (dirty.value) return 'dirty';
  return 'none';
});

const describedById = computed(() => {
  const ids = [descriptionId.value, messageId.value].filter(Boolean);
  return ids.length ? ids.join(' ') : undefined;
});

provideFormField({
  id: controlId,
  describedById,
  invalid,
  valid,
  dirty,
  validationState,
  disabled: toRef(props, 'disabled'),
  readonly: toRef(props, 'readonly'),
  required: toRef(props, 'required'),
  registerValue,
});

// Scoped-slot payload, mirroring the provided context for non-aware controls.
const slotProps = computed(() => ({
  id: controlId.value,
  describedById: describedById.value,
  invalid: invalid.value,
  valid: valid.value,
  dirty: dirty.value,
  validationState: validationState.value,
  disabled: props.disabled,
  readonly: props.readonly,
  required: props.required,
}));
</script>

<template>
  <div
    class="next-form-field flex flex-col gap-next-1_5"
    :class="disabled ? 'opacity-70' : ''"
  >
    <label
      v-if="label"
      :for="controlId"
      class="text-next-sm font-next-medium text-next-fg"
      :class="hideLabel ? 'sr-only' : ''"
    >
      {{ label }}
      <span v-if="required" class="text-next-danger" aria-hidden="true">*</span>
      <span v-if="required" class="sr-only">(required)</span>
    </label>

    <p
      v-if="description"
      :id="descriptionId"
      class="text-next-xs text-next-muted-foreground"
    >
      {{ description }}
    </p>

    <!-- Control(s): context-aware controls consume provide/inject; others can read
         the scoped slot payload. One or several controls may live here. -->
    <slot v-bind="slotProps" />

    <!-- Validation message: error takes priority; otherwise success. Never color
         alone — each pairs an icon with the message. -->
    <p
      v-if="error"
      :id="messageId"
      class="flex items-start gap-next-1 text-next-xs text-next-danger"
      role="alert"
    >
      <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
      <span>{{ error }}</span>
    </p>
    <p
      v-else-if="success"
      :id="messageId"
      class="flex items-start gap-next-1 text-next-xs text-next-success"
      role="status"
    >
      <Icon name="check-circle" class="mt-px shrink-0" aria-hidden="true" />
      <span>{{ success }}</span>
    </p>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
