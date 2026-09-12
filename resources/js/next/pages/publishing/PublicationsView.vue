<script setup lang="ts">
// PublicationsView — the publications BROWSE screen (R4, §4).
//
// Shape: PageHeader → FilterBar (sticky, with the MANDATORY Saved Views toolbar in `#top`)
// → the "needs a decision" banner → the status tab bar → a single-column list of cards with
// cursor pagination. Filter state lives here, is pushed to the store, and is mirrored into
// the router query — the `TasksView` / `WorkflowsView` pattern, by hand
// (`useRouteQueryHydration` does not exist in `next`).
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// THE COUNTS ARE REFETCHED WITH THE LIST, EVERY TIME
// ─────────────────────────────────────────────────────────────────────────────────────────
// `GET /counts` ignores `status` and honours every OTHER filter, so narrowing the
// destination or the date range changes the numbers on the tabs. A bar left un-refetched
// keeps saying 12 while the list under it shows 3 — a lie nobody can see, in the one place
// people look to decide where to go next. And when the counts request FAILS the badges
// disappear rather than reading zero: "we could not count" and "there is nothing" are
// different statements, and only one of them is true.
//
// THE STATUS TAB IS NOT PART OF A SAVED VIEW (D6). A saved view holds destination, search
// and the date range. If it held the status too, the tab bar and the view pill would both
// claim the same piece of state and every tab click would mark the active view "modified" —
// a bar full of false `modified` pills teaches people to ignore that pill everywhere else.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import DateRangeFilter, { type DateRangeFilterValue } from '../../ui/forms/DateRangeFilter.vue';
import PublicationCard from './PublicationCard.vue';
import SchedulePublicationModal from './SchedulePublicationModal.vue';
import { usePublishingStore } from '../../app/stores/publishing';
import { usePublishingConnectionsStore } from '../../app/stores/publishingConnections';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore, type FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import { ALL_PLATFORMS, ATTENTION_STATUSES, STATUS_TABS, statusIcon } from './publishingMeta';
import { deleteCopyKind } from './publicationActions';
import { serverMessageOf } from './publishingErrors';
import type { Publication, PublicationFilters, PublicationStatus, PublishingPlatform } from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = usePublishingStore();
const connectionsStore = usePublishingConnectionsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

const SAVED_VIEWS_CONTEXT = 'publishing';

/** `'all'` is the ABSENCE of `status`, never `status=all` — the server knows no such value. */
type StatusTab = 'all' | PublicationStatus;
const statusTab = ref<StatusTab>('all');

const search = ref('');
const platforms = ref<PublishingPlatform[]>([]);
const range = ref<DateRangeFilterValue>({
  preset: '',
  from: null,
  to: null,
  hide_without_deadline: false,
});

const filters = computed<PublicationFilters>(() => ({
  status: statusTab.value === 'all' ? undefined : statusTab.value,
  platform: platforms.value.length ? [...platforms.value] : undefined,
  search: search.value || undefined,
  scheduled_from: range.value.from ?? undefined,
  scheduled_to: range.value.to ?? undefined,
}));

const hasActiveFilters = computed(
  () => !!search.value || platforms.value.length > 0 || !!range.value.from || !!range.value.to,
);

// --- Destination options ----------------------------------------------------
/**
 * Labels come from a LOADED ROW's `platform_label` whenever one exists — the server's prose
 * in the reader's language. The frontend catalog is the floor for an empty list, where there
 * is no row to read a label from (gap L1: there is no destination catalogue endpoint).
 */
function platformLabel(platform: string): string {
  const fromData = store.items.find((p) => p.platform === platform)?.platform_label;
  return fromData ?? t(`publishing.platforms.${platform}`);
}

const platformOptions = computed<SelectOption[]>(() =>
  ALL_PLATFORMS.map((platform) => ({ value: platform, label: platformLabel(platform) })),
);

