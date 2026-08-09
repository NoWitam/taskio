<script setup lang="ts">
// KnowledgeRefineBar — revise the WHOLE board with a sentence (spec §24.4).
//
// A draft is corrected by PROMPT, never by hand-editing the card (§25.7): the moment the board
// becomes editable, the composer is an editor with an extra step, and the agent stops learning
// what the user actually wanted.
//
// The prompt HISTORY sits above the field, collapsed. Each revision replaces the drafts, so
// without a record of what was asked, a user three iterations in cannot tell why the board looks
// the way it does.
import { computed, ref } from 'vue';
import FormField from '../../../ui/forms/FormField.vue';
import Textarea from '../../../ui/forms/Textarea.vue';
import Surface from '../../../ui/layout/Surface.vue';
import Button from '../../../ui/primitives/Button.vue';
import Text from '../../../ui/primitives/Text.vue';
import Accordion from '../../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../../ui/disclosure/AccordionItem.vue';
import { useI18n } from '../../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** Every instruction asked so far, oldest first. */
    history?: string[];
    /** The server's own bound on an instruction. */
    maxChars?: number;
    /** A revision is running: the field stays READABLE (readonly), never disabled. */
    busy?: boolean;
  }>(),
  { history: () => [], maxChars: 2000, busy: false },
);

const emit = defineEmits<{ (e: 'refine', instruction: string): void }>();

const { t } = useI18n();

const prompt = defineModel<string>({ default: '' });
const fieldRef = ref<InstanceType<typeof Textarea> | null>(null);

const canSubmit = computed(() => prompt.value.trim() !== '' && !props.busy);

function submit(): void {
  if (!canSubmit.value) return;
  emit('refine', prompt.value.trim());
}

/** Focus the field with the caret at the end — used by "revise this entry" on a card. */
function focusEnd(): void {
  fieldRef.value?.focus?.();
}

defineExpose({ focusEnd });
</script>

<template>
  <Surface
    bg="card"
    border
    radius="lg"
    class="sticky bottom-0 z-[var(--z-next-sticky)] flex flex-col gap-next-3 p-next-4"
  >
    <Accordion v-if="history.length > 0" type="multiple" class="flex flex-col">
      <AccordionItem
        value="history"
        icon="clock"
        :title="t('knowledge.compose.historyTitle', '', { count: history.length })"
      >
        <ol class="flex flex-col gap-next-2">
          <li v-for="(item, index) in history" :key="index" class="flex flex-col gap-next-0_5">
            <Text variant="caption" tone="muted">
              {{ t('knowledge.compose.historyItem', '', { number: index + 1 }) }}
            </Text>
            <Text variant="ui" :clamp="2">{{ item }}</Text>
          </li>
        </ol>
      </AccordionItem>
    </Accordion>

    <FormField :label="t('knowledge.compose.refineLabel')">
      <Textarea
        ref="fieldRef"
        v-model="prompt"
        auto-grow
        :rows="3"
        counter
        :maxlength="maxChars"
        :readonly="busy"
        :placeholder="t('knowledge.compose.refinePlaceholder')"
        data-refine-input
      />
    </FormField>

    <div class="flex items-center justify-end">
      <Button
        leading-icon="sparkles"
        :loading="busy"
        :disabled="!canSubmit"
        data-refine-submit
        @click="submit"
      >
        {{ t('knowledge.compose.refine') }}
      </Button>
    </div>
  </Surface>
</template>
