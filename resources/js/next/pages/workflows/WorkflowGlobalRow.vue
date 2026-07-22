<script setup lang="ts">
// WorkflowGlobalRow — one row of the globals management list (Phase 3). A card row
// carrying the global's name, its `globals.<key>` reference chip, a human TYPE summary,
// a compact VALUE preview, the creator, and the Edit / Delete actions. Edit + Delete are
// GATED on the backend capability flags (`can_be_edited` / `can_be_deleted`); a disabled
// action keeps a tooltip explaining why. Emits `edit` / `delete` with the global.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { useI18n } from '../../app/i18n';
import { typeSummary, valuePreview } from './workflowGlobals';
import type { WorkflowGlobal } from './types';

const props = defineProps<{ global: WorkflowGlobal }>();

const emit = defineEmits<{ edit: [WorkflowGlobal]; delete: [WorkflowGlobal] }>();

const { t } = useI18n();

const typeLabel = computed(() => typeSummary(props.global.descriptor, t));
const valueLabel = computed(() => valuePreview(props.global.descriptor, props.global.value, t));
</script>

<template>
  <div class="flex items-start gap-next-4 rounded-next-lg border border-next-border bg-next-card p-next-4">
    <span
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-muted text-next-muted-foreground"
      aria-hidden="true"
    >
      <Icon name="braces" />
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-next-2">
      <!-- Name + reference chip -->
      <div class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1">
        <span class="truncate font-next-semibold text-next-fg">{{ global.name }}</span>
        <Badge variant="neutral" tone="subtle" size="sm">
          <code class="font-next-mono">{{ global.reference }}</code>
        </Badge>
      </div>

      <!-- Type summary + value preview -->
      <div class="flex flex-wrap items-center gap-x-next-3 gap-y-next-1 text-next-sm">
        <span class="inline-flex items-center gap-next-1 text-next-muted-foreground">
          <Icon name="tag" class="text-next-xs" aria-hidden="true" />
          {{ typeLabel }}
        </span>
        <span class="inline-flex min-w-0 items-center gap-next-1 text-next-fg">
          <span class="shrink-0 text-next-muted-foreground">{{ t('workflows.globals.list.valueColumn') }}</span>
          <span class="truncate">{{ valueLabel }}</span>
        </span>
      </div>

      <!-- Creator -->
      <CreatorBadge :creator="global.creator" size="xs" class="text-next-xs" />
    </div>

    <!-- Actions (gated on capability flags) -->
    <div class="flex shrink-0 items-center gap-next-1">
      <Tooltip :label="global.can_be_edited ? t('workflows.globals.list.edit') : t('workflows.globals.list.editDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="pencil"
          :disabled="!global.can_be_edited"
          :aria-label="t('workflows.globals.list.edit')"
          @click="emit('edit', global)"
        />
      </Tooltip>
      <Tooltip :label="global.can_be_deleted ? t('workflows.globals.list.delete') : t('workflows.globals.list.deleteDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="trash"
          :disabled="!global.can_be_deleted"
          :aria-label="t('workflows.globals.list.delete')"
          @click="emit('delete', global)"
        />
      </Tooltip>
    </div>
  </div>
</template>
