<script setup lang="ts">
// LabelSelect — a GLOBAL, reusable label picker for the "next" frontend.
//
// A wrapper over Select that loads labels from the verified backend contract
//   GET /labels?cursor=&search= → { data: [{ id, name, color, icon }],
//   meta: { next_cursor } }
// and renders each option / chip as a COLORED LABEL PILL: the label's `color`
// (user-chosen DATA → the allowed inline-color exception, like ColorInput) as a
// subtle tint + accent, plus the label's `icon` mapped onto our local Icon set
// (resolveLabelIcon, with a generic `tag` fallback) and the name. Removable chips.
//
// The AND/OR operator toggle lives INSIDE the dropdown: a SegmentedControl in the
// Select #header slot (All = AND, Any = OR) with a short helper line, ALWAYS shown
// whenever the consumer binds v-model:operator (regardless of how many labels are
// selected), wired to v-model:operator. There is intentionally NO external
// operator control.
//
// v-model is `string[]` (label ids). v-model:operator is 'AND' | 'OR'. Built-in
// `searchable` drives the `search` query param; cursor pagination + skeletons
// come from Select. i18n + a11y throughout; no legacy imports; namespaced tokens.
import { computed, reactive, ref, watch } from 'vue';
import Select, {
  type SelectFetchArgs,
  type SelectFetchResult,
  type SelectFetchOptions,
  type SelectOption,
} from './Select.vue';
import SegmentedControl, { type SegmentOption } from './SegmentedControl.vue';
import Icon from '../primitives/Icon.vue';
import { type IconName } from '../primitives/icons';
import Modal from '../overlay/Modal.vue';
import FormField from './FormField.vue';
import TextInput from './TextInput.vue';
import ColorInput from './ColorInput.vue';
import IconInput from './IconInput.vue';
import Button from '../primitives/Button.vue';
import { resolveLabelIcon } from './labelIcon';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';

const { t } = useI18n();

/** A label option carries the resolved color + mapped icon for the slots. */
interface LabelOption extends SelectOption {
  color: string | null;
}

interface ApiLabel {
  id: string | number;
  name: string;
  color?: string | null;
  icon?: string | null;
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    /** Leading icon (defaults to `tag`; pass `null` to drop it). */
    leadingIcon?: IconName | null;
    disabled?: boolean;
    readonly?: boolean;
    placeholder?: string;
    ariaLabel?: string;
    /** Standalone state lines (provided automatically inside a FormField). */
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    /** Multi trigger display: 'chips' (default) or 'summary'. */
    display?: 'chips' | 'summary';
    summary?: boolean;
    /** Show a "New label" button in the dropdown that opens a create dialog. */
    addable?: boolean;
    /**
     * Seed already-known labels so their chips render before (or without) an async
     * page that contains them — e.g. a task's current labels.
     */
    seed?: Array<Pick<ApiLabel, 'id' | 'name'> & { color?: string | null; icon?: string | null }>;
    /**
     * Override the labels loader (defaults to the `/labels` endpoint). Useful for
     * the styleguide gallery / tests, which inject a mock dataset. The returned
     * SelectOptions may carry `color` + a resolved `icon` for the slots.
     */
    fetchOptions?: SelectFetchOptions;
  }>(),
  {
    disabled: false,
    readonly: false,
    summary: false,
    addable: true,
  },
);

// Default the leading icon to `tag`; `:leading-icon="null"` opts out explicitly.
const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : props.leadingIcon ?? 'tag',
);

// v-model: label ids. operator is filter-only and OPTIONAL — when no `operator`
// model is bound, the in-dropdown toggle simply does not render.
const model = defineModel<string[]>({ default: () => [] });
const operator = defineModel<'AND' | 'OR' | undefined>('operator', {
  default: undefined,
});

function toOption(l: ApiLabel): LabelOption {
  return {
    value: String(l.id),
    label: l.name,
    color: l.color ?? null,
    icon: resolveLabelIcon(l.icon),
  };
}

// Running cache of seen labels (seed + loaded pages) so chips can resolve their
// color/icon even when the selected label is off the current async page.
const seededOptions = ref<LabelOption[]>(
  (props.seed ?? []).map((l) => toOption(l as ApiLabel)),
);

