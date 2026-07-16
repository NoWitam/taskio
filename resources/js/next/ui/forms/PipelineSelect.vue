<script setup lang="ts">
// PipelineSelect — a GLOBAL, reusable Approval-pipeline picker for the "next"
// frontend.
//
// A thin wrapper over Select that loads approval pipelines from the verified
// backend contract
//   GET /approval-pipelines?search=<q>&cursor=<cursor> → { data: [{ id, name,
//   icon, … }], meta: { next_cursor } }
// (the same endpoint + `search` param the Pipelines store uses) and renders each
// option / single value with a leading `git-branch` icon (or the pipeline's own
// icon, mapped onto our local Icon set via resolvePipelineIcon, with a
// `git-branch` fallback when the backend icon isn't a valid next IconName) plus
// the pipeline name. Built-in `searchable` drives the `search` query param; cursor
// pagination + skeletons come from Select.
//
// v-model is a single pipeline id (`string | null`). Clearable (Select's trailing
// ✕ → detach). An already-attached pipeline (e.g. a task's current pipeline) can
// be passed through `:seed` so its name renders before — or without — an async
// page that contains it. a11y label is provided by the surrounding FormField.
//
// ATTACH EXISTING only: pipelines are created in the Approvals module, not inline
// here. No legacy imports; namespaced tokens; i18n via t() with safe fallbacks.
import { computed, ref, watch } from 'vue';
import Select, {
  type SelectFetchArgs,
  type SelectFetchResult,
  type SelectFetchOptions,
  type SelectOption,
} from './Select.vue';
import Icon from '../primitives/Icon.vue';
import { type IconName } from '../primitives/icons';
import { resolvePipelineIcon } from './pipelineIcon';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';

const { t } = useI18n();

/** A pipeline option carries its resolved leading icon for the slots. */
interface PipelineOption extends SelectOption {
  icon: IconName;
}

interface ApiPipeline {
  id: string | number;
  name: string;
  icon?: string | null;
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    /** Leading icon (defaults to `git-branch`; pass `null` to drop it). */
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
     * Seed an already-known pipeline so its value renders before (or without) an
     * async page that contains it — e.g. a task's currently-attached pipeline.
     */
    seed?: Array<Pick<ApiPipeline, 'id' | 'name'> & { icon?: string | null }>;
    /**
     * Override the pipelines loader (defaults to the `/approval-pipelines`
     * endpoint). Useful for the styleguide gallery / tests, which inject a mock
     * dataset.
     */
    fetchOptions?: SelectFetchOptions;
  }>(),
  {
    disabled: false,
    readonly: false,
  },
);

// Default the leading icon to `git-branch`; `:leading-icon="null"` opts out.
const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : props.leadingIcon ?? 'git-branch',
);

// v-model: a single pipeline id (string | null). Clearable via Select's ✕.
const model = defineModel<string | null>({ default: null });

function toOption(p: ApiPipeline): PipelineOption {
  return {
    value: String(p.id),
    label: p.name,
    icon: resolvePipelineIcon(p.icon),
  };
}

// Running cache of seen pipelines (seed + loaded pages) so the value resolves its
// label/icon even when the selected pipeline is off the current async page.
const seededOptions = ref<PipelineOption[]>(
  (props.seed ?? []).map((p) => toOption(p as ApiPipeline)),
);

// Merge late-arriving seeds (e.g. the pipeline resolved AFTER mount on edit) so the
// trigger swaps the id fallback for the real name without a reselect.
watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((p) => toOption(p as ApiPipeline)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

// GET /approval-pipelines?search=&cursor= → { data, meta: { next_cursor } }.
async function fetchPipelines(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as PipelineOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }
  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('search', query);
  const qs = params.toString();
  const res = await api.get<{ data: ApiPipeline[]; meta: { next_cursor: string | null } }>(
    `/approval-pipelines${qs ? `?${qs}` : ''}`,
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
  () => props.placeholder ?? t('pipelineSelect.placeholder', 'Select a pipeline'),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('pipelineSelect.ariaLabel', 'Select a pipeline'),
);

defineExpose({ fetchPipelines });
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
    :fetch-options="fetchPipelines"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('pipelineSelect.search', 'Search pipelines…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Icon :name="(option as any).icon ?? 'git-branch'" class="shrink-0 text-next-muted-foreground" />
      <span class="min-w-0 flex-1 truncate text-next-sm">{{ option.label }}</span>
    </template>

    <template #value="{ option }">
      <Icon :name="(option as any).icon ?? 'git-branch'" class="shrink-0 text-next-muted-foreground" />
      <span class="truncate">{{ option.label }}</span>
    </template>
  </Select>
</template>
