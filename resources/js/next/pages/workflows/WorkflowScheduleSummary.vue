<script setup lang="ts">
// WorkflowScheduleSummary — the cadence sentence + the AI entry point (§4.5.3, REV5).
// REV5 drops its own `Surface` band + the `h-9` bubble: it is now the TOP ROW of the
// host's single header segment (the host owns the `Surface bg="muted"` frame). The row
// is: an inline calendar glyph + the live `describeSchedule` sentence + a `#jump` slot
// (the host's "Skocz do daty" compact date field — the host owns the anchor) + the
// "Zaplanuj z AI" button. The sentence is ALWAYS renderable (the neutral draft yields
// "Codziennie o 09:00"), so there is NO empty state. It owns NO AI logic (it emits
// `assist`; the host wires the modal) and NO anchor logic (the host fills `#jump`).
import { computed } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import { describeSchedule, type ScheduleDraft } from './workflowSchedule';

const props = withDefaults(
  defineProps<{
    draft: ScheduleDraft;
    /** The viewer's active zone; the "({tz})" clause shows only for a foreign zone (§4.5.10). */
    activeTz?: string | null;
  }>(),
  { activeTz: null },
);
const emit = defineEmits<{ (e: 'assist'): void }>();

const { t } = useI18n();

/** The live cadence sentence — recomputes on every draft/locale change. */
const sentence = computed(() => describeSchedule(props.draft, t, props.activeTz ?? undefined));
</script>

<template>
  <div class="flex flex-wrap items-center gap-next-2">
    <Icon name="calendar" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
    <p class="min-w-0 flex-1 text-next-sm font-next-medium text-next-fg">{{ sentence }}</p>
    <div class="flex flex-wrap items-center justify-end gap-next-1_5">
      <!-- The host's "Skocz do daty" date field (it owns the anchor). On a narrow row this
           cluster wraps below the sentence, and the field can wrap under the button. -->
      <slot name="jump" />
      <Button variant="outline" size="sm" leading-icon="sparkles" @click="emit('assist')">
        {{ t('workflows.schedule.summary.assist') }}
      </Button>
    </div>
  </div>
</template>
