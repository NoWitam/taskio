<script setup lang="ts">
// TemplateRow — one row of the templates management list. A card row carrying the template's
// name, a TYPE badge, its description, a slot-count chip, the creator, and the Start-session /
// Edit / Delete actions. Edit + Delete are GATED on the backend capability flags
// (`can_be_edited` / `can_be_deleted`); a disabled action keeps a tooltip explaining why. Start
// session is always available (a new session snapshots the recipe, so it needs no edit right).
// Emits `create-session` / `edit` / `delete`.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { useI18n } from '../../app/i18n';
import { contentTypeIcon, contentTypeLabel } from './templateMeta';
import type { Template } from './types';

const props = defineProps<{ template: Template }>();

const emit = defineEmits<{ 'create-session': [Template]; edit: [Template]; delete: [Template] }>();

const { t } = useI18n();

const typeLabel = computed(() => contentTypeLabel(props.template.content_type, t));
const typeIcon = computed(() => contentTypeIcon(props.template.content_type));
const slotCount = computed(() => props.template.slots?.length ?? 0);
</script>

<template>
  <div class="flex items-start gap-next-4 rounded-next-lg border border-next-border bg-next-card p-next-4">
    <span
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-muted text-next-muted-foreground"
      aria-hidden="true"
    >
      <Icon :name="typeIcon" />
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-next-2">
      <!-- Name + type badge -->
      <div class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1">
        <span class="truncate font-next-semibold text-next-fg">{{ template.name }}</span>
        <Badge variant="neutral" tone="subtle" size="sm" :icon="typeIcon">{{ typeLabel }}</Badge>
      </div>

      <!-- Description (optional) -->
      <p v-if="template.description" class="line-clamp-2 text-next-sm text-next-muted-foreground">
        {{ template.description }}
      </p>

      <!-- Metadata: slot count + creator -->
      <div class="flex flex-wrap items-center gap-x-next-3 gap-y-next-1 text-next-sm">
        <span class="inline-flex items-center gap-next-1 text-next-muted-foreground">
          <Icon name="braces" class="text-next-xs" aria-hidden="true" />
          {{ t('generator.templates.list.slotCount', '', { count: slotCount }) }}
        </span>
        <CreatorBadge :creator="template.creator" size="xs" class="text-next-xs" />
      </div>
    </div>

    <!-- Actions (Start session is always available; Edit / Delete gated on capability flags) -->
    <div class="flex shrink-0 items-center gap-next-1">
      <Tooltip :label="t('generator.templates.list.createSession')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="sparkles"
          :aria-label="t('generator.templates.list.createSession')"
          @click="emit('create-session', template)"
        />
      </Tooltip>
      <Tooltip :label="template.can_be_edited ? t('generator.templates.list.edit') : t('generator.templates.list.editDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="pencil"
          :disabled="!template.can_be_edited"
          :aria-label="t('generator.templates.list.edit')"
          @click="emit('edit', template)"
        />
      </Tooltip>
      <Tooltip :label="template.can_be_deleted ? t('generator.templates.list.delete') : t('generator.templates.list.deleteDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="trash"
          :disabled="!template.can_be_deleted"
          :aria-label="t('generator.templates.list.delete')"
          @click="emit('delete', template)"
        />
      </Tooltip>
    </div>
  </div>
</template>
