<script setup lang="ts">
// PlatformConnectionCard — one destination and the accounts connected to it (§9.2, §9.3).
//
// The grid of these cards IS the Connections screen's navigation, which is why that screen
// is the one named exception to the "every list gets a FilterBar + saved views" rule: the set
// of destinations is closed and known in advance, the response is unpaginated, and a saved
// view pill over three cards would be a pill with nothing in it.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// THERE IS NO "DISCONNECTED ACCOUNTS" SECTION HERE, AND THERE CANNOT BE ONE
// ═════════════════════════════════════════════════════════════════════════════════════════
// The spec's §9.3 asks for revoked accounts folded into a collapsed section. THE CONTRACT
// CANNOT SERVE IT: `PlatformConnectionService::index()` queries without `withTrashed()`, and
// revoking sets the status to `revoked` AND soft deletes the row — so a disconnected account
// never reaches this component at all. A section filtering `status === 'revoked'` out of that
// response is a section that is empty by construction: code that documents a screen which
// does not exist, and reads to the next person as if it did.
//
// The row itself DOES outlive disconnection server-side (an already-published publication
// still resolves to the account it went out on), so the missing piece is purely the read
// surface. RECOMMENDATION FOR THE BACKEND (recorded as a gap, not quietly assumed): an
// `?include=disconnected` parameter on `GET /publishing/connections`. Until it exists, the
// one place a disconnected account is named is the publication detail, which says so from
// the absence itself ("the account was disconnected").
import { computed } from 'vue';
import Card from '../../ui/layout/Card.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Accordion from '../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../ui/disclosure/AccordionItem.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { useI18n } from '../../app/i18n';
import {
  connectionFailureSentenceKey,
  connectionStatusIcon,
  platformIcon,
  toneToVariant,
} from './publishingMeta';
import { formatInstantLong } from './publishingTime';
import type { PlatformConnection, PublishingPlatform } from './types';

const props = defineProps<{
  platform: PublishingPlatform;
  connections: PlatformConnection[];
  timezone: string | null;
  /** True while this card's handshake is starting (the button stays busy until we leave). */
  busy?: boolean;
  /**
   * `platform_not_configured` came back for this destination. The button STAYS VISIBLE and
   * disabled with the reason beside it: this is a state of the installation, not a
   * permission the reader lacks, and hiding it would make the screen look complete.
   */
  notConfigured?: boolean;
  /** The server's own sentence for that refusal, rendered verbatim. */
  notConfiguredMessage?: string | null;
}>();

const emit = defineEmits<{
  (e: 'connect', platform: PublishingPlatform): void;
  (e: 'disconnect', connection: PlatformConnection): void;
}>();

const { t, currentLocale } = useI18n();

const label = computed(
  () => props.connections[0]?.platform_label ?? t(`publishing.platforms.${props.platform}`),
);

const at = (iso: string | null): string =>
  formatInstantLong(iso, props.timezone, currentLocale.value);

/** Shown only when the account is NOT healthy — a reason beside a working account is noise. */
function failureSentence(connection: PlatformConnection): string {
  if (!connection.failure_code || connection.status === 'active') return '';
  return t(connectionFailureSentenceKey(connection.failure_code), '', {
    code: connection.failure_code,
  });
}

function needsRepair(connection: PlatformConnection): boolean {
  return connection.needs_attention || connection.credentials_readable === false;
}

/**
 * The connect button stays REACHABLE while it is refused (`aria-disabled` + a dimmed look,
 * never `disabled` and never `pointer-events: none`): the reason stands in the Alert above
 * it and a control nobody can reach with a keyboard is a control whose reason nobody reads.
 * Activation is stopped here instead — `aria-disabled` alone stops nothing, so Enter and
 * Space on this button used to start a handshake the server has already refused.
 */
function onConnect(): void {
  if (props.notConfigured) return;
  emit('connect', props.platform);
}
</script>