// --- Filter chips -----------------------------------------------------------
// Multi-value groups render ONE CHIP PER VALUE, never "2 selected": a person has to be able
// to see, and remove, exactly what is narrowing the list.
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({
      key: `search=${search.value}`,
      label: t('publishing.filters.chip.search', '', { value: search.value }),
    });
  }
  if (platforms.value.length) {
    chips.push({
      key: 'platform',
      label: t('publishing.filters.chip.platform'),
      values: platforms.value.map((p) => ({ key: `platform:${p}`, label: platformLabel(p) })),
      operatorLabel: t('common.select', 'Any'),
    });
  }
  if (range.value.from) {
    chips.push({
      key: `from=${range.value.from}`,
      label: t('publishing.filters.chip.from', '', { value: range.value.from }),
    });
  }
  if (range.value.to) {
    chips.push({
      key: `to=${range.value.to}`,
      label: t('publishing.filters.chip.to', '', { value: range.value.to }),
    });
  }
  return chips;
});

const decoratedFilters = computed<ActiveFilter[]>(() =>
  savedViews.decorateActiveFilters(activeFilters.value),
);

function removeFilter(key: string): void {
  if (key.startsWith('search')) search.value = '';
  else if (key === 'platform') platforms.value = [];
  else if (key.startsWith('platform:')) {
    const value = key.slice('platform:'.length);
    platforms.value = platforms.value.filter((p) => p !== value);
  } else if (key.startsWith('from')) range.value = { ...range.value, from: null, preset: '' };
  else if (key.startsWith('to')) range.value = { ...range.value, to: null, preset: '' };
}

function clearAll(): void {
  search.value = '';
  platforms.value = [];
  range.value = { preset: '', from: null, to: null, hide_without_deadline: false };
}

// --- Saved views ------------------------------------------------------------
function serializeSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (platforms.value.length) snap.platform = [...platforms.value];
  if (range.value.from) snap.from = range.value.from;
  if (range.value.to) snap.to = range.value.to;
  return snap;
}

function applySnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  platforms.value = Array.isArray(snap.platform)
    ? (snap.platform.filter((p): p is PublishingPlatform =>
        (ALL_PLATFORMS as readonly string[]).includes(String(p)),
      ) as PublishingPlatform[])
    : [];
  range.value = {
    preset: '',
    from: typeof snap.from === 'string' ? snap.from : null,
    to: typeof snap.to === 'string' ? snap.to : null,
    hide_without_deadline: false,
  };
}

function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  if (Array.isArray(snap.platform)) {
    for (const value of snap.platform) keys.push(`platform:${String(value)}`);
  }
  if (snap.from) keys.push(`from=${String(snap.from)}`);
  if (snap.to) keys.push(`to=${String(snap.to)}`);
  return keys;
}

function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('search')) {
    search.value = typeof snap.search === 'string' ? snap.search : '';
  } else if (key.startsWith('platform:')) {
    const value = key.slice('platform:'.length) as PublishingPlatform;
    if (!platforms.value.includes(value)) platforms.value = [...platforms.value, value];
  } else if (key.startsWith('from')) {
    range.value = { ...range.value, from: typeof snap.from === 'string' ? snap.from : null };
  } else if (key.startsWith('to')) {
    range.value = { ...range.value, to: typeof snap.to === 'string' ? snap.to : null };
  }
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeSnapshot,
  apply: applySnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});

const savingView = ref(false);
const saveModalOpen = ref(false);
const saveModalMode = ref<'create' | 'edit'>('create');
const editingView = ref<FilterTab | null>(null);
const saveModalNameError = ref<string | null>(null);
const deleteViewConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);

async function onSaveActiveView(): Promise<void> {
  savingView.value = true;
  try {
    await savedViews.saveActive();
    toast.success(t('tasks.savedViews.toast.updated'));
  } catch {
    toast.danger(t('tasks.savedViews.toast.saveError'));
  } finally {
    savingView.value = false;
  }
}

function onSaveAs(): void {
  saveModalMode.value = 'create';
  editingView.value = null;
  saveModalNameError.value = null;
  saveModalOpen.value = true;
}

function onEditView(view: FilterTab): void {
  saveModalMode.value = 'edit';
  editingView.value = view;
  saveModalNameError.value = null;
  saveModalOpen.value = true;
}

