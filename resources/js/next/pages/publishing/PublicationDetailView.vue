<script setup lang="ts">
// PublicationDetailView — one publication, in full (§7).
//
// A ROUTE, not a drawer. The detail needs a stable URL: it is reached from the Calendar
// (publications are its fifth source), from a notification, from a support ticket. A panel
// that the Back button closes is not a place to read whether a post went out into the world.
//
// The screen is the SKELETON; the state band (§7.2) and the `needs_reconcile` panel (§8)
// carry what is different about each status. Below `next-lg` the columns stack with
// DELIVERY ABOVE CONTENT: on a narrow screen the first question is "what is happening to
// this", not "what does it say".
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Card from '../../ui/layout/Card.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import DescriptionList, { type DescriptionItem } from '../../ui/data/DescriptionList.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import PublicationStatusBand from './PublicationStatusBand.vue';
import ReconcilePanel from './ReconcilePanel.vue';
import PublicationApprovalPanel from './PublicationApprovalPanel.vue';
import PublicationMediaField from './PublicationMediaField.vue';
import SchedulePublicationModal from './SchedulePublicationModal.vue';
import { usePublishingStore } from '../../app/stores/publishing';
import { usePublishingConnectionsStore } from '../../app/stores/publishingConnections';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import { platformIcon, statusIcon, toneToVariant } from './publishingMeta';
import { deleteCopyKind } from './publicationActions';
import { serverMessageOf } from './publishingErrors';
import { formatInstantLong } from './publishingTime';
import type { Publication } from './types';

/** `publishing` can last up to fifteen minutes; a page left open overnight must not poll. */
const POLL_INTERVAL_MS = 15000;
const POLL_MAX = 20;

const { t, currentLocale } = useI18n();
const route = useRoute();
const router = useRouter();
const store = usePublishingStore();
const connections = usePublishingConnectionsStore();
const toast = useToast();
const confirm = useConfirm();

const id = computed(() => String(route.params.id ?? ''));
const publication = computed<Publication | null>(() =>
  store.detail?.id === id.value ? store.detail : null,
);

const section = computed(() =>
  String(route.name ?? '').endsWith('.approval') ? 'approval' : 'overview',
);

const scheduleOpen = ref(false);

// --- Load -------------------------------------------------------------------
async function load(): Promise<void> {
  try {
    await store.fetchPublication(id.value);
  } catch {
    // The store keeps the status + message; the template decides what to say about it.
  }
}

watch(id, () => void load(), { immediate: true });

// --- Polling while `publishing` ---------------------------------------------
let pollTimer: ReturnType<typeof setInterval> | null = null;
const pollCount = ref(0);
const pollExhausted = ref(false);

