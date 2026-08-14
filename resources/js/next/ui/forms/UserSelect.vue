<script setup lang="ts">
// UserSelect — a GLOBAL, reusable people picker for the "next" frontend.
//
// A thin wrapper over Select that loads users from the verified backend contract
//   GET /users?cursor=&q=&per_page=20 → { data: [{ id, name, email, avatar }],
//   meta: { next_cursor } }
// and renders each option / chip / single value with the user's Avatar (which
// falls back to name-initials when `avatar` is null, per UserResource today) plus
// the name (and email, muted, in the option rows). Built-in `searchable` drives
// the `q` query param; cursor pagination + skeletons come from Select.
//
// v-model is a single user id (`string | null`) by default, or `string[]` when
// `:multiple`. Already-selected users that may be off the current async page are
// preserved as chips/value via Select's `selectedOptions` label cache: pass any
// known users through `:seed` (or they get cached as they load).
//
// A11y + i18n: placeholders/labels resolve through t(); the trigger keeps
// Select's full combobox ARIA. No legacy imports; namespaced tokens only.
import { computed, ref, watch } from 'vue';
import Select, {
  type SelectFetchArgs,
  type SelectFetchResult,
  type SelectFetchOptions,
  type SelectOption,
} from './Select.vue';
import Avatar from '../primitives/Avatar.vue';
import { type IconName } from '../primitives/icons';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { type ControlSize } from './fieldShell';

const { t } = useI18n();

/** A user option carries the avatar + email so the slots can render them. */
interface UserOption extends SelectOption {
  avatar: string | null;
  email: string | null;
}

interface ApiUser {
  id: string | number;
  name: string;
  email?: string | null;
  avatar?: string | null;
}

const props = withDefaults(
  defineProps<{
    /** Select multiple people (v-model becomes string[]). */
    multiple?: boolean;
    /**
     * Multi trigger display: 'chips' (default) or 'summary'. Mirrors Select.
     */
    display?: 'chips' | 'summary';
    summary?: boolean;
    size?: ControlSize;
    /** Leading icon (defaults to `users`; pass `null` to drop it). */
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
     * Seed already-known users so their chips/value render before (or without) an
     * async page that contains them — e.g. a task's current assignee.
     */
    seed?: Array<Pick<ApiUser, 'id' | 'name'> & { email?: string | null; avatar?: string | null }>;
    /**
     * Override the users loader (defaults to the `/users` endpoint). Useful for the
     * styleguide gallery / tests, which inject a mock dataset. The returned
     * SelectOptions may carry `avatar` / `email` for the slots.
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
  },
);

// Default the leading icon to `users`; `:leading-icon="null"` opts out explicitly.
const resolvedLeadingIcon = computed<IconName | undefined>(() =>
  props.leadingIcon === null ? undefined : props.leadingIcon ?? 'users',
);

// v-model: single id OR string[] in multiple mode (mirrors Select's two models).
const single = defineModel<string | null>({ default: null });
const multi = defineModel<string[]>('values', { default: () => [] });

function toOption(u: ApiUser): UserOption {
  return {
    value: String(u.id),
    label: u.name,
    avatar: u.avatar ?? null,
    email: u.email ?? null,
  };
}

// Running label cache of every user we have seen (seed + loaded pages), so the
// slots can resolve avatar/email for already-selected, possibly off-page values.
const seededOptions = ref<UserOption[]>(
  (props.seed ?? []).map((u) => toOption(u as ApiUser)),
);

// Merge late-arriving seeds (e.g. ids resolved by name AFTER mount, on a refresh)
// so chips/trigger swap the id fallback for the real name without a reselect.
watch(
  () => props.seed,
  (seed) => {
    if (!seed?.length) return;
    const known = new Set(seededOptions.value.map((o) => o.value));
    const add = seed.map((u) => toOption(u as ApiUser)).filter((o) => !known.has(o.value));
    if (add.length) seededOptions.value = [...seededOptions.value, ...add];
  },
  { deep: true },
);

const selectedSeed = computed<SelectOption[]>(() => seededOptions.value);

// Surface the RESOLVED selected options (id → {value,label,…}) so a consumer can
// render per-value chips elsewhere (e.g. a FilterBar) showing actual names, not a
// count. Unknown ids (not yet loaded/seeded) fall back to the id as the label.
const emit = defineEmits<{
  (e: 'update:selected', options: UserOption[]): void;
}>();
const selectedResolved = computed<UserOption[]>(() => {
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
        avatar: null,
        email: null,
      },
  );
});
watch(selectedResolved, (v) => emit('update:selected', v), {
  deep: true,
  immediate: true,
});

// GET /users?cursor=&q=&per_page=20 → { data, meta: { next_cursor } }.
async function fetchUsers(args: SelectFetchArgs): Promise<SelectFetchResult> {
  if (props.fetchOptions) {
    const result = await props.fetchOptions(args);
    // Keep the chip/value cache fed from the injected loader too.
    const known = new Set(seededOptions.value.map((o) => o.value));
    seededOptions.value = [
      ...seededOptions.value,
      ...(result.options as UserOption[]).filter((o) => !known.has(o.value)),
    ];
    return result;
  }
  const { cursor, query } = args;
  const params = new URLSearchParams();
  if (cursor) params.set('cursor', cursor);
  if (query) params.set('q', query);
  params.set('per_page', '20');
  const res = await api.get<{ data: ApiUser[]; meta: { next_cursor: string | null } }>(
    `/users?${params.toString()}`,
  );
  const options = (res.data ?? []).map(toOption);
  // Keep the cache fresh so chips/value can read avatar/email later.
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
      ? t('userSelect.placeholderMultiple', 'Select people')
      : t('userSelect.placeholder', 'Select a person')),
);
const ariaLabelText = computed(
  () => props.ariaLabel ?? t('userSelect.ariaLabel', 'Select people'),
);

defineExpose({ fetchUsers });
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
    :fetch-options="fetchUsers"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('userSelect.search', 'Search people…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Avatar
        size="xs"
        :name="option.label"
        :src="(option as any).avatar ?? undefined"
      />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-next-sm">{{ option.label }}</span>
        <span
          v-if="(option as any).email"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ (option as any).email }}
        </span>
      </span>
    </template>

    <template #chip="{ option, remove }">
      <span
        class="next-badge inline-flex h-6 min-w-0 items-center gap-next-1 rounded-next-full bg-next-muted py-0 pl-next-0_5 pr-next-1_5 font-next-medium text-next-fg"
      >
        <Avatar
          size="xs"
          :name="option.label"
          :src="(option as any).avatar ?? undefined"
        />
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
    :fetch-options="fetchUsers"
    :selected-options="selectedSeed"
    :placeholder="placeholderText"
    :search-placeholder="t('userSelect.search', 'Search people…')"
    :aria-label="ariaLabelText"
  >
    <template #option="{ option }">
      <Avatar
        size="xs"
        :name="option.label"
        :src="(option as any).avatar ?? undefined"
      />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-next-sm">{{ option.label }}</span>
        <span
          v-if="(option as any).email"
          class="truncate text-next-xs text-next-muted-foreground"
        >
          {{ (option as any).email }}
        </span>
      </span>
    </template>

    <template #value="{ option }">
      <Avatar
        size="xs"
        :name="option.label"
        :src="(option as any).avatar ?? undefined"
      />
      <span class="truncate">{{ option.label }}</span>
    </template>
  </Select>
</template>