async function onSaveModalSubmit(payload: SaveViewSubmit): Promise<void> {
  saveModalNameError.value = null;
  savingView.value = true;
  const iconEnum = toIconEnumValue(payload.icon);
  try {
    if (saveModalMode.value === 'edit' && editingView.value) {
      await filterTabsStore.update(SAVED_VIEWS_CONTEXT, editingView.value.id, {
        name: payload.name,
        icon: iconEnum,
        filters: serializeSnapshot(),
      });
      toast.success(t('tasks.savedViews.toast.updated'));
    } else {
      await savedViews.saveAs(payload.name, iconEnum);
      toast.success(t('tasks.savedViews.toast.created'));
    }
    saveModalOpen.value = false;
  } catch (err: unknown) {
    const fieldErrors = (err as { fieldErrors?: Record<string, string> }).fieldErrors ?? {};
    if (fieldErrors.name) saveModalNameError.value = fieldErrors.name;
    else toast.danger(t('tasks.savedViews.toast.saveError'));
  } finally {
    savingView.value = false;
  }
}

function onDeleteView(view: FilterTab): void {
  viewToDelete.value = view;
  deleteViewConfirmOpen.value = true;
}

const deleteViewMessage = computed(() =>
  t('tasks.savedViews.confirm.deleteMessage', '', { name: viewToDelete.value?.name ?? '' }),
);

async function onConfirmDeleteView(): Promise<void> {
  if (!viewToDelete.value) return;
  savingView.value = true;
  const wasActive = String(viewToDelete.value.id) === String(savedViews.activeTabId.value);
  try {
    await filterTabsStore.remove(SAVED_VIEWS_CONTEXT, viewToDelete.value.id);
    if (wasActive) savedViews.clearActive();
    toast.success(t('tasks.savedViews.toast.deleted'));
    deleteViewConfirmOpen.value = false;
    viewToDelete.value = null;
  } catch {
    toast.danger(t('tasks.savedViews.toast.saveError'));
  } finally {
    savingView.value = false;
  }
}

async function moveView(view: FilterTab, dir: -1 | 1): Promise<void> {
  const list = savedViews.tabs.value;
  const idx = list.findIndex((v) => String(v.id) === String(view.id));
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= list.length) return;
  const ids = list.map((v) => v.id);
  [ids[idx], ids[target]] = [ids[target], ids[idx]];
  try {
    await filterTabsStore.reorder(SAVED_VIEWS_CONTEXT, ids);
    toast.success(t('tasks.savedViews.toast.reordered'));
  } catch {
    toast.danger(t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load();
  }
}

// --- Fetch orchestration ----------------------------------------------------
function refetch(): void {
  void store.fetchPublications(filters.value, { reset: true }).catch(() => undefined);
  // Together with the list, ALWAYS — see the file docblock.
  void store.fetchCounts(filters.value).catch(() => undefined);
}

const debouncedRefetch = useDebounce(refetch, 350);
// The `hydrating` guard covers the FLUSH after hydration too (see `hydrateFromQuery`), so
// reading filters out of the URL on mount does not fire a second identical request on top of
// the one `onMounted` already makes — the store's token would discard it, but the server
// would still have answered twice.
watch(search, () => {
  if (!hydrating) debouncedRefetch();
});
watch(
  [statusTab, platforms, range],
  () => {
    if (!hydrating) refetch();
  },
  { deep: true },
);

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMore(filters.value),
  canLoadMore: () =>
    store.hasMore &&
    !store.loading &&
    !store.loadingMore &&
    !store.errored &&
    !store.loadMoreErrored,
});

// --- View state -------------------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(
  () => !store.loading && !store.loadingMore && !store.errored && items.value.length === 0,
);
const skeletons = Array.from({ length: 6 }, (_, i) => i);

/**
 * The server's own sentence when it sent one, this module's when it did not. A 400 ("no
 * active workspace") is a SHELL state rather than a module one, so the store's message is
 * shown as-is rather than dressed up in publishing words.
 */
const loadErrorDescription = computed(() => store.error ?? t('publishing.errors.loadDescription'));

const tabItems = computed<TabItem<StatusTab>[]>(() => {
  const counts = store.counts;
  const item = (value: StatusTab, count: number | undefined): TabItem<StatusTab> => ({
    value,
    label: t(`publishing.tabs.${value}`),
    icon: value === 'all' ? undefined : statusIcon(value),
    // `undefined` when the counts are unknown: the badge disappears rather than saying 0.
    badge: count,
  });
  return [
    item('all', counts?.total),
    ...STATUS_TABS.map((status) => item(status, counts?.counts?.[status])),
  ];
});