function stopPolling(): void {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

function startPolling(): void {
  if (pollTimer) return;
  pollCount.value = 0;
  pollExhausted.value = false;
  pollTimer = setInterval(() => {
    pollCount.value += 1;
    if (pollCount.value > POLL_MAX) {
      // FIVE MINUTES, then stop and SAY SO. A screen that polls forever is a screen that
      // asks 5 760 times overnight for an answer nobody is reading.
      pollExhausted.value = true;
      stopPolling();
      return;
    }
    void load();
  }, POLL_INTERVAL_MS);
}

watch(
  () => publication.value?.status,
  (status) => {
    if (status === 'publishing') startPolling();
    else stopPolling();
  },
  { immediate: true },
);

onMounted(() => {
  void connections.fetchConnections().catch(() => undefined);
  void store.loadTimezone().catch(() => undefined);
});
onBeforeUnmount(stopPolling);

// --- Derived ----------------------------------------------------------------
const at = (iso: string | null): string =>
  formatInstantLong(iso, store.timezone, currentLocale.value);

/** The account this goes out on, joined client-side from the connections list. */
const connection = computed(() =>
  connections.find(publication.value?.platform_connection_id ?? null),
);

/**
 * The account could not be looked up. NEVER rendered as "no account": one says our request
 * failed, the other says nobody chose one, and only one of them is a thing to fix.
 */
const connectionUnknown = computed(
  () =>
    !!publication.value?.platform_connection_id &&
    !connection.value &&
    (connections.errored || !connections.loaded),
);

/**
 * THE ACCOUNT WAS DISCONNECTED — a third answer, and the one this screen exists for.
 *
 * `PlatformConnectionService::index()` queries WITHOUT `withTrashed()`, and disconnecting
 * sets the status to `revoked` AND soft deletes the row. So a publication that names an
 * account which the loaded, un-errored connections list does not contain is not a publication
 * with no account: it is a publication whose account is gone — which is exactly why it is
 * `blocked` and why somebody is reading this screen. "Not chosen" here would send them to
 * the composer to pick an account that is not in the list either.
 */
const connectionDisconnected = computed(
  () =>
    !!publication.value?.platform_connection_id &&
    !connection.value &&
    !connectionUnknown.value,
);

/**
 * The Delivery rows, in a fixed order, with the ones that have nothing to say LEFT OUT
 * rather than dashed. A dash is an answer ("none"); an absent attempt count is not.
 */
const shippingItems = computed<DescriptionItem[]>(() => {
  const p = publication.value;
  if (!p) return [];
  const items: DescriptionItem[] = [
    { key: 'platform', label: t('publishing.detail.fields.platform') },
    { key: 'connection', label: t('publishing.detail.fields.connection') },
  ];
  if (p.scheduled_at) {
    items.push({ key: 'scheduled_at', label: t('publishing.detail.fields.scheduled_at') });
  }
  if (p.published_at) {
    items.push({
      key: 'published_at',
      label: t('publishing.detail.fields.published_at'),
      value: at(p.published_at),
    });
  }
  if (p.attempts > 0) {
    items.push({ key: 'attempts', label: t('publishing.detail.fields.attempts'), value: p.attempts });
  }
  if (p.last_attempt_at) {
    items.push({
      key: 'last_attempt_at',
      label: t('publishing.detail.fields.last_attempt_at'),
      value: at(p.last_attempt_at),
    });
  }
  if (p.remote_id) {
    items.push({ key: 'remote_id', label: t('publishing.detail.fields.remote_id') });
  }
  items.push(
    { key: 'creator', label: t('publishing.detail.fields.creator') },
    { key: 'created_at', label: t('publishing.detail.fields.created_at'), value: at(p.created_at) },
    { key: 'updated_at', label: t('publishing.detail.fields.updated_at'), value: at(p.updated_at) },
  );
  return items;
});

const tabItems = computed<TabItem<string>[]>(() => [
  { value: 'overview', label: t('publishing.detail.tabOverview'), icon: 'layout-dashboard' },
  { value: 'approval', label: t('publishing.detail.tabApproval'), icon: 'git-branch' },
]);

function onTab(value: string): void {
  void router.push({
    name: `next.publishing.publication.${value}`,
    params: { id: id.value },
    query: route.query,
  });
}

// --- Actions ----------------------------------------------------------------
function onEdit(): void {
  void router.push({ query: { ...route.query, edit: id.value } });
}

async function onDelete(): Promise<void> {
  const record = publication.value;
  if (!record) return;
  const kind = deleteCopyKind(record);
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
    await store.deletePublication(record.id);
    toast.success(
      kind === 'published' ? t('publishing.toasts.deletedPublished') : t('publishing.toasts.deleted'),
    );
    void router.push({ name: 'next.publishing.publications' });
  } catch (err) {
    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
  }
}

