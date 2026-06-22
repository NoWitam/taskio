// useFilterTabs — saved-views ("Filter Tabs") orchestration for a list screen.
//
// Bridges the FilterTabs Pinia store (server state) with a PAGE's live filter
// refs. The page stays the single owner of its filter shape; it supplies four
// pure adapters so this composable can stay domain-agnostic:
//
//   • serialize()            → build the snapshot object to persist for the
//                              CURRENT filter state. The page encodes D1 dates
//                              here (absolute vs relative) and EXCLUDES the
//                              bucket (D3 — Active/Archive/Trash is view state,
//                              not part of the snapshot).
//   • apply(snapshot)        → restore the page's filter refs FROM a snapshot.
//                              The page expands relative dates here (today+offset).
//   • normalize(snapshot)    → a canonical, order-insensitive set of "filter
//                              keys" for the snapshot (sorted multi-values, no
//                              empty/null/false). Used for dirty-state + the
//                              per-chip trit-state. Reordering filters must NOT
//                              produce dirty → that's guaranteed by comparing
//                              SETS of keys, never array order.
//   • restoreValue(key, tab) → restore a SINGLE filter key from the active
//                              view's snapshot (for the FilterBar "restore"
//                              affordance on a `tab-disabled` chip).
//
// Trit-state per chip key (vs the active view's snapshot):
//   in both        → 'tab-active'  (baseline)
//   only current   → 'extra'
//   only snapshot  → 'tab-disabled' (rendered as a non-removable struck chip)
// With NO active view, every chip is left undecorated (renders like today).
import { computed, ref, type Ref } from 'vue';
import { useFilterTabsStore, type FilterTab } from '../stores/filterTabs';
import type {
  ActiveFilter,
  ActiveFilterValue,
  FilterTabState,
} from '../../ui/patterns/FilterBar.vue';

/** The opaque snapshot the page serializes/applies (persisted as `filters`). */
export type FilterSnapshot = Record<string, unknown>;

export interface UseFilterTabsAdapters {
  /** Build the snapshot for the current live filter state (page encodes D1/D3). */
  serialize: () => FilterSnapshot;
  /** Restore the page's filter refs from a snapshot (page expands relative dates). */
  apply: (snapshot: FilterSnapshot) => void;
  /**
   * Canonical, order-insensitive KEY SET for a snapshot — one entry per active
   * scalar / per multi-value item, matching the chip `key`s the page renders
   * (e.g. `search`, `priority`, `user_id:42`, `labels:7`, `date_from`).
   * Empty / null / false values must be omitted so they never read as dirty.
   */
  normalize: (snapshot: FilterSnapshot) => string[];
  /** Restore a single chip key from the active snapshot back into live refs. */
  restoreValue: (key: string, snapshot: FilterSnapshot) => void;
}

export interface UseFilterTabsReturn {
  /** Reactive list of saved views for this context (sorted). */
  tabs: Ref<FilterTab[]>;
  /** The active view id (null = none / unsaved). */
  activeTabId: Ref<string | number | null>;
  /** The active view object (or null). */
  activeTab: Ref<FilterTab | null>;
  /** True when an active view exists and the live state differs from its snapshot. */
  dirty: Ref<boolean>;
  /** Per-context loading flag. */
  loading: Ref<boolean>;
  /** Per-context load error flag. */
  loadError: Ref<boolean>;
  /** Load the views for this context. */
  load: () => Promise<void>;
  /** Activate a view: apply its snapshot to the page + mark it active. */
  applyTab: (tab: FilterTab) => void;
  /** Deactivate (no active view). */
  clearActive: () => void;
  /** Overwrite the active view with the current snapshot ("Save"). */
  saveActive: () => Promise<FilterTab>;
  /** Create a new view from the current snapshot ("Save as…"). */
  saveAs: (name: string, icon: string | null) => Promise<FilterTab>;
  /** Restore one `tab-disabled` chip from the active snapshot. */
  restoreFilter: (key: string) => void;
  /**
   * Annotate a page-built `ActiveFilter[]` with per-chip `tabState`. With no
   * active view the list is returned UNCHANGED (chips render like today).
   * Also injects the snapshot-only (`tab-disabled`) chips that have no current
   * value, so the user can see + restore them.
   */
  decorateActiveFilters: (activeFilters: ActiveFilter[]) => ActiveFilter[];
}

