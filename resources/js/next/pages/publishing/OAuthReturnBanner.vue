<script setup lang="ts">
// OAuthReturnBanner — what a person reads after coming back from Google or Meta (§10.3).
//
// SUCCESS IS A TOAST, FAILURE IS A BANNER THAT STAYS. Somebody returns from a consent screen
// with nothing, and the sentence they need is five to twenty-five words long and says what to
// do next — the server's catalog is written that way on purpose. A toast disappears after
// five seconds and cannot be read twice. So a failure lands in a dismissible Alert pinned
// above the grid, with "Connect again" offered exactly where the remedy is the reader's.
//
// FOURTEEN NAMED REASONS AND A FIFTEENTH SHAPE. The callback does not validate `reason`
// against a closed list: any raw platform error (`server_error`, …) passes through verbatim,
// truncated to 64 characters. So the fallback is load-bearing — without it, the worst
// possible moment to show somebody a machine identifier would show them one.
//
// TONE IS NOT SEVERITY THEATRE. `access_denied` and every stale-link refusal are `warning`:
// the user declined, or clicked an old link, and nothing broke. Red there would be an
// accusation. And none of these sentences names an attack — the commonest road even to the
// security refusals is a copied link or cookies switched off.
import { computed, onMounted, ref } from 'vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import { oauthCanRetry, oauthReasonKey, oauthReasonTone } from './publishingMeta';
import type { PublishingPlatform } from './types';

const props = defineProps<{
  reason: string | null;
  /** MAY be absent — the controller filters a null platform on `unknown_platform`. */
  platform: PublishingPlatform | null;
  /** The server's prose for the destination, when a connection for it is loaded. */
  platformLabel?: string | null;
}>();

const emit = defineEmits<{
  (e: 'dismiss'): void;
  (e: 'retry', platform: PublishingPlatform): void;
}>();

const { t } = useI18n();
const root = ref<HTMLElement | null>(null);

const variant = computed(() => oauthReasonTone(props.reason));
const sentence = computed(() => t(oauthReasonKey(props.reason)));
/** True only for a code this build does not know — then the raw code is shown, quietly. */
const isUnknownCode = computed(
  () => oauthReasonKey(props.reason) === 'publishing.oauth.failures.unknown',
);
const canRetry = computed(() => oauthCanRetry(props.reason, props.platform));

/**
 * Focus moves HERE on arrival. The person has just come back from another site and this
 * banner is the only new thing on the page; `preventScroll` keeps the viewport where the
 * layout put it.
 */
onMounted(() => {
  root.value?.focus({ preventScroll: true });
});
</script>

<template>
  <div ref="root" tabindex="-1" class="outline-none">
    <Alert
      :variant="variant"
      role="alert"
      dismissible
      :title="t('publishing.oauth.failed')"
      @dismiss="emit('dismiss')"
    >
      <p>{{ sentence }}</p>
      <!-- The raw code, on its own line and quietly — never as the sentence itself. -->
      <p v-if="isUnknownCode && reason" class="mt-next-1 break-all font-next-mono text-next-2xs opacity-80">
        {{ reason }}
      </p>

      <template v-if="canRetry && platform" #actions>
        <Button variant="outline" size="sm" leading-icon="link-2" @click="emit('retry', platform)">
          {{ t('publishing.connections.reconnect') }}
          <span v-if="platformLabel" class="sr-only"> — {{ platformLabel }}</span>
        </Button>
      </template>
    </Alert>
  </div>
</template>
