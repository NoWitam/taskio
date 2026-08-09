<script setup lang="ts">
// KnowledgeTocPanel — the reader's table of contents.
//
// FLAT, not a tree, and that is a data decision rather than a styling one: an entry has a
// `position` and no parent, so a tree would promise a hierarchy the model does not have. What it
// offers instead is OPTIONAL GROUPING by one `enum` metadata field — a real axis the schema
// defines — remembered per base in localStorage, because it is a view preference and not
// application state worth a URL key or a round trip.
//
// Reordering is keyboard-first (↑ / ↓ buttons, no drag & drop). D&D without a keyboard equivalent
// is an accessibility regression, and the ordering endpoint takes the WHOLE id set anyway, so a
// two-row swap and a drag cost exactly the same request.
import { computed, ref, watch } from 'vue';
import Surface from '../../../ui/layout/Surface.vue';
import Text from '../../../ui/primitives/Text.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Button from '../../../ui/primitives/Button.vue';
import Link from '../../../ui/primitives/Link.vue';
import StatusBadge from '../../../ui/data/StatusBadge.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import Select from '../../../ui/forms/Select.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import { entryStatusMap } from '../statusMaps';
import { byPosition } from '../entryMeta';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeBase, KnowledgeEntryListItem, KnowledgeSchemaField } from '../types';

const props = defineProps<{
  base: KnowledgeBase | null;
  entries: KnowledgeEntryListItem[];
  /** Slug of the entry currently being read. */
  activeSlug: string | null;
  loading?: boolean;
  /**
   * The entry LIST failed to load.
   *
   * The contents panel has no fetch of its own — it renders whatever list the reader loaded — so a
   * failed list reached it as an empty array, and "this base has no entries" is what it said. That
   * is silence dressed as an answer: the base is fine, the request is not, and nothing on screen
   * distinguished the two.
   */
  error?: string | null;
  /** True when the entry list was cut at the page cap — the panel says so instead of lying. */
  truncated?: boolean;
  /** Reordering is offered only when the viewer may write. */
  canReorder?: boolean;
  reordering?: boolean;
}>();

const emit = defineEmits<{
  (e: 'select', slug: string): void;
  (e: 'move', payload: { id: string; direction: -1 | 1 }): void;
  /** Re-run the entry-list fetch — the whole fix when only the request broke. */
  (e: 'reload'): void;
}>();

const { t } = useI18n();
const statusMap = computed(() => entryStatusMap(t));

// --- Local filter (never hits the API — this is a find-in-list, not a search) ---
const filter = ref('');

// --- Grouping ---------------------------------------------------------------
/** Only single-valued `enum` fields can group: an array field would put a row in several groups. */
const groupableFields = computed<KnowledgeSchemaField[]>(() =>
  (props.base?.metadata_schema ?? []).filter(
    (field) => field.descriptor?.base === 'enum' && !field.descriptor?.array,
  ),
);

const groupKey = ref<string>('');

/** A view preference, so it lives in localStorage — per base, since schemas differ. */
const storageKey = computed(() => (props.base ? `knowledge:toc-group:${props.base.id}` : null));

watch(
  storageKey,
  (key) => {
    if (!key) return;
    let stored: string | null = null;
    try {
      stored = localStorage.getItem(key);
    } catch {
      stored = null;
    }
    // Drop a remembered field that the schema no longer has.
    groupKey.value =
      stored && groupableFields.value.some((f) => f.key === stored) ? stored : '';
  },
  { immediate: true },
);

watch(groupKey, (value) => {
  if (!storageKey.value) return;
  try {
    if (value) localStorage.setItem(storageKey.value, value);
    else localStorage.removeItem(storageKey.value);
  } catch {
    /* private mode / quota — a lost preference is not worth an error. */
  }
});

const groupOptions = computed(() => [
  { value: '', label: t('knowledge.reader.toc.groupNone') },
  ...groupableFields.value.map((field) => ({ value: field.key, label: field.label || field.key })),
]);