// Merge late-arriving seeds (e.g. ids resolved by name AFTER mount, on a refresh).
watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((l) => toOption(l as ApiLabel)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

// Surface the RESOLVED selected labels (id → {value,label,color,icon}) so a
// consumer (e.g. a FilterBar) can render a chip per label with its real name.
const emit = defineEmits<{
  (e: 'update:selected', options: LabelOption[]): void;
}>();
const selectedResolved = computed<LabelOption[]>(() => {
  const byId = new Map(seededOptions.value.map((o) => [o.value, o]));
  return model.value.map(
    (id) =>
      byId.get(String(id)) ?? {
        value: String(id),
        label: String(id),
        color: null,
      },
  );
});
watch(selectedResolved, (v) => emit('update:selected', v), {
  deep: true,
  immediate: true,
});

// GET /labels?cursor=&search= → { data, meta: { next_cursor } }.
async function fetchLabels(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as LabelOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }
  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('search', query);
  const res = await api.get<{ data: ApiLabel[]; meta: { next_cursor: string | null } }>(
    `/labels?${params.toString()}`,
  );
  const options = (res.data ?? []).map(toOption);
  const known = new Set(seededOptions.value.map((o) => o.value));
  seededOptions.value = [
    ...seededOptions.value,
    ...options.filter((o) => !known.has(o.value)),
  ];
  return { options, nextCursor: res.meta?.next_cursor ?? null };
}

// --- Operator toggle (inside the dropdown) --------------------------------
// Shown whenever the consumer binds v-model:operator. The combination semantics
// only bite with ≥2 labels, but the control stays visible so the chosen mode is
// always discoverable (and never pops in/out as the selection count crosses 2).
const operatorBound = computed(() => operator.value !== undefined);
const showOperator = operatorBound;

const operatorOptions = computed<SegmentOption<'AND' | 'OR'>[]>(() => [
  { value: 'AND', label: t('labelSelect.operatorAll', 'All') },
  { value: 'OR', label: t('labelSelect.operatorAny', 'Any') },
]);

const operatorHelp = computed(() =>
  operator.value === 'AND'
    ? t('labelSelect.operatorAllHelp', 'Match tasks that have all selected labels.')
    : t('labelSelect.operatorAnyHelp', 'Match tasks with at least one selected label.'),
);

function onOperator(value: 'AND' | 'OR'): void {
  operator.value = value;
}

// --- Colored-pill styling (label color is DATA → inline-color exception) ---
// A subtle tint background derived from the label color, with the color itself as
// the border + icon accent and a readable foreground. color-mix keeps it legible
// in both light and dark without computing a contrast color per label.
function pillStyle(color: string | null | undefined): Record<string, string> | undefined {
  if (!color) return undefined;
  return {
    backgroundColor: `color-mix(in srgb, ${color} 14%, transparent)`,
    borderColor: `color-mix(in srgb, ${color} 45%, transparent)`,
    color: `color-mix(in srgb, ${color} 78%, var(--color-next-fg))`,
  };
}

const placeholderText = computed(
  () => props.placeholder ?? t('labelSelect.placeholder', 'Select labels'),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('labelSelect.ariaLabel', 'Select labels'),
);

// --- Create-label dialog --------------------------------------------------
// Opening the dialog mirrors the legacy LabelSelect: a small form (name + color
// + icon) that POSTs to /labels, then seeds + auto-selects the new label so its
// chip renders immediately (the dropdown refetches its list on the next open).
const createOpen = ref(false);
const createSubmitting = ref(false);
const createTouched = ref(false);
const createError = ref<string | null>(null);
const createForm = reactive<{ name: string; color: string | null; icon: IconName | null }>({
  name: '',
  color: null,
  icon: null,
});

const createNameError = computed(() =>
  createTouched.value && !createForm.name.trim()
    ? t('labelSelect.nameRequired', 'Label name is required.')
    : undefined,
);

function openCreate(): void {
  createForm.name = '';
  createForm.color = null;
  createForm.icon = null;
  createTouched.value = false;
  createError.value = null;
  createOpen.value = true;
}

