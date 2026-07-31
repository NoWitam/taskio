<script setup lang="ts">
// BotVisualImage — the bytes of ONE bot-owned image file (a likeness candidate, the approved likeness,
// or a reference), fetched through the Disk serve route.
//
// The route (`GET /api/disk/{id}?inline=1`) is auth + workspace scoped: it needs the headers only the
// `api` client attaches, so these bytes can NOT be a bare `<img src>` (a raw request would carry
// neither and 401/404). They are blob-fetched into an object URL — the same pattern as
// `SessionPartImage` / `DiskThumbnail` — and the URL is revoked before every refetch AND on unmount so
// nothing leaks. A superseding token means a slow response for a REPLACED file can never paint over a
// newer one.
//
// Three states, all rendered in the SAME box so the layout never jumps: a Skeleton that mimics the real
// frame, a load-error tile (no toast — a candidate that will not load is a tile-level fact), the image.
import { onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** The Disk file id to serve. */
    fileId: string;
    /** Accessible alt text (the caller localizes it — this component never invents one). */
    alt: string;
    /** How the image sits in its box: `cover` fills a square tile, `contain` shows the whole frame. */
    fit?: 'cover' | 'contain';
    /** Hint the browser to defer the decode (a grid of tiles). */
    lazy?: boolean;
  }>(),
  { fit: 'cover', lazy: false },
);

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

async function load(id: string): Promise<void> {
  const my = (token += 1);
  loading.value = true;
  failed.value = false;
  revoke();
  try {
    const blob = await api.get<Blob>(`/disk/${id}?inline=1`, { responseType: 'blob' });
    if (my !== token) return; // superseded by a newer file id
    url.value = URL.createObjectURL(blob);
  } catch {
    if (my !== token) return;
    failed.value = true;
  } finally {
    if (my === token) loading.value = false;
  }
}

watch(() => props.fileId, (id) => void load(id), { immediate: true });

onBeforeUnmount(revoke);
</script>

<template>
  <Skeleton v-if="loading" variant="rect" width="100%" height="100%" radius="md" />

  <div
    v-else-if="failed"
    class="flex h-full w-full flex-col items-center justify-center gap-next-1 p-next-2 text-center text-next-2xs text-next-muted-foreground"
  >
    <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
    <span>{{ t('bots.editor.visual.candidates.loadError') }}</span>
  </div>

  <img
    v-else-if="url"
    :src="url"
    :alt="alt"
    :loading="lazy ? 'lazy' : undefined"
    class="h-full w-full"
    :class="fit === 'cover' ? 'object-cover' : 'object-contain'"
  />
</template>
