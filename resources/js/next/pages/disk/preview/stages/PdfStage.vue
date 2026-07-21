<script setup lang="ts">
// PdfStage — browsers render PDFs natively inside an iframe; the bytes are blob-fetched (auth
// headers) into an object URL, exactly like MediaStage.
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
    // Re-wrap under the pdf mime so the browser's viewer engages regardless of blob typing.
    url.value = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
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
  <div class="flex min-h-[60vh] flex-col overflow-hidden rounded-next-lg border border-next-border bg-next-muted/40">
    <Skeleton v-if="loading" class="m-next-4 h-64 rounded-next-md" />
    <p v-else-if="failed" class="m-next-4 flex items-center gap-next-2 text-next-sm text-next-danger" role="alert">
      <Icon name="alert-circle" aria-hidden="true" />
      {{ t('disk.preview.loadError', 'Could not load the file.') }}
    </p>
    <iframe v-else-if="url" :src="url" :title="file.name" class="h-[70vh] w-full" />
  </div>
</template>
