<script setup lang="ts">
// FunctionRow — one row of the custom-functions management list. A card row carrying the
// function's name, its SIGNATURE (input type → return type, with the type glyphs) and arg
// count, an optional description, the creator, and the Edit / Delete actions. Edit + Delete
// are GATED on the backend capability flags (`can_be_edited` / `can_be_deleted`); a disabled
// action keeps a tooltip explaining why. Emits `edit` / `delete` with the function.
//
// The wire op id (`fn:<uuid>`) is DELIBERATELY not shown — the editable `name` is the only
// user-facing identity.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { getVariableIconLabel, getVariableIconName } from '../../ui/editor/extensions/operationHelpers';
import { useI18n } from '../../app/i18n';
import type { VariablePrimitive } from '../../ui/editor/extensions/types';
import type { CustomFunction } from '../workflows/types';

const props = defineProps<{ func: CustomFunction }>();

const emit = defineEmits<{ edit: [CustomFunction]; delete: [CustomFunction] }>();

const { t } = useI18n();

const inputType = computed(() => props.func.input_type as VariablePrimitive);
const returnType = computed(() => props.func.return_type as VariablePrimitive);
const argCount = computed(() => props.func.args?.length ?? 0);
</script>

<template>
  <div class="flex items-start gap-next-4 rounded-next-lg border border-next-border bg-next-card p-next-4">
    <span
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-muted text-next-muted-foreground"
      aria-hidden="true"
    >
      <Icon name="code" />
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-next-2">
      <!-- Name -->
      <div class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1">
        <span class="truncate font-next-semibold text-next-fg">{{ func.name }}</span>
      </div>

      <!-- Signature: input → return, with the arg count -->
      <div class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1 text-next-sm">
        <span class="inline-flex items-center gap-next-1 text-next-muted-foreground" :title="getVariableIconLabel(inputType)">
          <Icon :name="getVariableIconName(inputType)" class="text-next-xs" aria-hidden="true" />
          {{ getVariableIconLabel(inputType) }}
        </span>
        <Icon name="arrow-right" class="text-next-muted-foreground" aria-hidden="true" />
        <span class="inline-flex items-center gap-next-1 text-next-fg" :title="getVariableIconLabel(returnType)">
          <Icon :name="getVariableIconName(returnType)" class="text-next-xs text-next-primary" aria-hidden="true" />
          {{ getVariableIconLabel(returnType) }}
        </span>
        <Badge variant="neutral" tone="subtle" size="sm">
          {{ t('variables.functions.list.argCount', '', { count: argCount }) }}
        </Badge>
      </div>

      <!-- Description (optional) -->
      <p v-if="func.description" class="line-clamp-2 text-next-sm text-next-muted-foreground">
        {{ func.description }}
      </p>

      <!-- Creator -->
      <CreatorBadge :creator="func.creator" size="xs" class="text-next-xs" />
    </div>

    <!-- Actions (gated on capability flags) -->
    <div class="flex shrink-0 items-center gap-next-1">
      <Tooltip :label="func.can_be_edited ? t('variables.functions.list.edit') : t('variables.functions.list.editDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="pencil"
          :disabled="!func.can_be_edited"
          :aria-label="t('variables.functions.list.edit')"
          @click="emit('edit', func)"
        />
      </Tooltip>
      <Tooltip :label="func.can_be_deleted ? t('variables.functions.list.delete') : t('variables.functions.list.deleteDisabled')">
        <Button
          variant="ghost"
          size="icon-sm"
          leading-icon="trash"
          :disabled="!func.can_be_deleted"
          :aria-label="t('variables.functions.list.delete')"
          @click="emit('delete', func)"
        />
      </Tooltip>
    </div>
  </div>
</template>
