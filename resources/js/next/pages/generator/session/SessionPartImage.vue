<script setup lang="ts">
// SessionPartImage — a produced part image (R2 sub-stage 2c). The serve endpoint
// (GET /generator/sessions/{id}/parts/{partKey}/image) is auth-gated: it needs the Bearer + workspace
// headers that ONLY the `api` client attaches, so the bytes can NOT be a bare <img src> (a raw <img>
// request would carry neither). It is blob-fetched into an object URL — exactly how the Disk thumbnail /
// preview load their bytes — and the URL is revoked before every re-fetch and on unmount so nothing leaks.
//
// The frame is aspect-preserving (object-contain, capped height) with a hairline border on a muted
// surface, so it reads the same in light and dark. `width`/`height` (when known) are set on the <img> to
// reserve space and cut layout shift while the blob loads.
import { onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import { api } from '../../../app/lib/api';
import { useI18n } from '../../../app/i18n';
import type { ProducedImage } from '../sessionTypes';

const props = defineProps<{
  sessionId: string;
  partKey: string;
  /** Produced-image meta (dimensions reserve space; absent → the frame just uses a min height). */
  image?: ProducedImage | null;
  /** Accessible alt text for the produced image. */
  alt: string;
}>();

const { t } = useI18n();

const url = ref<string | null>(null);
const loading = ref(false);
const failed = ref(false);
let token = 0;

function revoke(): void {
  if (url.value) {
    URL.revokeObjectURL(url.value);
    url.value = null;
  }
}

async function load(): Promise<void> {
  const my = (token += 1);
  loading.value = true;
  failed.value = false;
  revoke();
  try {
    // Version-suffix the URL as a cache-buster: the serve endpoint resolves the CURRENT version
    // server-side (the path is version-agnostic), so after a regenerate/refine/undo bumps the
    // version the browser HTTP cache must not re-serve the previous bytes for the same path.
    const version = props.image?.version;
    const blob = await api.get<Blob>(
      `/generator/sessions/${props.sessionId}/parts/${encodeURIComponent(props.partKey)}/image${version ? `?v=${version}` : ''}`,
      { responseType: 'blob' },
    );
    if (my !== token) return;
    url.value = URL.createObjectURL(blob);
  } catch {
    // A missing / foreign part 404s; leave `failed` so the frame shows the load error, not a broken img.
    if (my !== token) return;
    failed.value = true;
  } finally {
    if (my === token) loading.value = false;
  }
}

// Refetch when the produced-image VERSION changes too — a per-part regenerate/refine/undo bumps
// `image.version` while sessionId/partKey stay put, and the version-agnostic serve URL would
// otherwise keep the stale object URL (the "Wersja N" badge would advance but the picture wouldn't).
watch(
  () => [props.sessionId, props.partKey, props.image?.version] as const,
  () => void load(),
  { immediate: true },
);

onBeforeUnmount(revoke);
</script>

<template>
  <div class="flex items-center justify-center overflow-hidden rounded-next-lg border border-next-border bg-next-muted/40 p-next-1">
    <Skeleton v-if="loading" variant="rect" width="100%" height="12rem" radius="md" />
    <div
      v-else-if="failed"
      class="flex min-h-[8rem] w-full items-center justify-center gap-next-2 p-next-4 text-next-sm text-next-danger"
      role="alert"
    >
      <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
      {{ t('generator.sessions.result.imageLoadError') }}
    </div>
    <img
      v-else-if="url"
      :src="url"
      :alt="alt"
      :width="image?.width || undefined"
      :height="image?.height || undefined"
      class="max-h-[28rem] w-auto max-w-full rounded-next-md object-contain"
    />
  </div>
</template>
