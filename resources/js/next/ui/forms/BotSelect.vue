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
//
// OPT-IN extras (all default to today's rendering, so existing consumers are untouched):
//   • `statusBadge` — option rows show a StatusBadge instead of the muted status line.
//   • `#empty`      — forwarded to Select's empty state ({ query, setQuery, refetch }),
//                     so a consumer can distinguish "no bots at all" from "no matches".
//   • `#value`      — override the selected-value display on the trigger.
import { computed, ref, watch } from 'vue';
import Select, {
  type SelectFetchArgs,
  type SelectFetchResult,
  type SelectFetchOptions,
  type SelectOption,
} from './Select.vue';
import Icon from '../primitives/Icon.vue';
import { type IconName } from '../primitives/icons';
import StatusBadge from '../data/StatusBadge.vue';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';
// The SHARED bot status enum + presentation (icon + tone + localized label) — the same
// module the Bots card/detail render from, so a bot's status never looks different here.
// It lives in `ui/data/` precisely so this picker needs no import from `pages/**`.
import { botStatusMap, type BotStatus } from '../data/botStatus';

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
    /**
     * Render each option's status as a `StatusBadge` (icon + tone + label) instead of
     * the quiet muted text line. OFF by default, so every existing consumer keeps the
     * exact rendering it has today. Turn it on where the status CHANGES the meaning of
     * the pick (e.g. the ai-text author, which may be an inactive bot).
     */
    statusBadge?: boolean;
    /**
     * Offer ONLY bots that can execute tasks (active + the task-execution module on) by
     * sending `can_execute_tasks=1` to `/bots`. OFF by default — turn it on wherever the
     * pick makes a bot RUN something (a task assignee), because assigning any other bot
     * is a silent no-op server-side. Ignored when a custom `fetchOptions` is injected.
     */
    executableOnly?: boolean;
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
    // Absence must stay `undefined` (no Boolean cast to `false`) so it defers
    // to the surrounding FormField — see `formField.ts`.
    ariaInvalid: undefined,
    multiple: false,
    summary: false,
    disabled: false,
    readonly: false,
    statusBadge: false,
    executableOnly: false,
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

// Reactive to the active locale, like every other consumer of the shared map.
const statusMap = computed(() => botStatusMap(t));

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
  if (props.executableOnly) params.set('can_execute_tasks', '1');
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
        <StatusBadge
          v-if="statusBadge && (option as any).status"
          class="mt-next-0_5 self-start"
          :status="(option as any).status"
          :status-map="statusMap"
          size="sm"
        />
        <span
          v-else-if="statusLabel((option as any).status)"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ statusLabel((option as any).status) }}
        </span>
      </span>
    </template>

    <!-- Empty state. Under `executableOnly` with NO search query the list is empty for a
         REASON the user can act on (no bot has the task-execution module on), so say it
         instead of a bare "no results". A consumer #empty slot still wins, and every
         other case renders exactly Select's default text. -->
    <template #empty="scope">
      <slot name="empty" v-bind="scope">
        <span v-if="executableOnly && !scope.query">
          {{ t('botSelect.emptyExecutable', 'No bot can execute tasks yet') }}
        </span>
        <span v-else>{{ t('select.empty', 'No results') }}</span>
      </slot>
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
        <StatusBadge
          v-if="statusBadge && (option as any).status"
          class="mt-next-0_5 self-start"
          :status="(option as any).status"
          :status-map="statusMap"
          size="sm"
        />
        <span
          v-else-if="statusLabel((option as any).status)"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ statusLabel((option as any).status) }}
        </span>
      </span>
    </template>

    <!-- Empty state. Under `executableOnly` with NO search query the list is empty for a
         REASON the user can act on (no bot has the task-execution module on), so say it
         instead of a bare "no results". A consumer #empty slot still wins, and every
         other case renders exactly Select's default text. -->
    <template #empty="scope">
      <slot name="empty" v-bind="scope">
        <span v-if="executableOnly && !scope.query">
          {{ t('botSelect.emptyExecutable', 'No bot can execute tasks yet') }}
        </span>
        <span v-else>{{ t('select.empty', 'No results') }}</span>
      </slot>
    </template>

    <!-- The selected-value display. A consumer may replace it (e.g. to mark an author
         that no longer resolves); with no slot the default glyph + name renders exactly
         as before. -->
    <template #value="{ option }">
      <slot name="value" :option="option">
        <Icon name="sparkles" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <span class="truncate">{{ option.label }}</span>
      </slot>
    </template>
  </Select>
</template>