// --- Rows -------------------------------------------------------------------
const ordered = computed(() => byPosition(props.entries));

const filtered = computed(() => {
  const term = filter.value.trim().toLowerCase();
  if (term === '') return ordered.value;
  return ordered.value.filter(
    (entry) =>
      entry.title.toLowerCase().includes(term) || entry.slug.toLowerCase().includes(term),
  );
});

interface TocGroup {
  key: string;
  label: string;
  entries: KnowledgeEntryListItem[];
}

/** The option LABEL for a metadata value — a key like `b2b` is not what the author wrote down. */
function optionLabel(field: KnowledgeSchemaField | undefined, value: unknown): string | null {
  if (value == null || value === '') return null;
  const raw = String(value);
  const option = field?.descriptor?.options?.find((o) => o.key === raw);
  return option?.label || raw;
}

const groups = computed<TocGroup[]>(() => {
  if (!groupKey.value) return [{ key: '', label: '', entries: filtered.value }];

  const field = groupableFields.value.find((f) => f.key === groupKey.value);
  const buckets = new Map<string, TocGroup>();
  const unassigned: KnowledgeEntryListItem[] = [];

  for (const entry of filtered.value) {
    const label = optionLabel(field, entry.metadata?.[groupKey.value]);
    if (label == null) {
      unassigned.push(entry);
      continue;
    }
    const bucket = buckets.get(label) ?? { key: label, label, entries: [] };
    bucket.entries.push(entry);
    buckets.set(label, bucket);
  }

  const out = [...buckets.values()].sort((a, b) => a.label.localeCompare(b.label));
  // "No value" LAST: it is the residue, not a category.
  if (unassigned.length) {
    out.push({ key: '__none__', label: t('knowledge.reader.toc.groupEmpty'), entries: unassigned });
  }
  return out;
});

/** Move controls act on the FULL order, never on the filtered view (which would reorder a lie). */
const canMove = computed(() => props.canReorder && filter.value.trim() === '' && !groupKey.value);

function moveDisabled(entry: KnowledgeEntryListItem, direction: -1 | 1): boolean {
  const index = ordered.value.findIndex((e) => e.id === entry.id);
  return index < 0 || (direction === -1 ? index === 0 : index === ordered.value.length - 1);
}
</script>

