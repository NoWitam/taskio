<script setup lang="ts">
// ConnectionsView — the accounts this workspace can publish to (§9, §10).
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// THIS PATH IS PINNED IN SERVER CONFIGURATION
// ═════════════════════════════════════════════════════════════════════════════════════════
// `config/publishing.php` → `oauth.return_path` defaults to `/next/publishing/connections`,
// and the callback redirects a whole browser THERE. Moving this route without changing that
// config means a SUCCESSFUL connect ends on the not-found screen — which is precisely the
// state the configuration comment describes today.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// THE NAMED EXCEPTION TO "EVERY LIST SCREEN GETS A FILTERBAR + SAVED VIEWS"
// ═════════════════════════════════════════════════════════════════════════════════════════
// This is not a list screen in that sense: the response is unpaginated, the set of
// destinations is closed and known in advance, and the only filter the API offers
// (`platform[]`) is replaced by the layout itself — a card per destination. A saved-view pill
// over three cards would satisfy the letter of the rule and defeat its reason. The exception
// is named here and in the batch report rather than left to be noticed.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// CONNECTING NAVIGATES THE WHOLE WINDOW — NEVER A POPUP, NEVER A `fetch`
// ═════════════════════════════════════════════════════════════════════════════════════════
// `POST …/authorize` answers `{authorize_url}` AND sets an `HttpOnly`, `SameSite=Lax`,
// host-only cookie the browser must still hold when the platform redirects back. A popup does
// receive that cookie, but it is blocked often enough to matter, it is lost when consent
// bounces through another Google profile, and the configured return path lands in the tab
// that STARTED the flow — with a popup, the one nobody is looking at. Following the URL with
// `fetch` drops the headers a real handshake needs from that point on.
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Card from '../../ui/layout/Card.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import PlatformConnectionCard from './PlatformConnectionCard.vue';
import OAuthReturnBanner from './OAuthReturnBanner.vue';
import { usePublishingConnectionsStore } from '../../app/stores/publishingConnections';
import { usePublishingStore } from '../../app/stores/publishing';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import { CONNECTABLE_PLATFORMS } from './publishingMeta';
import { fieldErrorsOf, serverMessageOf } from './publishingErrors';
import { readOAuthReturn } from './oauthReturn';
import type { PlatformConnection, PublishingPlatform } from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = usePublishingStore();
const connections = usePublishingConnectionsStore();
const toast = useToast();
const confirm = useConfirm();

/** The failure banner's state, read once on arrival (see `oauthReturn.ts`). */
const failure = ref<{ reason: string | null; platform: PublishingPlatform | null } | null>(null);

/**
 * Destinations this installation has no client id/secret for. There is no way to know before
 * clicking (gap L6): the connections resource does not say. So the refusal is remembered for
 * the life of this screen and the button stays visible but disabled with the server's reason
 * beside it — a second click would only repeat the same lesson.
 */
const notConfigured = ref<Record<string, string>>({});

onMounted(async () => {
  void store.loadTimezone().catch(() => undefined);

  // Read ONCE, then strip the parameters from the URL immediately — otherwise a later Back
  // resurrects a banner about something that happened a quarter of an hour ago.
  const result = readOAuthReturn(route.query);
  if (result) {
    const stripped = { ...route.query };
    delete stripped.connection;
    delete stripped.platform;
    delete stripped.reason;
    await router.replace({ query: stripped });

    if (result.result === 'connected') {
      // One sentence, and the proof appears on the card after the refetch — so a toast is
      // the right vessel here, unlike for a failure.
      toast.success(t('publishing.oauth.connected'));
    } else {
      failure.value = { reason: result.reason, platform: result.platform };
    }
  }

  // Ordinarily the module shell has already loaded this and the store's guard makes the call
  // free. A RETURN FROM A CONSENT SCREEN is the one arrival where the cached list is known to
  // be stale — the account just connected is not in it — so that one is forced.
  await connections.fetchConnections({ force: result !== null }).catch(() => undefined);
});

const grouped = computed(() => connections.byPlatform);

function labelFor(platform: PublishingPlatform): string {
  return grouped.value[platform]?.[0]?.platform_label ?? t(`publishing.platforms.${platform}`);
}

