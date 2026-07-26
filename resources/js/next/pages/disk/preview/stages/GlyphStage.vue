<script setup lang="ts">
// GlyphStage — the fallback preview for types with no cheap client-side render (archives,
// spreadsheets, unknown binaries): the big type glyph + a "download to view" hint.
import Icon from '../../../../ui/primitives/Icon.vue';
import { useI18n } from '../../../../app/i18n';
import { fileTypeIcon } from '../../fileIcon';
import type { DiskFile } from '../../types';

defineProps<{ file: DiskFile }>();

const { t } = useI18n();
</script>

<template>
  <div class="flex min-h-[50vh] flex-col items-center justify-center gap-next-3 rounded-next-lg border border-next-border bg-next-muted/40 p-next-6">
    <span class="text-[7rem] text-next-muted-foreground" aria-hidden="true">
      <Icon :name="fileTypeIcon(file.type)" />
    </span>
    <p class="text-next-sm text-next-muted-foreground">
      {{ t('disk.preview.noInlinePreview', 'No inline preview for this file type — download it to view.') }}
    </p>
  </div>
</template>
