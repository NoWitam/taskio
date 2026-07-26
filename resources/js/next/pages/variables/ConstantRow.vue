<script setup lang="ts">
// ConstantRow — one row of the consts management list. A card row carrying the const's
// name, its `globals.<key>` reference chip (the RUNTIME wire root stays `globals`), a human
// TYPE summary, a compact VALUE preview, the creator, and the Edit / Delete actions. Edit +
// Delete are GATED on the backend capability flags (`can_be_edited` / `can_be_deleted`); a
// disabled action keeps a tooltip explaining why. Emits `edit` / `delete` with the const.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { useI18n } from '../../app/i18n';
import { typeSummary, valuePreview } from './consts';
import type { Constant } from '../workflows/types';

const props = defineProps<{ constant: Constant }>();

const emit = defineEmits<{ edit: [Constant]; delete: [Constant] }>();

const { t } = useI18n();

const typeLabel = computed(() => typeSummary(props.constant.descriptor, t));
const valueLabel = computed(() => valuePreview(props.constant.descriptor, props.constant.value, t));
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
        <span class="truncate font-next-semibold text-next-fg">{{ constant.name }}</span>
        <Badge variant="neutral" tone="subtle" size="sm">
          <code class="font-next-mono">{{ constant.reference }}</code>
        </Badge>
      </div>

      <!-- Type summary + value preview -->
      <div class="flex flex-wrap items-center gap-x-next-3 gap-y-next-1 text-next-sm">
        <span class="inline-flex items-center gap-next-1 text-next-muted-foreground">
          <Icon name="tag" class="text-next-xs" aria-hidden="true" />
          {{ typeLabel }}
        </span>
        <span class="inline-flex min-w-0 items-center gap-next-1 text-next-fg">
          <span class="shrink-0 text-next-muted-foreground">{{ t('variables.consts.list.valueColumn') }}</span>
          <span class="truncate">{{ valueLabel }}</span>
        </span>
      </div>

      <!-- Creator -->
      <CreatorBadge :creator="constant.creator" size="xs" class="text-next-xs" />
    </div>

    <!-- Actions (gated on capability flags) -->
    <div class="flex shrink-0 items-center gap-next-1">
      <Tooltip :label="constant.can_be_edited ? t('variables.consts.list.edit') : t('variables.consts.list.editDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="pencil"
          :disabled="!constant.can_be_edited"
          :aria-label="t('variables.consts.list.edit')"
          @click="emit('edit', constant)"
        />
      </Tooltip>
      <Tooltip :label="constant.can_be_deleted ? t('variables.consts.list.delete') : t('variables.consts.list.deleteDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="trash"
          :disabled="!constant.can_be_deleted"
          :aria-label="t('variables.consts.list.delete')"
          @click="emit('delete', constant)"
        />
      </Tooltip>
    </div>
  </div>
</template>
