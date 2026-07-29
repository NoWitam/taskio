<script setup lang="ts">
// ImagePlanPreviewCard — renders a server-resolved IMAGE PLAN summary (from `POST /generator/preview`):
// the base + the ordered filter chain, with each prompt string already directive-resolved by the server.
// NO image is executed (that is sub-stage 2) — this is a faithful PLAN card. Labels are localized by the
// stable base KIND / pixel OP (never the server's English label); the resolved prompt / slot / file
// values are shown as data.
import Icon from '../../ui/primitives/Icon.vue';
import { useI18n } from '../../app/i18n';
import type { ImagePlanSummary } from './types';

defineProps<{ plan: ImagePlanSummary | null }>();

const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 p-next-3">
    <!-- Base -->
    <div v-if="plan?.base" class="flex items-start gap-next-2">
      <Icon name="image" class="mt-px shrink-0 text-next-muted-foreground" aria-hidden="true" />
      <div class="flex min-w-0 flex-col">
        <span class="text-next-sm font-next-medium text-next-fg">
          {{ t(`generator.templates.editor.imageBase.${plan.base.kind}`, plan.base.label) }}
        </span>
        <span v-if="plan.base.slot" class="truncate text-next-xs text-next-muted-foreground">slots.{{ plan.base.slot }}</span>
        <span v-else-if="plan.base.file" class="truncate text-next-xs text-next-muted-foreground">{{ plan.base.file }}</span>
        <span v-else-if="plan.base.prompt" class="truncate text-next-xs text-next-muted-foreground">{{ plan.base.prompt }}</span>
      </div>
    </div>
    <p v-else class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.imagePlan.noBase') }}</p>

    <!-- Ordered filter chain -->
    <ol v-if="plan && plan.filters.length > 0" class="flex flex-col gap-next-1">
      <li
        v-for="(filter, index) in plan.filters"
        :key="index"
        class="flex items-center gap-next-1 text-next-xs text-next-muted-foreground"
      >
        <span class="inline-flex h-4 w-4 items-center justify-center rounded-next-full bg-next-muted text-[0.625rem] text-next-fg">{{ index + 1 }}</span>
        <Icon :name="filter.kind === 'ai_edit' ? 'sparkles' : 'image'" class="shrink-0" aria-hidden="true" />
        <span class="truncate">
          <template v-if="filter.kind === 'ai_edit'">
            {{ t('generator.templates.editor.imagePlan.aiEditStep') }}<template v-if="filter.prompt"> — {{ filter.prompt }}</template>
          </template>
          <template v-else>{{ t(`generator.templates.editor.pixelOp.${filter.op}`, filter.label) }}</template>
        </span>
      </li>
    </ol>
  </div>
</template>