<template>
  <Surface bg="card" border elevation="sm" radius="lg" class="flex min-h-0 flex-col gap-next-3 p-next-3">
    <!-- Header + count. No pluralization anywhere in this module: "Label: {count}". -->
    <div class="flex items-baseline justify-between gap-next-2">
      <Text variant="caption" tone="muted" class="uppercase tracking-wide">
        {{ t('knowledge.reader.toc.title') }}
      </Text>
      <Text variant="caption" tone="muted">
        {{ t('knowledge.reader.toc.count', '', { count: entries.length }) }}
      </Text>
    </div>

    <TextInput
      v-model="filter"
      type="search"
      size="sm"
      leading-icon="search"
      :placeholder="t('knowledge.reader.toc.search')"
      :aria-label="t('knowledge.reader.toc.search')"
    />

    <Select
      v-if="groupableFields.length > 0"
      v-model="groupKey"
      size="sm"
      :options="groupOptions"
      :aria-label="t('knowledge.reader.toc.group')"
      :placeholder="t('knowledge.reader.toc.group')"
    />

    <!-- Loading: alternating text lines, shaped like the rows they replace. -->
    <div
      v-if="loading"
      class="flex flex-col gap-next-2"
      role="status"
      :aria-label="t('knowledge.common.loadingLabel')"
    >
      <Skeleton v-for="n in 8" :key="`sk-${n}`" variant="text" :width="n % 2 ? '70%' : '50%'" />
    </div>

    <!--
      FAILED, with the way out. Placed BEFORE the empty state on purpose: the two are reached by the
      same `entries.length === 0`, and the one that is a fault has to win.
    -->
    <Alert v-else-if="error" variant="danger" size="sm" data-toc-error>
      {{ t('knowledge.common.loadError') }}
      <template #actions>
        <Button
          variant="outline"
          size="sm"
          leading-icon="rotate-ccw"
          data-toc-retry
          @click="emit('reload')"
        >
          {{ t('knowledge.common.retry') }}
        </Button>
      </template>
    </Alert>

    <EmptyState
      v-else-if="entries.length === 0"
      size="sm"
      icon="file-text"
      :title="t('knowledge.reader.empty.title')"
    />

    <EmptyState
      v-else-if="filtered.length === 0"
      size="sm"
      variant="search"
      :title="t('knowledge.reader.toc.noMatches')"
    />

    <nav v-else :aria-label="t('knowledge.reader.toc.navLabel')" class="min-h-0 flex-1 overflow-y-auto">
      <ul class="flex flex-col gap-next-0_5">
        <template v-for="group in groups" :key="group.key">
          <li v-if="group.label" role="presentation" class="sticky top-0 bg-next-card py-next-1">
            <Text variant="caption" tone="muted" class="uppercase tracking-wide">{{ group.label }}</Text>
          </li>

          <li v-for="entry in group.entries" :key="entry.id">
            <div
              class="group flex items-center gap-next-1 rounded-next-md px-next-2 py-next-1_5 text-next-sm"
              :class="
                entry.slug === activeSlug
                  ? 'bg-next-primary-subtle text-next-primary-subtle-foreground'
                  : 'hover:bg-next-muted'
              "
            >
              <!-- `plain`: the ACTIVE row already carries its colour on the wrapper
                   (`bg-next-primary-subtle` + its foreground); a colour-forcing link variant would
                   overrule it and make the current entry indistinguishable from the rest. -->
              <Link
                :href="`#${entry.slug}`"
                variant="plain"
                class="min-w-0 flex-1 truncate text-left"
                :aria-current="entry.slug === activeSlug ? 'page' : undefined"
                @click.prevent="emit('select', entry.slug)"
              >
                {{ entry.title }}
              </Link>

              <!-- Trailing markers: conditional badges BEFORE the permanent move controls, so the
                   controls never shift position between rows. -->
              <!--
                THE PRIMITIVE, not a hand-rolled copy. This read the same map and then assembled
                its own `Badge` from it — and dropped the ICON on the way, so the one place in the
                module where a status is text+colour was here, while every sibling is
                text+icon+colour. Reassembling a primitive from its own inputs is how a design
                system loses a signal one component at a time.
              -->
              <StatusBadge
                v-if="entry.status && entry.status !== 'approved'"
                :status="entry.status"
                :status-map="statusMap"
                size="sm"
                class="shrink-0"
              />

              <Icon
                v-if="entry.is_stale"
                name="alert-triangle"
                class="shrink-0 text-next-xs text-next-warning"
                role="img"
                :aria-label="t('knowledge.reader.stale')"
              />

              <span v-if="canMove" class="flex shrink-0 items-center">
                <Button
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="chevron-up"
                  :disabled="reordering || moveDisabled(entry, -1)"
                  :aria-label="`${t('knowledge.reader.toc.moveUp')}: ${entry.title}`"
                  @click="emit('move', { id: entry.id, direction: -1 })"
                />
                <Button
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="chevron-down"
                  :disabled="reordering || moveDisabled(entry, 1)"
                  :aria-label="`${t('knowledge.reader.toc.moveDown')}: ${entry.title}`"
                  @click="emit('move', { id: entry.id, direction: 1 })"
                />
              </span>
            </div>
          </li>
        </template>
      </ul>
    </nav>

    <!-- Honesty about the cap: never a silently short list. -->
    <Alert v-if="truncated" variant="info" size="sm">
      {{ t('knowledge.reader.toc.truncated') }}
    </Alert>
  </Surface>
</template>