/** The three attention statuses that actually have rows — no link to an empty tab. */
const attentionLinks = computed(() =>
  ATTENTION_STATUSES.map((status) => ({ status, count: store.counts?.counts?.[status] ?? 0 })).filter(
    (entry) => entry.count > 0,
  ),
);

// --- URL sync ---------------------------------------------------------------
let hydrating = false;
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
const arr = (v: unknown): string[] =>
  Array.isArray(v) ? v.map((x) => String(x)) : v != null && v !== '' ? [String(v)] : [];

function hydrateFromQuery(): void {
  hydrating = true;
  const q = route.query;
  search.value = str(q.q);
  const status = str(q.status);
  statusTab.value = (STATUS_TABS as readonly string[]).includes(status)
    ? (status as PublicationStatus)
    : 'all';
  platforms.value = arr(q.platform).filter((p): p is PublishingPlatform =>
    (ALL_PLATFORMS as readonly string[]).includes(p),
  );
  range.value = {
    preset: '',
    from: str(q.from) || null,
    to: str(q.to) || null,
    hide_without_deadline: false,
  };
  // Released AFTER the watchers for these assignments have run. Clearing it synchronously
  // would leave the flag false by the time the post-flush watchers fire, which is the same
  // as having no guard at all.
  void nextTick(() => {
    hydrating = false;
  });
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string | string[]> = {};
  // Preserve the composer's own keys so changing a filter never closes the drawer.
  if (str(route.query.new)) query.new = str(route.query.new);
  if (str(route.query.edit)) query.edit = str(route.query.edit);

  if (search.value) query.q = search.value;
  if (statusTab.value !== 'all') query.status = statusTab.value;
  if (platforms.value.length) query.platform = [...platforms.value];
  if (range.value.from) query.from = range.value.from;
  if (range.value.to) query.to = range.value.to;
  void router.replace({ query });
}

watch([search, statusTab, platforms, range], () => syncQuery(), { deep: true });

// --- Row actions ------------------------------------------------------------
function onNew(): void {
  void router.push({ query: { ...route.query, new: '1' } });
}

function onEdit(publication: Publication): void {
  void router.push({ query: { ...route.query, edit: publication.id } });
}

const scheduleTarget = ref<Publication | null>(null);
function onSchedule(publication: Publication): void {
  scheduleTarget.value = publication;
}