async function submitCreate(): Promise<void> {
  createTouched.value = true;
  createError.value = null;
  if (!createForm.name.trim()) {
    return;
  }
  createSubmitting.value = true;
  try {
    const res = await api.post<{ data: ApiLabel }>('/labels', {
      name: createForm.name.trim(),
      color: createForm.color,
      icon: createForm.icon,
    });
    const created = res.data;
    const option = toOption(created);
    const known = new Set(seededOptions.value.map((o) => o.value));
    if (!known.has(option.value)) {
      seededOptions.value = [...seededOptions.value, option];
    }
    if (!model.value.includes(option.value)) {
      model.value = [...model.value, option.value];
    }
    createOpen.value = false;
  } catch (err: unknown) {
    const message =
      (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? null;
    createError.value = message ?? t('labelSelect.createError', 'Could not create the label.');
  } finally {
    createSubmitting.value = false;
  }
}

defineExpose({ fetchLabels });
</script>

<template>
  <Select
    v-model:values="model"
    multiple
    searchable
    :display="display"
    :summary="summary"
    :size="size"
    :leading-icon="resolvedLeadingIcon"
    :disabled="disabled"
    :readonly="readonly"
    :aria-invalid="ariaInvalid"
    :success="success"
    :dirty="dirty"
    :fetch-options="fetchLabels"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('labelSelect.search', 'Search labels…')"
    :aria-label="ariaLabelText"
  >
    <!-- AND/OR operator (when bound) + the "New label" action live INSIDE the
         dropdown header, shown whenever the consumer binds operator or addable. -->
    <template v-if="operatorBound || addable" #header>
      <div class="flex flex-col gap-next-2">
        <div v-if="showOperator" class="flex flex-col gap-next-1">
          <SegmentedControl
            size="sm"
            equal-width
            :model-value="operator ?? 'OR'"
            :options="operatorOptions"
            :aria-label="t('labelSelect.operatorLabel', 'Match labels')"
            @update:model-value="(v) => onOperator(v as 'AND' | 'OR')"
          />
          <span class="text-next-xs leading-snug text-next-muted-foreground">
            {{ operatorHelp }}
          </span>
        </div>
        <Button
          v-if="addable"
          type="button"
          variant="secondary"
          size="sm"
          leading-icon="plus"
          class="w-full"
          @click="openCreate"
        >
          {{ t('labelSelect.create', 'New label') }}
        </Button>
      </div>
    </template>

    <!-- Option row: colored label pill (color tint + mapped icon + name). -->
    <template #option="{ option }">
      <span
        class="inline-flex min-w-0 items-center gap-next-1 rounded-next-full border px-next-2 py-next-0_5 text-next-xs font-next-medium"
        :class="(option as any).color ? '' : 'border-next-border bg-next-muted text-next-muted-foreground'"
        :style="pillStyle((option as any).color)"
      >
        <Icon v-if="option.icon" :name="option.icon" class="shrink-0" />
        <span class="truncate">{{ option.label }}</span>
      </span>
    </template>

    <!-- Selected chip: same colored pill + a removable ✕. -->
    <template #chip="{ option, remove }">
      <span
        class="inline-flex h-6 min-w-0 items-center gap-next-1 rounded-next-full border px-next-2 text-next-xs font-next-medium"
        :class="(option as any).color ? '' : 'border-next-border bg-next-muted text-next-fg'"
        :style="pillStyle((option as any).color)"
      >
        <Icon v-if="option.icon" :name="option.icon" class="shrink-0" />
        <span class="truncate">{{ option.label }}</span>
        <button
          type="button"
          class="-mr-next-0_5 inline-flex shrink-0 items-center justify-center rounded-next-full p-[1px] transition-colors duration-[var(--duration-next-fast)] hover:bg-next-fg/15"
          :aria-label="t('select.removeItem', 'Remove {label}', { label: option.label })"
          @click.stop="remove"
        >
          <span class="text-[0.85em] leading-none" aria-hidden="true">✕</span>
        </button>
      </span>
    </template>
  </Select>

  <!-- Create-label dialog (only mounted/used when addable). -->
  <Modal
    v-if="addable"
    v-model:open="createOpen"
    size="sm"
    :aria-label="t('labelSelect.createTitle', 'Create label')"
  >
    <template #title>{{ t('labelSelect.createTitle', 'Create label') }}</template>

    <form class="flex flex-col gap-next-4" @submit.prevent="submitCreate">
      <FormField :label="t('labelSelect.nameLabel', 'Name')" required :error="createNameError">
        <TextInput
          v-model="createForm.name"
          :placeholder="t('labelSelect.namePlaceholder', 'Enter a name…')"
          :aria-label="t('labelSelect.nameLabel', 'Name')"
        />
      </FormField>

      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
        <FormField :label="t('labelSelect.colorLabel', 'Color (optional)')">
          <ColorInput v-model="createForm.color" :aria-label="t('labelSelect.colorLabel', 'Color (optional)')" />
        </FormField>
        <FormField :label="t('labelSelect.iconLabel', 'Icon (optional)')">
          <IconInput v-model="createForm.icon" :aria-label="t('labelSelect.iconLabel', 'Icon (optional)')" />
        </FormField>
      </div>

      <p v-if="createError" class="text-next-sm text-next-danger" role="alert">{{ createError }}</p>
    </form>

    <template #footer="{ close }">
      <Button type="button" variant="ghost" :disabled="createSubmitting" @click="close">
        {{ t('labelSelect.cancel', 'Cancel') }}
      </Button>
      <Button type="button" variant="primary" :loading="createSubmitting" @click="submitCreate">
        {{ t('labelSelect.submit', 'Create') }}
      </Button>
    </template>
  </Modal>
</template>