export function useFilterTabs(
  context: string,
  adapters: UseFilterTabsAdapters,
): UseFilterTabsReturn {
  const store = useFilterTabsStore();

  const tabs = computed<FilterTab[]>(() => store.byContext(context));
  const loading = computed<boolean>(() => store.isLoading(context));
  const loadError = computed<boolean>(() => store.hasError(context));

  const activeTabId = ref<string | number | null>(null);
  const activeTab = computed<FilterTab | null>(
    () => tabs.value.find((t) => String(t.id) === String(activeTabId.value)) ?? null,
  );

  // Canonical key SETS for the active snapshot + the live state. Comparing sets
  // (not arrays) makes ordering irrelevant → reordering never reads as dirty.
  const snapshotKeys = computed<Set<string>>(() => {
    const tab = activeTab.value;
    if (!tab) return new Set();
    return new Set(adapters.normalize(tab.filters ?? {}));
  });
  const currentKeys = computed<Set<string>>(
    () => new Set(adapters.normalize(adapters.serialize())),
  );

  const dirty = computed<boolean>(() => {
    if (!activeTab.value) return false;
    const a = snapshotKeys.value;
    const b = currentKeys.value;
    if (a.size !== b.size) return true;
    for (const k of a) if (!b.has(k)) return true;
    return false;
  });

  function tabStateFor(key: string): FilterTabState | undefined {
    if (!activeTab.value) return undefined;
    const inSnapshot = snapshotKeys.value.has(key);
    const inCurrent = currentKeys.value.has(key);
    if (inSnapshot && inCurrent) return 'tab-active';
    if (inCurrent) return 'extra';
    if (inSnapshot) return 'tab-disabled';
    return undefined;
  }

  async function load(): Promise<void> {
    try {
      await store.fetch(context);
    } catch {
      /* error flag is set on the store; surfaced via `loadError` */
    }
  }

  function applyTab(tab: FilterTab): void {
    adapters.apply(tab.filters ?? {});
    activeTabId.value = tab.id;
  }

  function clearActive(): void {
    activeTabId.value = null;
  }

  async function saveActive(): Promise<FilterTab> {
    if (!activeTab.value) throw new Error('No active view to save.');
    const updated = await store.update(context, activeTab.value.id, {
      filters: adapters.serialize(),
    });
    activeTabId.value = updated.id;
    return updated;
  }

  async function saveAs(name: string, icon: string | null): Promise<FilterTab> {
    const created = await store.create(context, {
      name,
      icon,
      filters: adapters.serialize(),
    });
    activeTabId.value = created.id;
    return created;
  }

  function restoreFilter(key: string): void {
    if (!activeTab.value) return;
    adapters.restoreValue(key, activeTab.value.filters ?? {});
  }

  function decorateActiveFilters(activeFilters: ActiveFilter[]): ActiveFilter[] {
    if (!activeTab.value) return activeFilters;

    // 1. Annotate the page's chips with their trit-state.
    const decorated: ActiveFilter[] = activeFilters.map((f) => {
      if (f.values?.length) {
        const values: ActiveFilterValue[] = f.values.map((v) => ({
          ...v,
          tabState: tabStateFor(v.key),
        }));
        return { ...f, values };
      }
      return { ...f, tabState: tabStateFor(f.key) };
    });

    // 2. Inject snapshot-only keys (in the view, removed from the current state)
    //    as `tab-disabled` chips so they're visible + restorable. They carry the
    //    raw key as a label fallback — the page owns nicer labels, but a removed
    //    value has no live option to read a name from, so the key is the honest
    //    minimum. (Most disabled chips are scalars that the page still labels via
    //    its own activeFilters; only fully-removed multi-values fall back here.)
    const presentKeys = new Set<string>();
    for (const f of decorated) {
      if (f.values?.length) f.values.forEach((v) => presentKeys.add(v.key));
      else presentKeys.add(f.key);
    }
    const missing = [...snapshotKeys.value].filter((k) => !presentKeys.has(k));
    for (const key of missing) {
      decorated.push({ key, label: key, tabState: 'tab-disabled' });
    }

    return decorated;
  }

  return {
    tabs,
    activeTabId,
    activeTab,
    dirty,
    loading,
    loadError,
    load,
    applyTab,
    clearActive,
    saveActive,
    saveAs,
    restoreFilter,
    decorateActiveFilters,
  };
}