async function onDelete(publication: Publication): Promise<void> {
  // FOUR SITUATIONS, FOUR SENTENCES. The `published` one matters most: deleting hides OUR
  // RECORD and changes nothing in the world, and the confirmation has to say exactly that.
  const kind = deleteCopyKind(publication);
  const copy = {
    plain: ['deleteTitle', 'deleteBody'],
    scheduled: ['deleteScheduledTitle', 'deleteScheduledBody'],
    blocked: ['deleteBlockedTitle', 'deleteBlockedBody'],
    published: ['deletePublishedTitle', 'deletePublishedBody'],
  }[kind];

  const ok = await confirm({
    title: t(`publishing.confirm.${copy[0]}`),
    message: t(`publishing.confirm.${copy[1]}`),
    confirmLabel:
      kind === 'published' ? t('publishing.actions.deleteRecord') : t('publishing.actions.delete'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;

  try {
    await store.deletePublication(publication.id);
    toast.success(
      kind === 'published' ? t('publishing.toasts.deletedPublished') : t('publishing.toasts.deleted'),
    );
    void store.fetchCounts(filters.value).catch(() => undefined);
  } catch (err) {
    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
  }
}

onMounted(() => {
  hydrateFromQuery();
  void savedViews.load();
  void connectionsStore.fetchConnections().catch(() => undefined);
  void store.loadTimezone().catch(() => undefined);
  refetch();
});
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader
      :title="t('publishing.title')"
      :description="t('publishing.subtitle')"
      icon="send"
    >
      <template #actions>
        <Button leading-icon="plus" @click="onNew">{{ t('publishing.newPublication') }}</Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      sticky
      control-size="md"
      :search-placeholder="t('publishing.filters.search')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('publishing.filters.clearAll')"
      @remove-filter="removeFilter"
      @clear-all="clearAll"
      @restore-filter="savedViews.restoreFilter"
    >
      <!-- Saved Views: ALWAYS present on a list screen (house rule). -->
      <template #top>
        <FilterTabBar
          :tabs="savedViews.tabs.value"
          :active-tab-id="savedViews.activeTabId.value"
          :dirty="savedViews.dirty.value"
          :loading="savedViews.loading.value"
          :error="savedViews.loadError.value"
          :has-active-filters="hasActiveFilters"
          :busy="savingView"
          @activate="savedViews.applyTab"
          @deactivate="savedViews.clearActive()"
          @save="onSaveActiveView"
          @save-as="onSaveAs"
          @edit="onEditView"
          @delete="onDeleteView"
          @move-up="(view) => moveView(view, -1)"
          @move-down="(view) => moveView(view, 1)"
          @retry="savedViews.load"
        />
      </template>

      <div class="min-w-0 flex-1 basis-56">
        <Select
          v-model:values="platforms"
          multiple
          display="chips"
          :options="platformOptions"
          leading-icon="send"
          :placeholder="t('publishing.filters.platformPlaceholder')"
          :aria-label="t('publishing.filters.platformLabel')"
        />
      </div>
      <div class="min-w-0 flex-1 basis-56">
        <!-- ONE range control for the moment — the house rule against two date fields. -->
        <DateRangeFilter
          v-model="range"
          :placeholder="t('publishing.filters.momentPlaceholder')"
          :aria-label="t('publishing.filters.momentLabel')"
        />
      </div>

      <template #results>
        <!-- The total is the SERVER's or it is not shown. `items.length` is how many pages
             have been scrolled through, not how many there are, and printing it as a total
             would understate the list by however far the reader has not scrolled. -->
        <span v-if="store.counts">
          {{ t('publishing.results.count', '', { count: store.counts.total }) }}
        </span>
        <span v-else-if="!store.loading && items.length === 0">
          {{ t('publishing.results.zero') }}
        </span>
        <Button v-if="hasActiveFilters" variant="ghost" size="xs" @click="clearAll">
          {{ t('publishing.filters.clearAll') }}
        </Button>
      </template>
    </FilterBar>

    <!-- "Needs a decision": the NUMBER IS THE SERVER'S (`needs_attention`), never a sum of
         three client-side counts — the day an eighth status joins that set, a client doing
         its own arithmetic would be quietly wrong. -->
    <Alert
      v-if="(store.counts?.needs_attention ?? 0) > 0"
      variant="warning"
      role="alert"
    >
      {{ t('publishing.attention.sentence', '', { count: store.counts?.needs_attention ?? 0 }) }}
      <template #actions>
        <Button
          v-for="link in attentionLinks"
          :key="link.status"
          variant="ghost"
          size="sm"
          @click="statusTab = link.status"
        >
          {{ t('publishing.attention.goTo', '', {
            label: t(`publishing.tabs.${link.status}`),
            count: link.count,
          }) }}
        </Button>
      </template>
    </Alert>

    <!-- The counts failed but the list did not: say so, and show NO badges. -->
    <Alert v-if="store.countsErrored" variant="warning" size="sm">
      <div class="flex items-center justify-between gap-next-2">
        <span>{{ t('publishing.counts.error') }}</span>
        <Button
          variant="ghost"
          size="xs"
          leading-icon="rotate-ccw"
          @click="store.fetchCounts(filters).catch(() => undefined)"
        >
          {{ t('publishing.counts.retry') }}
        </Button>
      </div>
    </Alert>

    <!-- Eight tabs in LIFECYCLE order, so the bar reads as the axis a publication travels
         along. Navigation only — the list below is shared, because only the query changes. -->
    <Tabs
      v-model="statusTab"
      :items="tabItems"
      variant="pills"
      size="sm"
      :aria-label="t('publishing.tabs.label')"
    />

    <!-- Error: the header, the filter bar and the tabs stay live — changing a filter is
         itself a retry. -->
    <EmptyState
      v-if="store.errored && items.length === 0"
      variant="error"
      role="alert"
      :title="t('publishing.errors.loadTitle')"
      :description="loadErrorDescription"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
          {{ t('publishing.actions.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Loading: six card-shaped skeletons. Never a spinner with "Loading…". -->
    <div v-else-if="initialLoading" class="flex flex-col gap-next-3">
      <EntityCard v-for="n in skeletons" :key="`sk-${n}`" loading />
    </div>

    <!-- Empty: the tabs say DIFFERENT things, because they mean different things. "Nothing
         failed" and "nothing to check" look like the same emptiness and are not: one is
         about knowledge, the other about its absence. -->
    <EmptyState
      v-else-if="isEmpty && hasActiveFilters"
      variant="search"
      :title="t('publishing.empty.search.title')"
      :description="t('publishing.empty.search.description')"
    >
      <template #action>
        <Button size="sm" variant="outline" @click="clearAll">
          {{ t('publishing.empty.search.action') }}
        </Button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="isEmpty && statusTab === 'all'"
      variant="default"
      icon="send"
      :title="t('publishing.empty.firstRun.title')"
      :description="t('publishing.empty.firstRun.description')"
    >
      <template #action>
        <Button size="sm" leading-icon="plus" @click="onNew">
          {{ t('publishing.newPublication') }}
        </Button>
      </template>
      <template #secondary>
        <Button
          variant="ghost"
          size="sm"
          leading-icon="link-2"
          @click="router.push({ name: 'next.publishing.connections' })"
        >
          {{ t('publishing.empty.firstRun.secondary') }}
        </Button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="isEmpty"
      variant="default"
      :icon="statusIcon(statusTab)"
      :title="t(`publishing.empty.${statusTab}.title`)"
      :description="t(`publishing.empty.${statusTab}.description`)"
    >
      <template v-if="statusTab === 'draft'" #action>
        <Button size="sm" leading-icon="plus" @click="onNew">
          {{ t('publishing.newPublication') }}
        </Button>
      </template>
      <template v-else-if="statusTab === 'scheduled'" #secondary>
        <Button variant="ghost" size="sm" leading-icon="calendar" @click="router.push('/calendar')">
          {{ t('publishing.empty.scheduled.secondary') }}
        </Button>
      </template>
    </EmptyState>

    <!-- The list. One column at every breakpoint: the subtitle needs the width. -->
    <template v-else>
      <div role="list" class="flex flex-col gap-next-3">
        <div v-for="publication in items" :key="publication.id" role="listitem">
          <PublicationCard
            :publication="publication"
            :timezone="store.timezone"
            :connections="connectionsStore.connections"
            @edit="onEdit"
            @schedule="onSchedule"
            @delete="onDelete"
          />
        </div>

        <template v-if="store.loadingMore">
          <EntityCard v-for="n in 2" :key="`more-${n}`" loading />
        </template>
      </div>

      <!-- An append failure keeps every loaded card on screen and pauses the sentinel. -->
      <Alert v-if="store.loadMoreErrored && items.length > 0" variant="danger" size="sm">
        <div class="flex items-center justify-between gap-next-2">
          <span>{{ t('publishing.errors.loadDescription') }}</span>
          <Button
            variant="ghost"
            size="xs"
            leading-icon="rotate-ccw"
            @click="store.retryLoadMore(filters)"
          >
            {{ t('publishing.actions.retry') }}
          </Button>
        </div>
      </Alert>

      <div
        v-if="store.hasMore && !store.errored && !store.loadMoreErrored"
        ref="sentinelRef"
        aria-hidden="true"
        class="h-px w-full"
      />
    </template>

    <!-- Arming, from a row. The modal owns the call and its refusals. -->
    <SchedulePublicationModal
      v-if="scheduleTarget"
      :key="scheduleTarget.id"
      :publication="scheduleTarget"
      :timezone="store.timezone"
      @close="scheduleTarget = null"
      @done="scheduleTarget = null"
    />

    <SaveViewModal
      v-model:open="saveModalOpen"
      :mode="saveModalMode"
      :initial-name="editingView?.name ?? ''"
      :initial-icon="editingView?.icon ?? null"
      :submitting="savingView"
      :name-error="saveModalNameError"
      @submit="onSaveModalSubmit"
    />

    <ConfirmDialog
      v-model:open="deleteViewConfirmOpen"
      variant="danger"
      :title="t('tasks.savedViews.confirm.deleteTitle')"
      :message="deleteViewMessage"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      :loading="savingView"
      @confirm="onConfirmDeleteView"
    />
  </div>
</template>
