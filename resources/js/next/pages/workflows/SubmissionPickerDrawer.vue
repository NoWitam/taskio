<script setup lang="ts">
// SubmissionPickerDrawer — a right Drawer that lists a form's approved submissions
// for SELECTION (the workflow run-now "Pick submission" flow, §6 / B8).
//
// Thin shell around the SHARED SubmissionsBrowser (in `selectable` mode) so the
// picker's filters + Saved Views tabs are IDENTICAL to the Forms submissions LIST
// page — the two can never drift. The browser renders the FilterBar (search /
// source / indexed / sort / date), the saved-views toolbar, and the cursor-
// paginated SubmissionCard list; here each card is a whole-card pick affordance
// that emits `select(id, submission)` and CLOSES the drawer.
//
// Scope: `formId` is the workflow trigger's bound form (a uuid) or `null` ("any
// form"). When null the drawer shows a FormSelect step FIRST; once a form is chosen
// the browser mounts scoped to it (a "change form" link returns to the chooser).
//
// NOTE: in `selectable` mode the browser lists ONLY active (approved) submissions —
// no Active/Deleted bucket, no delete/restore actions. All strings via i18n.
import { computed, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import FormField from '../../ui/forms/FormField.vue';
import FormSelect from '../../ui/forms/FormSelect.vue';
import Button from '../../ui/primitives/Button.vue';
import SubmissionsBrowser from '../forms/SubmissionsBrowser.vue';
import { useI18n } from '../../app/i18n';
import type { FormSubmission } from '../forms/types';

const props = withDefaults(defineProps<{ formId?: string | null }>(), { formId: null });

const emit = defineEmits<{
  (e: 'select', submissionId: string, submission: FormSubmission): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

// --- Form scope (bound form, or a chosen one for the "any form" case) --------
const chosenFormId = ref<string | null>(props.formId);
watch(
  () => props.formId,
  (id) => {
    chosenFormId.value = id;
  },
);
const effectiveFormId = computed<string | null>(() => props.formId ?? chosenFormId.value);
const isAnyForm = computed(() => props.formId == null);
// A FormSelect step is shown only when the workflow binds no form AND none picked.
const needsForm = computed(() => isAnyForm.value && !chosenFormId.value);

function changeForm(): void {
  chosenFormId.value = null;
}

function onSelected(submissionId: string, submission: FormSubmission): void {
  emit('select', submissionId, submission);
  open.value = false;
}
</script>

<template>
  <!-- The picker hosts the FULL SubmissionsBrowser (search + source + indexed + sort +
       date + saved-views tabs + list), so it needs room: `4xl` (the widest fixed size,
       ~72rem — roughly double the old `xl`/42rem) keeps the filter row uncramped while
       the Drawer's own `max-w-[calc(100vw-2rem)]` cap keeps it responsive on small
       screens. -->
  <Drawer v-model:open="open" side="right" size="4xl" :aria-label="t('workflows.run.picker.title')">
    <template #title>{{ t('workflows.run.picker.title') }}</template>

    <!-- Step 1: choose a form (only when the workflow binds no form). -->
    <div v-if="needsForm" class="flex flex-col gap-next-4">
      <p class="text-next-sm text-next-muted-foreground">
        {{ t('workflows.run.picker.chooseFormHint') }}
      </p>
      <FormField :label="t('workflows.run.picker.chooseForm')">
        <FormSelect v-model="chosenFormId" :aria-label="t('workflows.run.picker.chooseForm')" />
      </FormField>
    </div>

    <!-- Step 2: the scoped submissions browser (identical filters + saved views as
         the list page), in selection mode. -->
    <div v-else class="flex flex-col gap-next-4">
      <div v-if="isAnyForm" class="flex justify-start">
        <Button variant="ghost" size="sm" leading-icon="arrow-left" @click="changeForm">
          {{ t('workflows.run.picker.changeForm') }}
        </Button>
      </div>

      <SubmissionsBrowser
        v-if="effectiveFormId"
        :key="effectiveFormId"
        :form-id="effectiveFormId"
        selectable
        @select="onSelected"
      />
    </div>
  </Drawer>
</template>
