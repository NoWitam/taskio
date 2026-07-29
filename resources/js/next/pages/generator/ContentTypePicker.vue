<script setup lang="ts">
// ContentTypePicker — STEP 1 of the create wizard: choose which RECIPE the template is for. It renders a
// card per content type from `GET /generator/content-types` (localized by stable id, NOT the server
// label) with the parts it is made of, and EMITS the chosen id. The editor then renders its data-driven
// sections from that type's parts. States: loading skeletons / error+retry / the cards.
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import { contentTypeIcon, contentTypeLabel, partKindIcon } from './templateMeta';
import { useI18n } from '../../app/i18n';
import type { ContentTypeDefinition } from './types';

defineProps<{
  contentTypes: ContentTypeDefinition[];
  loading?: boolean;
  errored?: boolean;
}>();

const emit = defineEmits<{ select: [string]; retry: [] }>();

const { t } = useI18n();
</script>

<template>
  <section class="flex flex-col gap-next-4">
    <div class="flex flex-col gap-next-0_5">
      <span class="text-next-sm font-next-medium text-next-fg">{{ t('generator.templates.editor.contentTypeLabel') }}</span>
      <span class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.editor.contentTypeHint') }}</span>
    </div>

    <!-- Loading: card-shaped skeletons. -->
    <div v-if="loading" class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2 next-lg:grid-cols-3" aria-hidden="true">
      <div v-for="n in 3" :key="n" class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-4">
        <Skeleton variant="rect" width="2.25rem" height="2.25rem" radius="md" />
        <Skeleton variant="text" width="60%" />
        <Skeleton variant="text" width="90%" />
      </div>
    </div>

    <!-- Error + retry. -->
    <EmptyState
      v-else-if="errored"
      variant="error"
      :title="t('generator.templates.editor.contentTypeError')"
      :description="t('generator.templates.errors.description')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="emit('retry')">
          {{ t('workflows.errors.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- The cards. -->
    <div v-else class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2 next-lg:grid-cols-3">
      <button
        v-for="type in contentTypes"
        :key="type.id"
        type="button"
        class="flex flex-col items-start gap-next-3 rounded-next-lg border border-next-border bg-next-card p-next-4 text-left transition-colors hover:border-next-primary hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
        @click="emit('select', type.id)"
      >
        <span
          class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-primary text-next-primary-foreground"
          aria-hidden="true"
        >
          <Icon :name="contentTypeIcon(type.id)" />
        </span>
        <span class="font-next-semibold text-next-fg">{{ contentTypeLabel(type.id, t, type.label) }}</span>
        <ul class="flex flex-wrap gap-next-1">
          <li
            v-for="part in type.parts"
            :key="part.key"
            class="inline-flex items-center gap-next-1 rounded-next-full bg-next-muted px-next-2 py-px text-next-xs text-next-muted-foreground"
          >
            <Icon :name="partKindIcon(part.kind)" class="text-next-xs" aria-hidden="true" />
            {{ t(`generator.templates.editor.partLabel.${part.kind}`, part.label) }}
            <span v-if="!part.required" class="text-next-muted-foreground/70">{{ t('generator.templates.editor.optionalTag') }}</span>
          </li>
        </ul>
      </button>
    </div>
  </section>
</template>
