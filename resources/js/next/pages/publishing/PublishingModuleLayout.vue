<script setup lang="ts">
// PublishingModuleLayout — the Publishing (R4) module shell.
//
// Two PERMANENT screens with genuinely different lifecycles, which is exactly the shape
// `ModuleAside` exists for: the publications stream (grows daily, filtered, paginated) and
// Connections (a handful of accounts, no pagination, repaired once a quarter). Precedent:
// Bots, Workflows, Forms, Knowledge.
//
// Connections is a TRUE ROUTE and not a tab, because `config/publishing.php` →
// `oauth.return_path` is pinned to `/next/publishing/connections`: the OAuth callback
// redirects a whole browser there, and a tab state is not an address.
//
// The shell HOSTS the composer drawer as a query key (`?new=1` / `?edit=<id>`) so the list
// and the detail share one host — one component, two entrances — and the Back button closes
// it. The same arrangement `WorkflowsModuleLayout` uses for `?workflow=`.
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ModuleAside, { type ModuleNavItem } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import PublicationEditorDrawer from './PublicationEditorDrawer.vue';
import { usePublishingStore } from '../../app/stores/publishing';
import { usePublishingConnectionsStore } from '../../app/stores/publishingConnections';
import { useAuthStore } from '../../app/stores/auth';
import { useI18n } from '../../app/i18n';
import { setPageContextLabel } from '../../app/lib/pageContext';
import { isPathActive } from '../../app/router/isPathActive';
import type { Publication } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = usePublishingStore();
const connections = usePublishingConnectionsStore();
const auth = useAuthStore();

// The accounts are fetched HERE, not only on the Connections screen: the badge below is the
// only way a broken account is visible while somebody is looking at publications. The list
// is unpaginated and tiny, and the store's own guard makes this ONE request per module visit
// however many screens ask for it on the way in.
function loadModuleContext(): void {
  void connections.fetchConnections().catch(() => undefined);
  void store.loadTimezone().catch(() => undefined);
}

onMounted(loadModuleContext);

// --- Navigation -------------------------------------------------------------
const moduleItems = computed<ModuleNavItem[]>(() => [
  {
    key: 'publications',
    label: t('publishing.nav.publications'),
    icon: 'send',
    to: '/publishing/publications',
  },
  {
    key: 'connections',
    label: t('publishing.nav.connections'),
    icon: 'link-2',
    to: '/publishing/connections',
    // Hidden at zero. `needsAttentionCount` also counts unreadable credentials, which the
    // server's own `needs_attention` does not — an account whose stored access cannot be
    // decrypted publishes nothing, and that has to be visible from here.
    badge: connections.needsAttentionCount > 0 ? connections.needsAttentionCount : undefined,
    badgeVariant: 'danger',
    badgeLabel: t('publishing.module.connectionsBadge', '', {
      count: connections.needsAttentionCount,
    }),
  },
]);

function isItemActive(item: ModuleNavItem): boolean {
  return typeof item.to === 'string' ? isPathActive(route.path, item.to) : false;
}

// --- The open publication feeds the navbar breadcrumb -----------------------
const publicationId = computed(() =>
  String(route.name ?? '').startsWith('next.publishing.publication.') && route.params.id
    ? String(route.params.id)
    : null,
);

watch(
  () => (publicationId.value && store.detail?.id === publicationId.value ? store.detail.title : null),
  (title) => setPageContextLabel(title),
  { immediate: true },
);
// Synchronous, BEFORE the incoming layout's setup — `onUnmounted` is post-flush and would
// wipe the label the next module layout has already set.
onBeforeUnmount(() => setPageContextLabel(null));

// --- The composer drawer (query-hosted) -------------------------------------
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));

const isNew = computed(() => str(route.query.new) === '1');
const editId = computed<string | null>(() => str(route.query.edit) || null);

const editorOpen = computed<boolean>({
  get: () => isNew.value || editId.value !== null,
  set: (open) => {
    if (open) return;
    const query = { ...route.query };
    delete query.new;
    delete query.edit;
    void router.replace({ query });
  },
});

function onSaved(publication: Publication): void {
  // The store already reconciled the list and the detail; the drawer only has to close.
  editorOpen.value = false;
  if (publicationId.value === publication.id) setPageContextLabel(publication.title);
}

// --- Switching workspace ----------------------------------------------------
/**
 * SWITCHING WORKSPACE DOES NOT NAVIGATE — `auth.setCurrentWorkspace()` swaps the context and
 * refetches `/auth/me`, and this layout stays mounted with every cached row still on screen.
 * Publications, accounts, counts and THE CLOCK are all per workspace, so all of it is dropped
 * here and loaded again for the new one.
 *
 * The clock is the entry that matters: `timezone` is latched (loaded once per module visit),
 * so a layout that only cleared the lists would go on reading every moment in this module on
 * the PREVIOUS workspace's zone — silently, with times that look perfectly plausible.
 *
 * The `:key` on `<RouterView>` is the other half: a reset without a remount would leave the
 * list screen showing "Nothing here yet" for a workspace nobody has asked about yet. And the
 * composer closes, because the row it is editing belongs to the workspace we just left.
 */
watch(
  () => auth.currentWorkspaceId,
  (next, previous) => {
    if (next === previous) return;
    if (editorOpen.value) editorOpen.value = false;
    store.resetAll();
    connections.resetAll();
    loadModuleContext();
  },
);
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- `module-icon="send"` renders WHITE-ON-PRIMARY from the component's own tokens.
         Nothing is passed to tint or mute it — that is the house rule for a module's icon
         on its own page, and dark mode is already handled by the token swap. -->
    <ModuleAside
      module-icon="send"
      :module-title="t('publishing.title')"
      :module-hint="t('publishing.subtitle')"
      :module-items="moduleItems"
      :active-match="isItemActive"
    />

    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs :items="moduleItems" :active-match="isItemActive" />
      <!-- Keyed by the workspace: a switch re-mounts the routed screen, so it asks for ITS
           workspace's data instead of sitting on an emptied store. See the watcher above. -->
      <RouterView :key="auth.currentWorkspaceId ?? 'none'" />
    </div>

    <!-- The composer. Full-height below `next-md` (`size="full"`), a right-hand sheet above.
         Keyed by the record so switching rows re-seeds the form instead of carrying one
         publication's body into another's. -->
    <Drawer
      v-model:open="editorOpen"
      side="right"
      size="lg"
      :scroll-body="false"
      :show-close="false"
      :padded="false"
      :aria-label="editId ? t('publishing.editor.editTitle') : t('publishing.editor.createTitle')"
    >
      <PublicationEditorDrawer
        :key="editId ?? 'new'"
        :publication-id="editId"
        @close="editorOpen = false"
        @saved="onSaved"
      />
    </Drawer>
  </div>
</template>
