<script setup lang="ts">
// MediaStage — native <video>/<audio> playback for the preview. The binary needs the
// workspace/auth headers only the api client sends, so the bytes are blob-fetched into a
// same-origin object URL (revoked on change/unmount).
import { onBeforeUnmount, ref, watch } from 'vue';
import Skeleton from '../../../../ui/data/Skeleton.vue';
import Icon from '../../../../ui/primitives/Icon.vue';
import { api } from '../../../../app/lib/api';
import { useI18n } from '../../../../app/i18n';
import type { DiskFile } from '../../types';

const props = defineProps<{ file: DiskFile }>();

const { t } = useI18n();

const url = ref<string | null>(null);
const loading = ref(false);
const failed = ref(false);

function revoke(): void {
  if (url.value) URL.revokeObjectURL(url.value);
  url.value = null;
}

async function load(): Promise<void> {
  revoke();
  failed.value = false;
  loading.value = true;
  try {
    const inline = props.file.path + (props.file.path.includes('?') ? '&' : '?') + 'inline=1';
    const blob = await api.get<Blob>(inline, { responseType: 'blob' });
    url.value = URL.createObjectURL(blob);
  } catch {
    failed.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => props.file.id, load, { immediate: true });
onBeforeUnmount(revoke);
</script>

<template>
  <div class="flex min-h-[50vh] items-center justify-center rounded-next-lg border border-next-border bg-next-muted/40 p-next-4">
    <Skeleton v-if="loading" class="h-64 w-full rounded-next-md" />
    <p v-else-if="failed" class="flex items-center gap-next-2 text-next-sm text-next-danger" role="alert">
      <Icon name="alert-circle" aria-hidden="true" />
      {{ t('disk.preview.loadError', 'Could not load the file.') }}
    </p>
    <video v-else-if="url && file.type === 'video'" :src="url" controls class="max-h-[65vh] max-w-full rounded-next-md" />
    <audio v-else-if="url" :src="url" controls class="w-full max-w-xl" />
  </div>
</template>
