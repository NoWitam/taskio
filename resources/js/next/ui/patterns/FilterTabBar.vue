<script setup lang="ts">
// FilterTabBar — the "saved views" toolbar that sits ABOVE FilterBar (FilterTabs
// Stage 2). A `role="toolbar"` strip of view pills with: an active highlight, a
// "modified" badge when the active view is dirty, a per-view kebab menu
// (edit / move up / move down / delete), and trailing Save / "Save as…" actions.
//
// Distinct from Tabs.vue (which is a `role="tablist"` for Active/Archive/Trash):
// this is a toolbar of saved filter sets, NOT a panel switcher. The active pill
// uses `aria-current="true"` (never `aria-selected`).
//
// Reorder is keyboard-first via the menu (move up/down) — no drag handle (a
// follow-up). States: empty (prompt + Save as), loading (pill skeletons), error
// (inline retry). Mutations report through the parent (toasts live there); this
// component is presentational + emits intent.
//
// R2: switching to ANOTHER view while the current one is dirty opens a discard
// ConfirmDialog. "Clear all" (handled by FilterBar) is NOT guarded.
import { computed, ref } from 'vue';
import Button from '../primitives/Button.vue';
import Badge from '../primitives/Badge.vue';
import Icon from '../primitives/Icon.vue';
import DropdownMenu from '../overlay/DropdownMenu.vue';
import DropdownMenuItem from '../overlay/DropdownMenuItem.vue';
import ConfirmDialog from '../overlay/ConfirmDialog.vue';
import { resolveLabelIcon } from '../forms/labelIcon';
import type { IconName } from '../primitives/icons';
import { useI18n } from '../../app/i18n';
import type { FilterTab } from '../../app/stores/filterTabs';

const props = withDefaults(
  defineProps<{
    tabs: FilterTab[];
    activeTabId: string | number | null;
    dirty?: boolean;
    loading?: boolean;
    error?: boolean;
    /** Whether the current filter state has anything worth saving. */
    hasActiveFilters?: boolean;
    /** Disable Save/Save-as while a mutation is in flight (optional). */
    busy?: boolean;
  }>(),
  {
    dirty: false,
    loading: false,
    error: false,
    hasActiveFilters: false,
    busy: false,
  },
);

const emit = defineEmits<{
  (e: 'activate', tab: FilterTab): void;
  /** Leave saved-view mode (no view selected); current filters are kept. */
  (e: 'deactivate'): void;
  (e: 'save'): void;
  (e: 'save-as'): void;
  (e: 'edit', tab: FilterTab): void;
  (e: 'delete', tab: FilterTab): void;
  (e: 'move-up', tab: FilterTab): void;
  (e: 'move-down', tab: FilterTab): void;
  (e: 'retry'): void;
}>();

const { t } = useI18n();

function isActive(tab: FilterTab): boolean {
  return String(tab.id) === String(props.activeTabId);
}
function iconFor(tab: FilterTab): IconName | null {
  return tab.icon ? resolveLabelIcon(tab.icon) : null;
}
function indexOf(tab: FilterTab): number {
  return props.tabs.findIndex((t) => String(t.id) === String(tab.id));
}
function isFirst(tab: FilterTab): boolean {
  return indexOf(tab) === 0;
}
function isLast(tab: FilterTab): boolean {
  return indexOf(tab) === props.tabs.length - 1;
}

// --- Discard-on-switch guard (R2) -----------------------------------------
const discardOpen = ref(false);
const pendingTab = ref<FilterTab | null>(null);

function onActivate(tab: FilterTab): void {
  if (isActive(tab)) return;
  if (props.dirty) {
    pendingTab.value = tab;
    discardOpen.value = true;
    return;
  }
  emit('activate', tab);
}
// Leaving view mode keeps the current filters (nothing is lost), so it needs no
// discard guard — unlike switching to another view.
function onSelectNone(): void {
  if (!props.activeTabId) return;
  emit('deactivate');
}
function confirmDiscard(): void {
  if (pendingTab.value) emit('activate', pendingTab.value);
  pendingTab.value = null;
  discardOpen.value = false;
}
function cancelDiscard(): void {
  pendingTab.value = null;
  discardOpen.value = false;
}

const activeTab = computed<FilterTab | null>(
  () => props.tabs.find((tab) => isActive(tab)) ?? null,
);
const discardMessage = computed(() =>
  t('tasks.savedViews.confirm.discardMessage', '', { name: activeTab.value?.name ?? '' }),
);

const showSave = computed(() => !!props.activeTabId && props.dirty);
const saveAsDisabled = computed(() => !props.hasActiveFilters || props.busy);
</script>

