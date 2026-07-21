<script setup lang="ts">
// Textarea — multi-line text control for the "next" frontend.
//
// It does NOT use FieldShell (whose height is fixed) because a textarea may grow
// in HEIGHT — but it reuses FieldShell's exact visual language: the same bordered
// surface and the same "state line" (an inset ring ON the border at offset 0) for
// focus / error / success / dirty, driven by the same `--field-line` var and the
// same precedence (disabled > error > success > focus > dirty > default). WIDTH
// stays fixed; only height grows (with `autoGrow`).
//
// Options: `autoGrow` (height tracks content, no inner scrollbar), an optional
// character counter, and a `resize` control (none | vertical). Consumes a
// surrounding FormField for id/describedby/validation/disabled, or standalone.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useFormField } from './formField';
import { resolveFieldState } from './fieldShell';

type ResizeMode = 'none' | 'vertical';

const props = withDefaults(
  defineProps<{
    placeholder?: string;
    rows?: number;
    disabled?: boolean;
    readonly?: boolean;
    /** Grow height to fit content (disables manual resize). */
    autoGrow?: boolean;
    /** Show a character counter under the control. */
    counter?: boolean;
    /** Hard character limit (also caps input + feeds the counter). */
    maxlength?: number;
    /** Manual resize affordance when not auto-growing. */
    resize?: ResizeMode;
    ariaInvalid?: boolean;
    /** Force success styling standalone (FormField sets this for you). */
    success?: boolean;
    /** Force the subtle "dirty" accent standalone (FormField tracks this). */
    dirty?: boolean;
    id?: string;
    describedById?: string;
    name?: string;
    ariaLabel?: string;
  }>(),
  {
    rows: 3,
    disabled: false,
    readonly: false,
    autoGrow: false,
    counter: false,
    resize: 'vertical',
    success: false,
    dirty: false,
  },
);

const model = defineModel<string>({ default: '' });

const field = useFormField();
const resolvedId = computed(() => props.id ?? field?.id.value);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const success = computed(() => props.success || (field?.valid.value ?? false));
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

const counterId = computed(() =>
  props.counter ? `${resolvedId.value ?? 'next-textarea'}-counter` : undefined,
);
const describedBy = computed(() => {
  const ids = [resolvedDescribedBy.value, counterId.value].filter(Boolean);
  return ids.length ? ids.join(' ') : undefined;
});

const charCount = computed(() => model.value?.length ?? 0);
const overLimit = computed(
  () => props.maxlength != null && charCount.value > props.maxlength,
);

const el = ref<HTMLTextAreaElement | null>(null);
function grow(): void {
  const node = el.value;
  if (!node || !props.autoGrow) return;
  // Bail when the field isn't laid out yet — e.g. mounted inside a hidden
  // (display:none) tab panel, where clientHeight/scrollHeight both read 0 and we
  // would otherwise pin `height: 0px` and collapse it to a sliver. It keeps its
  // `rows` height until shown, then re-measures as its content changes.
  if (node.offsetParent === null) return;
  node.style.height = 'auto';
  // Never shrink below the height implied by `rows` — auto-grow should grow FROM
  // that baseline, not collapse an empty field to a single line.
  const minHeight = node.clientHeight;
  node.style.height = `${Math.max(node.scrollHeight, minHeight)}px`;
}
watch(model, () => nextTick(grow));
watch(el, () => nextTick(grow));

const resizeClass = computed(() => {
  if (props.autoGrow) return 'resize-none overflow-hidden';
  return props.resize === 'none' ? 'resize-none' : 'resize-y';
});

// Same state model as FieldShell. We don't pass `focused` (CSS :focus-within
// drives the focus line). error/success keep their color even when focused.
const state = computed(() =>
  resolveFieldState({
    disabled: disabled.value,
    error: invalid.value,
    success: success.value,
    focused: false,
    dirty: dirty.value,
  }),
);
const lineVar = computed(() => {
  switch (state.value) {
    case 'error':
      return 'var(--color-next-danger)';
    case 'success':
      return 'var(--color-next-success)';
    case 'dirty':
      return 'var(--color-next-primary)';
    default:
      return 'var(--color-next-input)';
  }
});
const showRing = computed(
  () => state.value === 'error' || state.value === 'success',
);
</script>

<template>
  <div class="flex flex-col gap-next-1">
    <div
      class="next-textarea-shell"
      :class="[
        disabled ? 'is-disabled bg-next-muted' : readonly ? 'is-readonly bg-next-muted/50' : 'bg-next-card',
        !disabled && !readonly && !showRing && state !== 'dirty' ? 'allow-hover' : '',
        showRing ? 'has-ring' : '',
        `state-${state}`,
      ]"
      :style="{ '--field-line': lineVar }"
    >
      <textarea
        :id="resolvedId"
        ref="el"
        v-model="model"
        :rows="rows"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :maxlength="maxlength"
        :name="name"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy"
        :aria-required="required ? 'true' : undefined"
        :aria-label="ariaLabel"
        class="block w-full border-0 bg-transparent px-next-3 py-next-2 text-next-sm text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
        :class="resizeClass"
      />
    </div>

    <div
      v-if="counter"
      :id="counterId"
      class="self-end text-next-xs"
      :class="overLimit ? 'text-next-danger' : 'text-next-muted-foreground'"
      aria-live="polite"
    >
      {{ charCount }}<template v-if="maxlength"> / {{ maxlength }}</template>
    </div>
  </div>
</template>

<style scoped>
/* Mirror of FieldShell's surface/state-line — height free, width fixed. */
.next-textarea-shell {
  border-radius: var(--radius-next-md);
  border: 1px solid var(--field-line, var(--color-next-input));
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    box-shadow var(--duration-next-fast) var(--ease-next-standard);
}
.next-textarea-shell.has-ring {
  box-shadow: inset 0 0 0 1.5px var(--field-line);
}
.next-textarea-shell.is-disabled {
  opacity: 0.6;
}
.next-textarea-shell.allow-hover:hover {
  --field-line: color-mix(in srgb, var(--color-next-fg) 30%, transparent);
}
/* Focus line wins over default/dirty; error/success keep their own color. */
.next-textarea-shell:not(.is-disabled):not(.is-readonly):not(.state-error):not(
    .state-success
  ):focus-within {
  --field-line: var(--color-next-ring);
  box-shadow: inset 0 0 0 1.5px var(--color-next-ring);
}
</style>
