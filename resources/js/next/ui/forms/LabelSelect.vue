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
import { computed, ref } from 'vue';
import Select, {
  type SelectFetchArgs,
  type SelectFetchResult,
  type SelectFetchOptions,
  type SelectOption,
} from './Select.vue';
import SegmentedControl, { type SegmentOption } from './SegmentedControl.vue';
import Icon from '../primitives/Icon.vue';
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
    size: 'md',
    disabled: false,
    readonly: false,
    summary: false,
  },
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
const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

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
    <!-- AND/OR operator lives INSIDE the dropdown; shown whenever the consumer
         binds v-model:operator (regardless of how many labels are selected). -->
    <template v-if="operatorBound" #header>
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
</template>
