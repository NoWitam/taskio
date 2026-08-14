<script setup lang="ts">
// KnowledgeBaseSelect — a GLOBAL, reusable KNOWLEDGE BASE picker for the "next" frontend.
//
// A thin wrapper over Select that loads bases from the verified backend contract
//   GET /knowledge/bases?search=<q>&cursor=<cursor>
//     → { data: [{ id, name, description, entries_count, … }], meta: { next_cursor } }
// and renders each option with the `book-open` glyph, the base's name, and its ENTRY COUNT — the
// one number that tells a picker whether the base they are about to bind has anything in it.
//
// Mirrors `BotSelect.vue` 1:1 (async cursor pages, label cache for an off-page selection, search,
// a11y from Select). SINGLE-select only: every consumer so far binds exactly one base, and a
// multi-select would have to invent a meaning for "a bot reads two bases" that the backend does
// not have (`KnowledgeBinding` is one row per bot).
//
// It talks to `api` DIRECTLY rather than through `stores/knowledge`, and that is deliberate: the
// knowledge store's list state is what the Bases SCREEN renders, so a picker mounted over that
// screen (the migration modal) would otherwise replace the list the user is looking at with its
// own paged results. Same reasoning as `botDirectory` vs. `bots`.
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

/** A base option carries the two facts the row shows beyond the name. */
interface BaseOption extends SelectOption {
  entriesCount: number | null;
  description: string | null;
}

interface ApiBase {
  id: string | number;
  name: string;
  description?: string | null;
  entries_count?: number | null;
}

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    /** Leading icon (defaults to `book-open`; pass `null` to drop it). */
    leadingIcon?: IconName | null;
    disabled?: boolean;
    readonly?: boolean;
    placeholder?: string;
    ariaLabel?: string;
    ariaInvalid?: boolean;
    success?: boolean;
    dirty?: boolean;
    /**
     * Seed already-known bases so the selected value renders before (or without) an async page
     * that contains it — e.g. a bot already bound to a base far down the list.
     */
    seed?: Array<Pick<ApiBase, 'id' | 'name'> & { entries_count?: number | null }>;
    /** Override the loader (styleguide / tests inject a mock dataset). */
    fetchOptions?: SelectFetchOptions;
  }>(),
  {
    // Absence must stay `undefined` (no Boolean cast to `false`) so it defers
    // to the surrounding FormField — see `formField.ts`.
    ariaInvalid: undefined,
    disabled: false,
    readonly: false,
  },
);

const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : (props.leadingIcon ?? 'book-open'),
);

const selected = defineModel<string | null>({ default: null });

function toOption(base: ApiBase): BaseOption {
  return {
    value: String(base.id),
    label: base.name,
    entriesCount: base.entries_count ?? null,
    description: base.description ?? null,
  };
}

/** Every base we have seen (seed + loaded pages) — lets the trigger name an off-page selection. */
const seededOptions = ref<BaseOption[]>((props.seed ?? []).map((b) => toOption(b as ApiBase)));

watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((b) => toOption(b as ApiBase)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

const emit = defineEmits<{
  /** The RESOLVED selection, so a host can render the name / entry count without a second lookup. */
  (e: 'update:selected', option: BaseOption | null): void;
}>();

const selectedResolved = computed<BaseOption | null>(() => {
  if (selected.value == null) return null;
  const id = String(selected.value);
  return (
    seededOptions.value.find((o) => o.value === id) ?? {
      value: id,
      label: id,
      entriesCount: null,
      description: null,
    }
  );
});
watch(selectedResolved, (v) => emit('update:selected', v), { deep: true, immediate: true });

/** GET /knowledge/bases?search=&cursor= → { data, meta: { next_cursor } }. NO `total` by contract. */
async function fetchBases(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as BaseOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }

  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('search', query);
  const qs = params.toString();

  const res = await api.get<{ data: ApiBase[]; meta?: { next_cursor?: string | null } }>(
    `/knowledge/bases${qs ? `?${qs}` : ''}`,
  );
  const options = (res.data ?? []).map(toOption);

  const known = new Set(seededOptions.value.map((o) => o.value));
  seededOptions.value = [...seededOptions.value, ...options.filter((o) => !known.has(o.value))];

  return { options, nextCursor: res.meta?.next_cursor ?? null };
}

const placeholderText = computed(
  () => props.placeholder ?? t('knowledgeBaseSelect.placeholder', 'Select a knowledge base'),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('knowledgeBaseSelect.ariaLabel', 'Select a knowledge base'),
);

defineExpose({ fetchBases });
</script>

<template>
  <Select
    v-model="selected"
    searchable
    :size="size"
    :leading-icon="resolvedLeadingIcon"
    :disabled="disabled"
    :readonly="readonly"
    :aria-invalid="ariaInvalid"
    :success="success"
    :dirty="dirty"
    :fetch-options="fetchBases"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('knowledgeBaseSelect.search', 'Search knowledge bases…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Icon name="book-open" class="mt-px shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-next-sm">{{ option.label }}</span>
        <span
          v-if="(option as BaseOption).entriesCount != null"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ t('knowledgeBaseSelect.entries', 'Entries: {count}', { count: (option as BaseOption).entriesCount as number }) }}
        </span>
      </span>
    </template>
  </Select>
</template>
