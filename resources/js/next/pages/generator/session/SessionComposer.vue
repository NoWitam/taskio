<script setup lang="ts">
// SessionComposer — the bottom refinement bar of the session chat (owner decision #3), LIVE in R2 sub-stage 2d.
//
// A free-text instruction + a send button that REFINES a produced part: the same `refine` endpoint the per-part
// "Dopracuj" affordance uses, so the composer just targets a part. It defaults to the PRIMARY text part (the
// post body — the first refinable target the page passes in) and, when more than one part can be refined,
// exposes a compact selector so it is always clear WHICH part the instruction revises. The instruction bubbles
// up (`send({partKey, instruction})`); the page claims + polls and surfaces the outcome.
//
// States: with no refinable target yet (a draft / nothing produced) the field is disabled with a hint; while
// the session is `generating` the send is disabled (the draft is preserved) — there is one op per session.
import { computed, ref, watch } from 'vue';
import Button from '../../../ui/primitives/Button.vue';
import Select, { type SelectOption } from '../../../ui/forms/Select.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import { SESSION_CAPABILITIES } from './sessionGating';
import { useI18n } from '../../../app/i18n';

/** One refinable part the composer may target (produced text / image part, in declared order — primary first). */
export interface ComposerTarget {
  key: string;
  label: string;
}

const props = withDefaults(
  defineProps<{
    /** The refinable, produced parts (text / image), primary text FIRST. Empty = nothing to refine yet. */
    targets?: ComposerTarget[];
    /** The session is mid-run — send is disabled (the draft is kept until it settles). */
    disabled?: boolean;
  }>(),
  { targets: () => [], disabled: false },
);

const emit = defineEmits<{ send: [{ partKey: string; instruction: string }] }>();

const { t } = useI18n();

const draft = ref('');
const selectedKey = ref('');

// Keep the selected target valid: default to the FIRST target (the primary text part) and re-seed when the set
// changes (e.g. a fresh generate produced the parts). `immediate` seeds a composer mounted already-populated.
watch(
  () => props.targets,
  (list) => {
    if (list.length === 0) {
      selectedKey.value = '';
    } else if (!list.some((tgt) => tgt.key === selectedKey.value)) {
      selectedKey.value = list[0].key;
    }
  },
  { immediate: true, deep: true },
);

const hasTargets = computed(() => props.targets.length > 0);
/** The field accepts input whenever there is a target — even while generating (the draft is queued). */
const composerEnabled = computed(() => SESSION_CAPABILITIES.composerSend && hasTargets.value);
/** Send is additionally blocked while the session is generating (one op per session). */
const canSend = computed(() => composerEnabled.value && !props.disabled);

const targetOptions = computed<SelectOption[]>(() =>
  props.targets.map((tgt) => ({ value: tgt.key, label: tgt.label })),
);
const selectedLabel = computed(
  () => props.targets.find((tgt) => tgt.key === selectedKey.value)?.label ?? '',
);

/** The under-field hint explains why the composer is inert / that a draft will wait for the run. */
const hint = computed(() => {
  if (!hasTargets.value) return t('generator.sessions.composer.noTargets');
  if (props.disabled) return t('generator.sessions.composer.queued');
  return '';
});

function onSend(): void {
  if (!canSend.value) return;
  const instruction = draft.value.trim();
  if (!instruction || !selectedKey.value) return;
  emit('send', { partKey: selectedKey.value, instruction });
  draft.value = '';
}

/** Enter submits, Shift+Enter inserts a newline (only when it can send). */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault();
    onSend();
  }
}
</script>

<template>
  <div class="flex flex-col gap-next-2 border-t border-next-border bg-next-card p-next-3">
    <!-- Which part does this refine? Clear label, or a compact selector when there are several. -->
    <div v-if="hasTargets" class="flex items-center gap-next-2 text-next-xs text-next-muted-foreground">
      <span class="shrink-0">{{ t('generator.sessions.composer.refines') }}</span>
      <Select
        v-if="targets.length > 1"
        v-model="selectedKey"
        size="sm"
        :options="targetOptions"
        :aria-label="t('generator.sessions.composer.targetLabel')"
        class="min-w-[10rem]"
      />
      <span v-else class="truncate font-next-medium text-next-fg">{{ selectedLabel }}</span>
    </div>

    <div class="flex items-end gap-next-2">
      <div class="flex min-w-0 flex-1 flex-col gap-next-1">
        <textarea
          v-model="draft"
          rows="1"
          :disabled="!composerEnabled"
          :placeholder="t('generator.sessions.composer.placeholder')"
          :aria-label="t('generator.sessions.composer.placeholder')"
          class="min-h-[2.5rem] w-full resize-none rounded-next-md border border-next-input bg-next-bg px-next-3 py-next-2 text-next-sm text-next-fg placeholder:text-next-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring disabled:cursor-not-allowed disabled:opacity-60"
          @keydown="onKeydown"
        />
        <span v-if="hint" class="text-next-xs text-next-muted-foreground">{{ hint }}</span>
      </div>
      <Tooltip :label="t('generator.sessions.composer.send')">
        <Button
          variant="secondary"
          size="icon"
          leading-icon="arrow-up"
          :disabled="!canSend || !draft.trim()"
          :aria-label="t('generator.sessions.composer.send')"
          @click="onSend"
        />
      </Tooltip>
    </div>
  </div>
</template>
