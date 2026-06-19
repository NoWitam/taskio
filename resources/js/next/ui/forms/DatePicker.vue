<script setup lang="ts">
// DatePicker — single date entry for the "next" frontend.
//
// A typeable text field (live-parsed `dd.mm.yyyy`) rendered through FieldShell,
// with a calendar popover (CalendarPanel inside FieldPopover). It consumes the
// surrounding FormField (id / describedById / validation / disabled / readonly)
// and works standalone too.
//
// MODEL CONTRACT: v-model is an ISO day string `yyyy-mm-dd` (or null) — NEVER a
// Date object, to avoid timezone drift. See `date/dateCore.ts`.
//
// Locale defaults are Polish (Monday week start, `dd.mm.yyyy`, `pl` names), all
// overridable via `locale` / `weekStartsOn` / `format`.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../primitives/Icon.vue';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import CalendarPanel from './date/CalendarPanel.vue';
import { useFormField, nextId } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';
import {
  clampDate,
  fromIsoDate,
  formatDate,
  maskDateInput,
  parseDateInput,
  toIsoDate,
  type DisabledDatePredicate,
  type WeekDay,
} from './date/dateCore';

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Min selectable day, ISO `yyyy-mm-dd`. */
    min?: string | null;
    /** Max selectable day, ISO `yyyy-mm-dd`. */
    max?: string | null;
    /** Extra disable predicate (receives a local Date). */
    disabledDate?: DisabledDatePredicate | null;
    /** Show a clear (✕) button when there's a value. */
    clearable?: boolean;
    /** Display/parse format. Default Polish `dd.mm.yyyy`. */
    format?: string;
    /** BCP-47 locale for calendar names. Default `pl`. */
    locale?: string;
    /** 0 = Sunday … 6 = Saturday. Default Monday (1). */
    weekStartsOn?: WeekDay;
    /** Standalone wiring (FormField provides these otherwise). */
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    id?: string;
    describedById?: string;
    name?: string;
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    disabled: false,
    readonly: false,
    min: null,
    max: null,
    disabledDate: null,
    clearable: true,
    format: 'dd.mm.yyyy',
    weekStartsOn: 1,
    success: false,
    dirty: false,
  },
);

// ISO day string `yyyy-mm-dd` (or null). Never a Date.
const model = defineModel<string | null>({ default: null });

const { t, currentLocale } = useI18n();
// Effective BCP-47 locale for the calendar: explicit `locale` prop wins, else
// follow the active UI language.
const effectiveLocale = computed(() => props.locale ?? currentLocale.value);

const field = useFormField();
const generatedId = nextId('next-date');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const panelId = computed(() => `${resolvedId.value}-panel`);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const fieldInvalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const fieldSuccess = computed(() => props.success || (field?.valid.value ?? false));
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

// ── Typed text ↔ model ────────────────────────────────────────────────────────
const text = ref('');
// A typed full-but-invalid date (e.g. 31.02.2026 or out-of-range) flags invalid.
const parseError = ref(false);

function syncTextFromModel(): void {
  const d = fromIsoDate(model.value);
  text.value = d ? formatDate(d, props.format) : '';
  parseError.value = false;
}
watch(model, syncTextFromModel, { immediate: true });

const popoverRef = ref<InstanceType<typeof FieldPopover> | null>(null);

function onInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value;
  text.value = maskDateInput(raw, props.format);
  if (!text.value) {
    parseError.value = false;
    if (model.value !== null) model.value = null;
    return;
  }
  const parsed = parseDateInput(text.value, props.format);
  if (parsed) {
    const clamped = clampDate(parsed, fromIsoDate(props.min), fromIsoDate(props.max));
    parseError.value = props.disabledDate?.(clamped) ?? false;
    model.value = toIsoDate(clamped);
  } else {
    // Keep typing; only flag once the field has enough digits to be a full date.
    parseError.value = text.value.replace(/\D/g, '').length >= (props.format.includes('yyyy') ? 8 : 6);
  }
}

function onBlur(): void {
  // Normalize the display back to the canonical model on blur.
  syncTextFromModel();
}

function onCalendarSelect(iso: string): void {
  model.value = iso;
  popoverRef.value?.closePanel(true);
}

function clear(): void {
  model.value = null;
  text.value = '';
  parseError.value = false;
}

function openPanel(): void {
  if (disabled.value || readonly.value) return;
  popoverRef.value?.openPanel();
}

const invalid = computed(() => fieldInvalid.value || parseError.value);
const success = computed(() => fieldSuccess.value && !parseError.value);
const showClear = computed(
  () => props.clearable && !disabled.value && !readonly.value && !!model.value,
);
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('pickers.calendar', 'Calendar')"
  >
    <template #trigger="{ open }">
      <FieldShell
        :size="size"
        :disabled="disabled"
        :readonly="readonly"
        :error="invalid"
        :success="success"
        :dirty="dirty"
        :focused="open || undefined"
      >
        <template #leading>
          <Icon name="calendar" class="text-next-muted-foreground" />
        </template>

        <input
          :id="resolvedId"
          :value="text"
          type="text"
          inputmode="numeric"
          :name="name"
          :placeholder="placeholder ?? format"
          :disabled="disabled"
          :readonly="readonly"
          autocomplete="off"
          role="combobox"
          aria-haspopup="dialog"
          :aria-expanded="open"
          :aria-controls="panelId"
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="resolvedDescribedBy"
          :aria-required="required ? 'true' : undefined"
          :aria-label="ariaLabel"
          class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent pl-next-2 text-current outline-none placeholder:text-next-muted-foreground disabled:cursor-not-allowed"
          :class="FIELD_PADDING_X[size]"
          @input="onInput"
          @blur="onBlur"
          @keydown.down.prevent="openPanel"
          @click="openPanel"
        />

        <template #trailing>
          <span class="flex items-center gap-next-1">
            <!-- Clear box is always reserved when clearable, visibility toggled,
                 so the input width never shifts as the value comes/goes. -->
            <span v-if="clearable" class="flex h-5 w-5 items-center justify-center">
              <button
                type="button"
                class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
                :class="showClear ? '' : 'invisible'"
                :aria-hidden="showClear ? undefined : 'true'"
                tabindex="-1"
                :aria-label="t('pickers.clearDate', 'Clear date')"
                @click.stop="clear"
              >
                <Icon name="x" />
              </button>
            </span>
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg disabled:cursor-not-allowed"
              :disabled="disabled || readonly"
              :aria-label="t('pickers.openCalendar', 'Open calendar')"
              tabindex="-1"
              @click.stop="openPanel"
            >
              <Icon name="calendar" />
            </button>
          </span>
        </template>
      </FieldShell>
    </template>

    <CalendarPanel
      :model-value="model"
      :min="min"
      :max="max"
      :disabled-date="disabledDate"
      :locale="effectiveLocale"
      :week-starts-on="weekStartsOn"
      @select="onCalendarSelect"
    />
  </FieldPopover>
</template>