<template>
  <section
    role="toolbar"
    :aria-label="t('tasks.savedViews.barLabel')"
    class="next-saved-views flex flex-wrap items-center gap-next-2"
  >
    <!-- Section label -->
    <span class="flex items-center gap-next-1_5 pr-next-1 text-next-sm font-next-medium text-next-muted-foreground">
      <Icon name="bookmark" class="shrink-0" aria-hidden="true" />
      <span>{{ t('tasks.savedViews.sectionLabel') }}</span>
    </span>

    <!-- Loading: a few pill-shaped skeletons of varied width. -->
    <template v-if="loading">
      <div class="flex items-center gap-next-2" aria-hidden="true">
        <span
          v-for="(w, n) in ['7rem', '5.5rem', '8rem', '6rem']"
          :key="n"
          class="h-8 animate-pulse rounded-next-full bg-next-muted"
          :style="{ width: w }"
        />
      </div>
      <span class="sr-only">{{ t('tasks.savedViews.loading') }}</span>
    </template>

    <!-- Error: inline message + retry (does not block the rest of the page). -->
    <template v-else-if="error">
      <span class="flex items-center gap-next-1_5 text-next-sm text-next-danger">
        <Icon name="alert-triangle" aria-hidden="true" />
        {{ t('tasks.savedViews.loadError') }}
      </span>
      <Button size="sm" variant="ghost" leading-icon="rotate-ccw" @click="emit('retry')">
        {{ t('common.retry') }}
      </Button>
    </template>

    <!-- Empty: a short prompt; Save as is disabled until there are filters. -->
    <template v-else-if="!tabs.length">
      <span class="text-next-sm text-next-muted-foreground">
        {{ t('tasks.savedViews.empty') }}
      </span>
    </template>

    <!-- Pills -->
    <template v-else>
      <!-- "No view" — a radio-like default pill, highlighted when no view is
           selected. Clicking it leaves view mode but KEEPS the current filters
           (use the bar's "Clear all" to reset filters). -->
      <button
        type="button"
        class="next-view-pill inline-flex h-8 items-center rounded-next-full px-next-3 text-next-sm outline-none transition-colors duration-[var(--duration-next-fast)] focus-visible:ring-2 focus-visible:ring-next-ring"
        :class="!activeTabId
          ? 'bg-next-primary text-next-primary-foreground font-next-semibold shadow-next-xs'
          : 'bg-next-muted text-next-muted-foreground hover:bg-next-accent hover:text-next-fg'"
        :aria-current="!activeTabId ? 'true' : undefined"
        :aria-label="t('tasks.savedViews.activateNone')"
        @click="onSelectNone"
      >
        {{ t('tasks.savedViews.noView') }}
      </button>

      <div
        v-for="tab in tabs"
        :key="tab.id"
        class="next-view-pill group inline-flex items-center gap-next-1 rounded-next-full h-8 pl-next-2 pr-next-1 text-next-sm transition-colors duration-[var(--duration-next-fast)]"
        :class="isActive(tab)
          ? 'bg-next-primary text-next-primary-foreground font-next-semibold shadow-next-xs'
          : 'bg-next-muted text-next-muted-foreground hover:bg-next-accent hover:text-next-fg'"
      >
        <!-- Clickable body = activate -->
        <button
          type="button"
          class="inline-flex min-w-0 items-center gap-next-1_5 rounded-next-full outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
          :aria-current="isActive(tab) ? 'true' : undefined"
          :aria-label="t('tasks.savedViews.activate', '', { name: tab.name })"
          @click="onActivate(tab)"
        >
          <Icon v-if="iconFor(tab)" :name="iconFor(tab)!" class="shrink-0" aria-hidden="true" />
          <span class="max-w-[14ch] truncate">{{ tab.name }}</span>
        </button>

        <!-- Conditional dirty badge (BEFORE the permanent kebab) -->
        <Badge
          v-if="isActive(tab) && dirty"
          variant="warning"
          tone="subtle"
          size="sm"
          icon="pencil"
          :aria-label="t('tasks.savedViews.dirtyAria', '', { name: tab.name })"
        >
          {{ t('tasks.savedViews.dirtyBadge') }}
        </Badge>

        <!-- Permanent kebab menu -->
        <DropdownMenu
          placement="bottom-end"
          :aria-label="t('tasks.savedViews.menuAria', '', { name: tab.name })"
        >
          <template #trigger="{ props: triggerProps }">
            <Button
              size="icon-xs"
              variant="ghost"
              leading-icon="more-vertical"
              v-bind="triggerProps"
              :aria-label="t('tasks.savedViews.menuAria', '', { name: tab.name })"
            />
          </template>

          <DropdownMenuItem icon="pencil" @select="emit('edit', tab)">
            {{ t('tasks.savedViews.menu.edit') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="chevron-up"
            :disabled="isFirst(tab)"
            @select="emit('move-up', tab)"
          >
            {{ t('tasks.savedViews.menu.moveUp') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            icon="chevron-down"
            :disabled="isLast(tab)"
            @select="emit('move-down', tab)"
          >
            {{ t('tasks.savedViews.menu.moveDown') }}
          </DropdownMenuItem>
          <DropdownMenuItem icon="trash" destructive @select="emit('delete', tab)">
            {{ t('tasks.savedViews.menu.delete') }}
          </DropdownMenuItem>
        </DropdownMenu>
      </div>
    </template>

    <!-- Trailing actions: Save (overwrite active, when dirty) + Save as… -->
    <div v-if="!loading && !error" class="ml-auto flex items-center gap-next-2">
      <Button
        v-if="showSave"
        size="sm"
        variant="primary"
        leading-icon="check"
        :loading="busy"
        @click="emit('save')"
      >
        {{ t('tasks.savedViews.save') }}
      </Button>
      <Button
        size="sm"
        variant="outline"
        leading-icon="plus"
        :disabled="saveAsDisabled"
        :title="saveAsDisabled ? t('tasks.savedViews.saveAsDisabledHint') : undefined"
        @click="emit('save-as')"
      >
        {{ t('tasks.savedViews.saveAs') }}
      </Button>
    </div>

    <!-- Discard-on-switch confirmation (R2) -->
    <ConfirmDialog
      v-model:open="discardOpen"
      :title="t('tasks.savedViews.confirm.discardTitle')"
      :message="discardMessage"
      :confirm-label="t('tasks.savedViews.confirm.discardConfirm')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmDiscard"
      @cancel="cancelDiscard"
    />
  </section>
</template>
