<script setup lang="ts">
// BotSelect — a GLOBAL, reusable Bot (AI Character) picker for the "next" frontend.
//
// A thin wrapper over Select that loads bots from the verified backend contract
//   GET /bots?search=<q>&cursor=<cursor> → { data: [{ id, name, status, … }],
//   meta: { next_cursor } }
// (the SAME endpoint + `search` param the Bots store uses) and renders each
// option / chip / single value with a leading `sparkles` icon (the bot identity
// glyph, distinct from a user Avatar) plus the bot name and — in the option rows
// — a small localized status label.
//
// v-model is a single bot id (`string | null`) by default, or `string[]` when
// `:multiple` (e.g. the Tasks `bot_id[]` filter). Already-selected bots that may
// be off the current async page are preserved as chips/value via Select's
// `selectedOptions` label cache: pass any known bots through `:seed` (or they get
// cached as they load).
//
// Mirrors UserSelect / FormSelect 1:1 for a11y/loading/empty (the combobox ARIA,
// cursor pagination + skeletons come from Select). No legacy imports; namespaced
// tokens only; i18n via t() with safe fallbacks.
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
import type { BotStatus } from '../../pages/bots/types';

const { t } = useI18n();

/** A bot option carries its status so the option rows can label it. */
interface BotOption extends SelectOption {
  status: BotStatus | null;
}

interface ApiBot {
  id: string | number;
  name: string;
  status?: BotStatus | null;
}

const props = withDefaults(
  defineProps<{
    /** Select multiple bots (v-model becomes string[]). */
    multiple?: boolean;
    /** Multi trigger display: 'chips' (default) or 'summary'. Mirrors Select. */
    display?: 'chips' | 'summary';
    summary?: boolean;
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
     * Seed already-known bots so their chips/value render before (or without) an
     * async page that contains them — e.g. a task's current bot assignee.
     */
    seed?: Array<Pick<ApiBot, 'id' | 'name'> & { status?: BotStatus | null }>;
    /**
     * Override the bots loader (defaults to the `/bots` endpoint). Useful for the
     * styleguide gallery / tests, which inject a mock dataset.
     */
    fetchOptions?: SelectFetchOptions;
  }>(),
  {
    multiple: false,
    summary: false,
    disabled: false,
    readonly: false,
  },
);

// Default the leading icon to `sparkles`; `:leading-icon="null"` opts out.
const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : props.leadingIcon ?? 'sparkles',
);

// v-model: single id OR string[] in multiple mode (mirrors Select's two models).
const single = defineModel<string | null>({ default: null });
const multi = defineModel<string[]>('values', { default: () => [] });

function toOption(b: ApiBot): BotOption {
  return {
    value: String(b.id),
    label: b.name,
    status: b.status ?? null,
  };
}

// Running label cache of every bot we have seen (seed + loaded pages), so the
// slots can resolve status for already-selected, possibly off-page values.
const seededOptions = ref<BotOption[]>(
  (props.seed ?? []).map((b) => toOption(b as ApiBot)),
);

// Merge late-arriving seeds (e.g. ids resolved AFTER mount, on a refresh) so
// chips/trigger swap the id fallback for the real name without a reselect.
watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((b) => toOption(b as ApiBot)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

// Surface the RESOLVED selected options (id → {value,label,…}) so a consumer can
// render per-value chips elsewhere (e.g. a FilterBar) showing actual names, not a
// count. Unknown ids (not yet loaded/seeded) fall back to the id as the label.
const emit = defineEmits<{
  (e: 'update:selected', options: BotOption[]): void;
}>();
const selectedResolved = computed<BotOption[]>(() => {
  const byId = new Map(seededOptions.value.map((o) => [o.value, o]));
  const ids = props.multiple
    ? multi.value ?? []
    : single.value != null
      ? [single.value]
      : [];
  return ids.map(
    (id) =>
      byId.get(String(id)) ?? {
        value: String(id),
        label: String(id),
        status: null,
      },
  );
});
watch(selectedResolved, (v) => emit('update:selected', v), {
  deep: true,
  immediate: true,
});

// A localized status label for the option rows (falls back to the raw key).
function statusLabel(status: BotStatus | null): string {
  if (!status) return '';
  return t(`bots.statuses.${status}`, status);
}

// GET /bots?search=&cursor= → { data, meta: { next_cursor } }.
async function fetchBots(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    // Keep the chip/value cache fed from the injected loader too.
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as BotOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }
  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('search', query);
  const qs = params.toString();
  const res = await api.get<{ data: ApiBot[]; meta: { next_cursor: string | null } }>(
    `/bots${qs ? `?${qs}` : ''}`,
  );
  const options = (res.data ?? []).map(toOption);
  // Keep the cache fresh so chips/value can read the status later.
  const known = new Set(seededOptions.value.map((o) => o.value));
  seededOptions.value = [
    ...seededOptions.value,
    ...options.filter((o) => !known.has(o.value)),
  ];
  return { options, nextCursor: res.meta?.next_cursor ?? null };
}

const placeholderText = computed(
  () =>
    props.placeholder ??
    (props.multiple
      ? t('botSelect.placeholderMultiple', 'Select bots')
      : t('botSelect.placeholder', 'Select a bot')),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('botSelect.ariaLabel', 'Select bots'),
);

defineExpose({ fetchBots });
</script>

<template>
  <!-- Multiple: bind the array model; single: bind the scalar model. -->
  <Select
    v-if="multiple"
    v-model:values="multi"
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
    :fetch-options="fetchBots"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('botSelect.search', 'Search bots…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Icon name="sparkles" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-next-sm">{{ option.label }}</span>
        <span
          v-if="statusLabel((option as any).status)"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ statusLabel((option as any).status) }}
        </span>
      </span>
    </template>

    <template #chip="{ option, remove }">
      <span
        class="next-badge inline-flex h-6 min-w-0 items-center gap-next-1 rounded-next-full bg-next-muted py-0 pl-next-1_5 pr-next-1_5 font-next-medium text-next-fg"
      >
        <Icon name="sparkles" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <span class="truncate text-next-xs">{{ option.label }}</span>
        <button
          type="button"
          class="-mr-next-0_5 inline-flex shrink-0 items-center justify-center rounded-next-full p-[1px] text-next-muted-foreground transition-colors duration-[var(--duration-next-fast)] hover:bg-next-fg/15 hover:text-next-fg"
          :aria-label="t('select.removeItem', 'Remove {label}', { label: option.label })"
          @click.stop="remove"
        >
          <span class="text-[0.85em] leading-none" aria-hidden="true">✕</span>
        </button>
      </span>
    </template>
  </Select>

  <Select
    v-else
    v-model="single"
    searchable
    :size="size"
    :leading-icon="resolvedLeadingIcon"
    :disabled="disabled"
    :readonly="readonly"
    :aria-invalid="ariaInvalid"
    :success="success"
    :dirty="dirty"
    :fetch-options="fetchBots"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('botSelect.search', 'Search bots…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Icon name="sparkles" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-next-sm">{{ option.label }}</span>
        <span
          v-if="statusLabel((option as any).status)"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ statusLabel((option as any).status) }}
        </span>
      </span>
    </template>

    <template #value="{ option }">
      <Icon name="sparkles" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <span class="truncate">{{ option.label }}</span>
    </template>
  </Select>
</template>
