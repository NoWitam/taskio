<script setup lang="ts">
// TextInput — single-line text control for the "next" frontend.
//
// Renders THROUGH FieldShell: the border + the state line (focus/error/success/
// dirty) live on the shell; this component only owns the <input> and the
// affordances (leading/trailing icon, clear button, password toggle, loading
// spinner), which it places into the shell's #leading / #trailing slots.
//
// Long values truncate with an ellipsis and NEVER resize the field — the height
// is fixed per size and the input is `min-w-0` inside the shell body.
//
// Wiring: consumes a surrounding FormField (id / describedById / validation state /
// disabled / readonly / required) when present, but also accepts its own props so
// it works standalone. When inside a FormField it registers its value for dirty
// tracking; standalone it can be told `dirty`/`success` directly.
import { computed, onBeforeUnmount, ref, useSlots } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Spinner from '../primitives/Spinner.vue';
import FieldShell from './FieldShell.vue';
import { useFormField } from './formField';
import { FIELD_PADDING_X, type ControlSize, useControlSize } from './fieldShell';

type TextInputType = 'text' | 'email' | 'password' | 'search' | 'url' | 'tel';

const props = withDefaults(
  defineProps<{
    type?: TextInputType;
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Force invalid styling standalone (FormField sets this for you). */
    ariaInvalid?: boolean;
    /** Force success styling standalone (FormField sets this for you). */
    success?: boolean;
    /** Force the subtle "dirty" accent standalone (FormField tracks this for you). */
    dirty?: boolean;
    /** Standalone id (FormField provides one otherwise). */
    id?: string;
    /** Standalone aria-describedby (FormField provides one otherwise). */
    describedById?: string;
    /** Leading icon inside the control. */
    leadingIcon?: IconName;
    /** Trailing icon inside the control (hidden while loading/clearable shown). */
    trailingIcon?: IconName;
    /** Trailing spinner; also marks the control aria-busy. */
    loading?: boolean;
    /** Show a clear (✕) button when there's a value. Auto-on for `search`. */
    clearable?: boolean;
    name?: string;
    autocomplete?: string;
    /** Accessible label when used with no FormField + no visible <label>. */
    ariaLabel?: string;
  }>(),
  {
    // Absence must stay `undefined` (no Boolean cast to `false`) so it defers
    // to the surrounding FormField — see `formField.ts`.
    ariaInvalid: undefined,
    type: 'text',
    disabled: false,
    readonly: false,
    success: false,
    dirty: false,
    loading: false,
  },
);

const emit = defineEmits<{ (e: 'clear'): void }>();

const model = defineModel<string>({ default: '' });

const field = useFormField();
// Effective size: explicit prop > ambient (FilterBar) > family default `md`.
const controlSize = useControlSize(() => props.size);

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

// Register this value for the FormField's dirty tracking.
if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

// Password show/hide.
const reveal = ref(false);
const effectiveType = computed(() => {
  if (props.type === 'password') return reveal.value ? 'text' : 'password';
  return props.type;
});

// `clearable` is a feature flag (the slot space is reserved whenever the feature
// is on); `showClear` only controls the button's VISIBILITY so toggling it never
// changes the input's width (no-grow rule). Search inputs are implicitly clearable.
const clearEnabled = computed(
  () => (props.clearable || props.type === 'search') && !disabled.value && !readonly.value,
);
const showClear = computed(
  () => clearEnabled.value && !props.loading && !!model.value,
);
const showPasswordToggle = computed(
  () => props.type === 'password' && !disabled.value,
);

function clear(): void {
  model.value = '';
  emit('clear');
}

const slots = useSlots();
const hasLeading = computed(() => !!props.leadingIcon || !!slots.leading);
// Reserve the trailing region whenever a toggleable affordance COULD appear
// (clearable/password/loading feature enabled) — not just while it is visible —
// so the text area width is stable as the clear/spinner come and go.
const hasTrailing = computed(
  () =>
    props.loading ||
    clearEnabled.value ||
    showPasswordToggle.value ||
    !!props.trailingIcon ||
    !!slots.trailing,
);

// Drop the input's edge padding when an adornment provides the inset there.
const inputPadding = computed(() => {
  const l = hasLeading.value ? 'pl-next-2' : FIELD_PADDING_X[controlSize.value];
  const r = hasTrailing.value ? 'pr-next-2' : FIELD_PADDING_X[controlSize.value];
  return [l, r];
});
</script>

<template>
  <FieldShell
    :size="controlSize"
    :disabled="disabled"
    :readonly="readonly"
    :error="invalid"
    :success="success"
    :dirty="dirty"
  >
    <template v-if="hasLeading" #leading>
      <slot name="leading">
        <Icon v-if="leadingIcon" :name="leadingIcon" />
      </slot>
    </template>

    <input
      :id="resolvedId"
      v-model="model"
      :type="effectiveType"
      :name="name"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      :autocomplete="autocomplete"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="resolvedDescribedBy"
      :aria-required="required ? 'true' : undefined"
      :aria-busy="loading ? 'true' : undefined"
      :aria-label="ariaLabel"
      class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
      :class="inputPadding"
    />

    <template v-if="hasTrailing" #trailing>
      <span class="flex items-center gap-next-1">
        <!-- Clear/loading share one fixed-width slot: when `clearable` is enabled
             the box is always reserved (visibility toggle, not v-if) so the input
             width never shifts as the clear button comes and goes. While loading,
             the spinner takes the same box instead of the clear button. -->
        <span
          v-if="clearEnabled || loading"
          class="flex h-5 w-5 items-center justify-center"
        >
          <Spinner
            v-if="loading"
            :size="controlSize === 'lg' ? 'sm' : 'xs'"
            tone="muted"
            decorative
          />
          <button
            v-else
            type="button"
            class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
            :class="showClear ? '' : 'invisible'"
            :tabindex="showClear ? undefined : -1"
            :aria-hidden="showClear ? undefined : 'true'"
            aria-label="Clear"
            @click="clear"
          >
            <Icon name="x" />
          </button>
        </span>

        <button
          v-if="showPasswordToggle"
          type="button"
          class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
          :aria-label="reveal ? 'Hide password' : 'Show password'"
          :aria-pressed="reveal"
          @click="reveal = !reveal"
        >
          <Icon :name="reveal ? 'eye-off' : 'eye'" />
        </button>

        <span v-if="trailingIcon && !loading && !showClear" class="text-next-muted-foreground">
          <Icon :name="trailingIcon" />
        </span>

        <slot name="trailing" />
      </span>
    </template>
  </FieldShell>
</template>

<style scoped>
/* Suppress the BROWSER's native clear control for `type="search"` (WebKit/Blink
   render their own ✕). We provide our own clear button in the trailing slot, so
   the native one would show a duplicate second ✕. */
input[type='search']::-webkit-search-cancel-button,
input[type='search']::-webkit-search-decoration {
  -webkit-appearance: none;
  appearance: none;
}
</style>
