<script setup lang="ts">
// TemplateSelect — a GLOBAL, reusable Generator-TEMPLATE picker for the "next" frontend.
//
// A thin wrapper over Select that loads templates from the verified backend contract
//   GET /generator/templates?search=<q>&cursor=<cursor> → { data: [{ id, name,
//   content_type, … }], meta: { next_cursor } }
// (the same endpoint + `search` param the Templates store uses) and renders each option /
// single value with a leading glyph derived from the template's `content_type` plus the
// template name. Built-in `searchable` drives the `search` query param; cursor pagination
// + skeletons come from Select.
//
// v-model is a single template id (`string | null`). Clearable (Select's trailing ✕).
// An already-chosen template (e.g. a saved workflow step's) can be passed through `:seed`
// so its name renders before — or without — an async page that contains it. a11y label is
// provided by the surrounding FormField.
//
// ATTACH EXISTING only: templates are authored in the Generator module, not inline here.
// NOTE the `ui/** → pages/**` import ban: the content-type → icon map is a LOCAL copy of
// the same three ids `pages/generator/templateMeta.ts` maps (a design-system component may
// not reach into a page). It is presentation-only — an unknown/new id falls back to the
// generic file glyph, exactly like the page-side map.
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
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';

const { t } = useI18n();

/**
 * The per-content-type glyph. LOCAL by the `ui/** → pages/**` import rule (see the header);
 * the fallback keeps a future/unknown content type rendering rather than crashing.
 */
const CONTENT_TYPE_ICONS: Record<string, IconName> = {
  post: 'file-text',
  post_with_image: 'image',
  video_script: 'film',
};

function resolveTemplateIcon(contentType?: string | null): IconName {
  return (contentType && CONTENT_TYPE_ICONS[contentType]) || 'file-text';
}

/** A template option carries its resolved leading icon for the slots. */
interface TemplateOption extends SelectOption {
  icon: IconName;
}

interface ApiTemplate {
  id: string | number;
  name: string;
  content_type?: string | null;
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    /** Leading icon (defaults to `sparkles`; pass `null` to drop it). */
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
     * Seed an already-known template so its value renders before (or without) an async
     * page that contains it — e.g. a saved workflow step's chosen template.
     */
    seed?: Array<Pick<ApiTemplate, 'id' | 'name'> & { content_type?: string | null }>;
    /**
     * Override the templates loader (defaults to the `/generator/templates` endpoint).
     * Useful for the styleguide gallery / tests, which inject a mock dataset.
     */
    fetchOptions?: SelectFetchOptions;
  }>(),
  {
    disabled: false,
    readonly: false,
  },
);

// Default the leading icon to `sparkles` (the Generator module's glyph); `:leading-icon="null"` opts out.
const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : props.leadingIcon ?? 'sparkles',
);

// v-model: a single template id (string | null). Clearable via Select's ✕.
const model = defineModel<string | null>({ default: null });

function toOption(tpl: ApiTemplate): TemplateOption {
  return {
    value: String(tpl.id),
    label: tpl.name,
    icon: resolveTemplateIcon(tpl.content_type),
  };
}

// Running cache of seen templates (seed + loaded pages) so the value resolves its
// label/icon even when the selected template is off the current async page.
const seededOptions = ref<TemplateOption[]>(
  (props.seed ?? []).map((tpl) => toOption(tpl as ApiTemplate)),
);

// Merge late-arriving seeds (e.g. the template resolved AFTER mount on edit) so the
// trigger swaps the id fallback for the real name without a reselect.
watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((tpl) => toOption(tpl as ApiTemplate)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

// GET /generator/templates?search=&cursor= → { data, meta: { next_cursor } }.
async function fetchTemplates(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as TemplateOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }
  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('search', query);
  const qs = params.toString();
  const res = await api.get<{ data: ApiTemplate[]; meta: { next_cursor: string | null } }>(
    `/generator/templates${qs ? `?${qs}` : ''}`,
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
  () => props.placeholder ?? t('templateSelect.placeholder', 'Select a template'),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('templateSelect.ariaLabel', 'Select a template'),
);

defineExpose({ fetchTemplates });
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
    :fetch-options="fetchTemplates"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('templateSelect.search', 'Search templates…')"
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
