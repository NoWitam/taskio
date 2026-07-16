<script setup lang="ts">
// FormSelect — a GLOBAL, reusable Form picker for the "next" frontend.
//
// A thin wrapper over Select that loads forms from the verified backend contract
//   GET /forms?search=<q>&cursor=<cursor> → { data: [{ id, name, icon, … }],
//   meta: { next_cursor } }
// (the same endpoint + `search` param the Forms store uses) and renders each
// option / single value with a leading `file-text` icon (or the form's own icon,
// mapped onto our local Icon set via resolveFormIcon, with a `file-text` fallback
// when the backend icon isn't a valid next IconName) plus the form name. Built-in
// `searchable` drives the `search` query param; cursor pagination + skeletons come
// from Select.
//
// v-model is a single form id (`string | null`). Clearable (Select's trailing ✕).
// An already-attached form (e.g. a task's current form) can be passed through
// `:seed` so its name renders before — or without — an async page that contains
// it. a11y label is provided by the surrounding FormField.
//
// No legacy imports; namespaced tokens; i18n via t() with safe fallbacks.
import { computed, ref, watch } from 'vue';
import Select, {
  type SelectFetchArgs,
  type SelectFetchResult,
  type SelectFetchOptions,
  type SelectOption,
} from './Select.vue';
import Icon from '../primitives/Icon.vue';
import { type IconName } from '../primitives/icons';
import { resolveFormIcon } from './formIcon';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';

const { t } = useI18n();

/** A form option carries its resolved leading icon for the slots. */
interface FormOption extends SelectOption {
  icon: IconName;
}

interface ApiForm {
  id: string | number;
  name: string;
  icon?: string | null;
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    /** Leading icon (defaults to `file-text`; pass `null` to drop it). */
    leadingIcon?: IconName | null;
    disabled?: boolean;
    readonly?: boolean;
    placeholder?: string;
    ariaLabel?: string;
    /** Standalone state lines (provided automatically inside a FormField). */
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    /**
     * Seed an already-known form so its value renders before (or without) an async
     * page that contains it — e.g. a task's currently-attached form.
     */
    seed?: Array<Pick<ApiForm, 'id' | 'name'> & { icon?: string | null }>;
    /**
     * Override the forms loader (defaults to the `/forms` endpoint). Useful for the
     * styleguide gallery / tests, which inject a mock dataset.
     */
    fetchOptions?: SelectFetchOptions;
  }>(),
  {
    disabled: false,
    readonly: false,
  },
);

// Default the leading icon to `file-text`; `:leading-icon="null"` opts out.
const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : props.leadingIcon ?? 'file-text',
);

// v-model: a single form id (string | null). Clearable via Select's ✕.
const model = defineModel<string | null>({ default: null });

function toOption(f: ApiForm): FormOption {
  return {
    value: String(f.id),
    label: f.name,
    icon: resolveFormIcon(f.icon),
  };
}

// Running cache of seen forms (seed + loaded pages) so the value resolves its
// label/icon even when the selected form is off the current async page.
const seededOptions = ref<FormOption[]>(
  (props.seed ?? []).map((f) => toOption(f as ApiForm)),
);

// Merge late-arriving seeds (e.g. the form resolved AFTER mount on edit) so the
// trigger swaps the id fallback for the real name without a reselect.
watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((f) => toOption(f as ApiForm)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

// GET /forms?search=&cursor= → { data, meta: { next_cursor } }.
async function fetchForms(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as FormOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }
  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('search', query);
  const qs = params.toString();
  const res = await api.get<{ data: ApiForm[]; meta: { next_cursor: string | null } }>(
    `/forms${qs ? `?${qs}` : ''}`,
  );
  const options = (res.data ?? []).map(toOption);
  const known = new Set(seededOptions.value.map((o) => o.value));
  seededOptions.value = [
    ...seededOptions.value,
    ...options.filter((o) => !known.has(o.value)),
  ];
  return { options, nextCursor: res.meta?.next_cursor ?? null };
}

const placeholderText = computed(
  () => props.placeholder ?? t('formSelect.placeholder', 'Select a form'),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('formSelect.ariaLabel', 'Select a form'),
);

defineExpose({ fetchForms });
</script>

<template>
  <Select
    v-model="model"
    searchable
    :size="size"
    :leading-icon="resolvedLeadingIcon"
    :disabled="disabled"
    :readonly="readonly"
    :aria-invalid="ariaInvalid"
    :success="success"
    :dirty="dirty"
    :fetch-options="fetchForms"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('formSelect.search', 'Search forms…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Icon :name="(option as any).icon ?? 'file-text'" class="shrink-0 text-next-muted-foreground" />
      <span class="min-w-0 flex-1 truncate text-next-sm">{{ option.label }}</span>
    </template>

    <template #value="{ option }">
      <Icon :name="(option as any).icon ?? 'file-text'" class="shrink-0 text-next-muted-foreground" />
      <span class="truncate">{{ option.label }}</span>
    </template>
  </Select>
</template>