/** Start a handshake and hand the window over. */
async function connect(platform: PublishingPlatform): Promise<void> {
  try {
    const authorization = await connections.authorize(platform);
    window.location.assign(authorization.authorize_url);
  } catch (err) {
    // `platform_not_configured` / `platform_not_connectable` arrive as a 422 on the
    // `platform` field (`AuthorizePlatformConnectionRequest`). Both describe a lasting state
    // of the installation, so the server's sentence belongs ON THE CARD rather than in a
    // toast that vanishes before an administrator can be told about it.
    const reason = fieldErrorsOf(err).platform;
    if (reason) {
      notConfigured.value = { ...notConfigured.value, [platform]: reason };
      return;
    }
    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
  }
}

async function disconnect(connection: PlatformConnection): Promise<void> {
  // THE CONSEQUENCE IS STATED BEFORE, NOT AFTER. Three things in this text are deliberate:
  // there is NO COUNT of held publications (we do not have one, and a guessed number would
  // be a lie where a lie costs unsent posts); the promise that reconnecting restores them is
  // true (`connect()` calls `releaseQueue()`); and the warning about overdue ones is true and
  // non-obvious — restoring arms the ORIGINAL moment, so anything already past goes out at
  // the next sweep, a minute later.
  const ok = await confirm({
    title: t('publishing.connections.disconnectConfirm.title', '', {
      name: connection.account_name,
    }),
    message: t('publishing.connections.disconnectConfirm.body'),
    confirmLabel: t('publishing.connections.disconnect'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;

  try {
    await connections.disconnect(connection.id);
    toast.success(t('publishing.toasts.disconnected'));
  } catch (err) {
    toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
  }
}
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <PageHeader
      :title="t('publishing.connections.title')"
      :description="t('publishing.connections.subtitle')"
      icon="link-2"
    >
      <template #actions>
        <!-- Explicitly asked for: this one always goes to the server. -->
        <Button
          variant="ghost"
          leading-icon="rotate-ccw"
          :loading="connections.loading"
          @click="connections.fetchConnections({ force: true }).catch(() => undefined)"
        >
          {{ t('publishing.connections.refresh') }}
        </Button>
      </template>
    </PageHeader>

    <OAuthReturnBanner
      v-if="failure"
      :reason="failure.reason"
      :platform="failure.platform"
      :platform-label="failure.platform ? labelFor(failure.platform) : null"
      @dismiss="failure = null"
      @retry="connect"
    />

    <!-- The APP_KEY incident. Not dismissible, because it is not an event — it is a state
         that lasts until somebody reconnects. The rest of the screen renders NORMALLY: this
         is the page people come to in order to fix exactly this, so it cannot be its
         casualty. -->
    <Alert v-if="connections.hasUnreadableCredentials" variant="danger" role="alert">
      <p class="font-next-medium">{{ t('publishing.connections.unreadable.title') }}</p>
      <p>{{ t('publishing.connections.unreadable.body') }}</p>
    </Alert>

    <!-- Loading: THREE platform-card skeletons, because there will always be three. -->
    <div
      v-if="connections.loading && !connections.loaded"
      class="grid gap-next-4 next-md:grid-cols-2 next-xl:grid-cols-3"
    >
      <Card v-for="n in 3" :key="`sk-${n}`">
        <Skeleton variant="text" width="30%" :label="n === 1 ? t('common.loading') : undefined" />
        <Skeleton variant="rect" height="3rem" class="mt-next-3" :count="2" />
      </Card>
    </div>

    <EmptyState
      v-else-if="connections.errored && !connections.loaded"
      variant="error"
      role="alert"
      :title="t('publishing.errors.connectionsTitle')"
      :description="connections.error ?? t('publishing.errors.loadDescription')"
    >
      <template #action>
        <Button
          variant="outline"
          size="sm"
          leading-icon="rotate-ccw"
          @click="connections.fetchConnections({ force: true }).catch(() => undefined)"
        >
          {{ t('publishing.actions.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- One card per CONNECTABLE destination, including the ones with no accounts. `dry_run`
         gets none: it has nothing to connect, and a card explaining that would be three
         sentences about something nobody attempted. -->
    <div v-else class="grid gap-next-4 next-md:grid-cols-2 next-xl:grid-cols-3">
      <PlatformConnectionCard
        v-for="platform in CONNECTABLE_PLATFORMS"
        :key="platform"
        :platform="platform"
        :connections="grouped[platform] ?? []"
        :timezone="store.timezone"
        :busy="connections.authorizing === platform"
        :not-configured="!!notConfigured[platform]"
        :not-configured-message="notConfigured[platform] ?? null"
        @connect="connect"
        @disconnect="disconnect"
      />
    </div>
  </div>
</template>