<template>
  <Card
    class="flex flex-col gap-next-3"
    :class="connections.some(needsRepair) ? 'border-next-danger/30' : ''"
  >
    <header class="flex items-center gap-next-2">
      <span
        class="flex h-8 w-8 items-center justify-center rounded-next-lg border border-next-border bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Icon :name="platformIcon(platform)" />
      </span>
      <h2 class="min-w-0 flex-1 truncate text-next-sm font-next-semibold text-next-fg">
        {{ label }}
      </h2>
    </header>

    <!-- The installation is not registered with this platform. Server prose, verbatim. -->
    <Alert v-if="notConfigured" variant="warning" size="sm">
      {{ notConfiguredMessage ?? t('publishing.connections.notConfigured') }}
    </Alert>

    <!-- Empty destination: NOT a page-wide EmptyState. An overlay would cover exactly the
         three buttons somebody came here to press. -->
    <div
      v-if="connections.length === 0"
      class="rounded-next-lg bg-next-muted/40 p-next-3 text-next-sm"
    >
      <p class="font-next-medium text-next-fg">{{ t('publishing.connections.empty') }}</p>
      <p class="text-next-muted-foreground">{{ t('publishing.connections.emptyHint') }}</p>
    </div>

    <!-- EVERY account the server returned, unfiltered. A client-side filter here could only
         hide a row the contract says cannot arrive — and would hide it silently the day it
         did. See the file docblock. -->
    <ul v-else role="list" class="flex flex-col gap-next-3">
      <li
        v-for="connection in connections"
        :key="connection.id"
        class="rounded-next-lg border p-next-3"
        :class="
          connection.credentials_readable === false
            ? 'border-next-danger/30 bg-next-danger-subtle'
            : 'border-next-border bg-next-card'
        "
      >
        <div class="flex items-start gap-next-2">
          <div class="min-w-0 flex-1">
            <p class="truncate text-next-sm font-next-medium text-next-fg">
              {{ connection.account_name }}
            </p>
            <!-- The ONE thing that tells two same-named accounts apart. Never hidden. -->
            <p class="break-all font-next-mono text-next-2xs text-next-muted-foreground">
              {{ connection.external_account_id }}
            </p>
          </div>

          <!-- Trailing order: the CONDITIONAL repair button before the permanent kebab. -->
          <Button
            v-if="needsRepair(connection)"
            variant="outline"
            size="sm"
            leading-icon="link-2"
            :loading="busy"
            @click="emit('connect', platform)"
          >
            {{ t('publishing.connections.reconnect') }}
          </Button>

          <DropdownMenu
            v-if="connection.can_be_disconnected"
            placement="bottom-end"
            :aria-label="t('publishing.connections.accountActions', '', { name: connection.account_name })"
          >
            <template #trigger="{ props: triggerProps }">
              <Button
                variant="ghost"
                size="icon-sm"
                leading-icon="more-vertical"
                :aria-label="t('publishing.connections.accountActions', '', { name: connection.account_name })"
                :aria-haspopup="triggerProps['aria-haspopup']"
                :aria-expanded="triggerProps['aria-expanded'] === 'true'"
                :aria-controls="triggerProps['aria-controls']"
              />
            </template>
            <DropdownMenuItem
              icon="unlink"
              destructive
              :label="t('publishing.connections.disconnect')"
              @select="emit('disconnect', connection)"
            >
              {{ t('publishing.connections.disconnect') }}
            </DropdownMenuItem>
          </DropdownMenu>
        </div>

        <div class="mt-next-2 flex flex-wrap items-center gap-next-2 text-next-xs">
          <Badge
            :variant="toneToVariant(connection.status_tone)"
            tone="subtle"
            size="sm"
            :icon="connectionStatusIcon(connection.status)"
          >
            {{ connection.status_label }}
          </Badge>
          <!-- `null` means THE PLATFORM DID NOT SAY. It never reads as "expired". -->
          <span class="text-next-muted-foreground">
            {{
              connection.expires_at
                ? t('publishing.connections.expiresAt', '', { value: at(connection.expires_at) })
                : t('publishing.connections.expiresUnknown')
            }}
          </span>
          <span v-if="connection.last_refreshed_at" class="text-next-muted-foreground">
            {{ t('publishing.connections.refreshedAt', '', { value: at(connection.last_refreshed_at) }) }}
          </span>
          <CreatorBadge :creator="connection.creator ?? null" size="xs" />
        </div>

        <p v-if="failureSentence(connection)" class="mt-next-2 text-next-xs text-next-danger">
          {{ failureSentence(connection) }}
        </p>

        <!-- What the platform GRANTED — not what was asked for. Technical, and kept because
             it is the only available explanation for a publish that later fails on
             permissions. Collapsed, so it costs nobody anything. -->
        <Accordion v-if="connection.scopes.length" type="single" class="mt-next-2">
          <AccordionItem
            :value="`scopes-${connection.id}`"
            :title="t('publishing.connections.scopes', '', { count: connection.scopes.length })"
          >
            <ul class="flex flex-col gap-next-0_5">
              <li
                v-for="scope in connection.scopes"
                :key="scope"
                class="break-all font-next-mono text-next-xs text-next-muted-foreground"
              >
                {{ scope }}
              </li>
            </ul>
          </AccordionItem>
        </Accordion>
      </li>
    </ul>

    <!-- Refused, and still reachable: `aria-disabled` + the house inert look, with the reason
         in the Alert above and the guard in `onConnect()`. Never `pointer-events-none` —
         Button's own docblock records what that cost the application. -->
    <Button
      variant="primary"
      size="sm"
      leading-icon="plus"
      :loading="busy"
      :aria-disabled="notConfigured || undefined"
      :class="notConfigured ? 'opacity-60 cursor-not-allowed' : ''"
      @click="onConnect"
    >
      {{
        connections.length
          ? t('publishing.connections.connectAnother')
          : t('publishing.connections.connect')
      }}
    </Button>
  </Card>
</template>