const copied = ref(false);
async function copyRemoteId(): Promise<void> {
  const value = publication.value?.remote_id;
  if (!value) return;
  try {
    await navigator.clipboard.writeText(value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
  } catch {
    // Clipboard access can be refused; the id is on screen and selectable regardless.
  }
}
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <!-- 404 on a uuid is a CONCRETE message, not "something went wrong". -->
    <EmptyState
      v-if="store.detailStatus === 404"
      variant="error"
      role="alert"
      :title="t('publishing.errors.notFoundTitle')"
      :description="t('publishing.errors.notFoundDescription')"
    >
      <template #action>
        <Button
          variant="outline"
          size="sm"
          leading-icon="arrow-left"
          @click="router.push({ name: 'next.publishing.publications' })"
        >
          {{ t('publishing.actions.backToList') }}
        </Button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="store.detailStatus === 403"
      variant="error"
      role="alert"
      :title="t('publishing.errors.forbidden')"
      :description="store.detailError ?? ''"
    />

    <!-- Loading: the band as a full-width block, then the two columns SHAPED LIKE WHAT
         ARRIVES (§12.1) — never a spinner, and never two grey rectangles: a placeholder that
         does not mimic its element teaches nothing about what is coming. One `label` on the
         first shape announces the whole region once, politely. -->
    <div v-else-if="store.detailLoading && !publication" class="flex flex-col gap-next-4">
      <Skeleton variant="rect" height="5rem" :label="t('common.loading')" />
      <div class="grid gap-next-4 next-lg:grid-cols-3">
        <!-- Delivery: eight label/value pairs, the shape `DescriptionList` will fill. Same
             `order` classes as the real cards, so nothing jumps sideways when they land. -->
        <Card class="next-lg:order-2">
          <div class="flex flex-col gap-next-3">
            <div v-for="n in 8" :key="`ship-${n}`" class="flex items-center gap-next-3">
              <Skeleton variant="text" width="30%" />
              <Skeleton variant="text" width="50%" />
            </div>
          </div>
        </Card>
        <!-- Content: a heading and six lines of body text. -->
        <Card class="next-lg:order-1 next-lg:col-span-2">
          <Skeleton variant="text" width="40%" />
          <Skeleton variant="text" class="mt-next-3" :count="6" />
        </Card>
      </div>
    </div>

    <template v-else-if="publication">
      <PageHeader
        :title="publication.title"
        :breadcrumbs="[
          { label: t('publishing.title'), to: { name: 'next.publishing.publications' } },
          { label: publication.title },
        ]"
      >
        <template #leading>
          <span
            class="flex h-10 w-10 items-center justify-center rounded-next-lg border border-next-border bg-next-muted text-next-muted-foreground"
            aria-hidden="true"
          >
            <Icon :name="platformIcon(publication.platform)" class="text-next-lg" />
          </span>
        </template>

        <template #meta>
          <Badge
            :variant="toneToVariant(publication.status_tone)"
            tone="subtle"
            size="sm"
            :icon="statusIcon(publication.status)"
          >
            {{ publication.status_label }}
          </Badge>
        </template>

        <template #tabs>
          <Tabs
            :model-value="section"
            :items="tabItems"
            variant="underline"
            size="sm"
            :aria-label="t('publishing.module.resourceNav')"
            @update:model-value="onTab"
          />
        </template>
      </PageHeader>

      <template v-if="section === 'overview'">
        <!-- `needs_reconcile` replaces the band with a panel of its own. -->
        <ReconcilePanel
          v-if="publication.status === 'needs_reconcile'"
          :publication="publication"
          :timezone="store.timezone"
          @updated="(p) => store.upsertIntoList(p)"
        />
        <PublicationStatusBand
          v-else
          :publication="publication"
          :timezone="store.timezone"
          :connections="connections.connections"
          @schedule="scheduleOpen = true"
          @edit="onEdit"
          @delete="onDelete"
          @connections="router.push({ name: 'next.publishing.connections' })"
          @approval="onTab('approval')"
        />

        <!-- Polling is announced rather than silent: a screen that changes by itself and
             never said it would is a screen people stop trusting. -->
        <p v-if="publication.status === 'publishing'" class="text-next-xs text-next-muted-foreground">
          {{ t('publishing.detail.polling') }}
        </p>
        <Alert v-if="pollExhausted" variant="info" size="sm">
          {{ t('publishing.detail.pollingStopped') }}
        </Alert>

        <div class="grid gap-next-4 next-lg:grid-cols-3">
          <!-- DELIVERY FIRST in source order, so it lands on top when the columns stack. -->
          <Card class="next-lg:order-2">
            <h2 class="mb-next-3 text-next-sm font-next-semibold text-next-fg">
              {{ t('publishing.detail.shippingCard') }}
            </h2>
            <!-- The house key→value component, with `#value-<key>` slots wherever the value
                 is a badge, a link or an id with a copy button. -->
            <DescriptionList :items="shippingItems" layout="horizontal" size="sm">
              <template #value-platform>
                <span class="flex items-center gap-next-1">
                  <Icon :name="platformIcon(publication.platform)" class="text-next-xs" aria-hidden="true" />
                  {{ publication.platform_label }}
                  <Badge v-if="!publication.publishes_publicly" variant="neutral" tone="subtle" size="sm">
                    {{ t('publishing.rehearsal') }}
                  </Badge>
                </span>
              </template>

              <template #value-connection>
                <span v-if="connection" class="flex min-w-0 items-center gap-next-1">
                  <span class="truncate">{{ connection.account_name }}</span>
                  <Badge :variant="toneToVariant(connection.status_tone)" tone="subtle" size="sm">
                    {{ connection.status_label }}
                  </Badge>
                </span>
                <!-- The lookup failed — say THAT, never "no account": one means our request
                     did not come back, the other means nobody chose one, and only the second
                     is a thing to go and fix. -->
                <span v-else-if="connectionUnknown" class="text-next-muted-foreground">
                  {{ t('publishing.errors.connectionUnknown') }}
                </span>
                <!-- The row names an account the list does not contain: it was DISCONNECTED
                     (the index never returns soft-deleted connections). Its own sentence,
                     because this is the screen somebody opens to find out why a publication
                     is on hold — and "not chosen" would send them to pick from a list that
                     no longer has it. -->
                <Badge
                  v-else-if="connectionDisconnected"
                  variant="warning"
                  tone="subtle"
                  size="sm"
                  icon="unlink"
                >
                  {{ t('publishing.detail.fields.connectionDisconnected') }}
                </Badge>
                <!-- Nobody has chosen an account at all (`platform_connection_id === null`). -->
                <Badge
                  v-else-if="publication.publishes_publicly"
                  variant="warning"
                  tone="subtle"
                  size="sm"
                >
                  {{ t('publishing.detail.fields.noConnection') }}
                </Badge>
                <span v-else>—</span>
              </template>

              <!-- Both moments stand together HERE and only here: on the detail their
                   difference is information (reconciliation can pull them hours apart). -->
              <template #value-scheduled_at>
                <span>
                  {{ at(publication.scheduled_at) }}
                  <span v-if="publication.status === 'draft'" class="text-next-muted-foreground">
                    {{ t('publishing.detail.fields.unarmed') }}
                  </span>
                </span>
              </template>

              <!-- The proof a post exists. `break-all` because an id has no spaces to wrap on. -->
              <template #value-remote_id>
                <span class="flex min-w-0 items-center gap-next-1">
                  <span class="min-w-0 break-all font-next-mono text-next-2xs">
                    {{ publication.remote_id }}
                  </span>
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    :leading-icon="copied ? 'check' : 'copy'"
                    :aria-label="t('publishing.detail.copyRemoteId')"
                    @click="copyRemoteId"
                  />
                </span>
              </template>

              <template #value-creator>
                <CreatorBadge :creator="publication.creator ?? null" size="xs" />
              </template>
            </DescriptionList>
          </Card>

          <Card class="next-lg:order-1 next-lg:col-span-2">
            <h2 class="mb-next-3 text-next-sm font-next-semibold text-next-fg">
              {{ t('publishing.detail.contentCard') }}
            </h2>
            <!-- `whitespace-pre-wrap`: the body is plain text and its line breaks ARE the
                 formatting the platform will honour. -->
            <p
              v-if="publication.body"
              class="whitespace-pre-wrap text-next-sm text-next-fg"
            >{{ publication.body }}</p>
            <p v-else class="text-next-sm text-next-muted-foreground">
              {{ t('publishing.detail.noBody') }}
            </p>

            <div v-if="publication.media.length" class="mt-next-4">
              <!-- Read-only here: the ordering controls belong to the composer. -->
              <PublicationMediaField :model-value="publication.media" disabled />
            </div>
          </Card>
        </div>
      </template>

      <PublicationApprovalPanel v-else :publication="publication" />

      <SchedulePublicationModal
        v-if="scheduleOpen"
        :publication="publication"
        :timezone="store.timezone"
        @close="scheduleOpen = false"
        @done="scheduleOpen = false"
      />
    </template>

    <EmptyState
      v-else
      variant="error"
      role="alert"
      :title="t('publishing.errors.loadTitle')"
      :description="store.detailError ?? t('publishing.errors.loadDescription')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
          {{ t('publishing.actions.retry') }}
        </Button>
      </template>
    </EmptyState>
  </div>
</template>
