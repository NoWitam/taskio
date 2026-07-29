<script setup lang="ts">
// AiUsageCapEditor — the OWNER-only monthly $ cap control (R2 sub-stage 4).
//
// Renders ONLY when the caller passed `can_manage` (the page gates it); a member never sees this. Three
// mutually-exclusive modes map to the backend cap semantics 1:1:
//   • "Set a limit"       → a positive $ amount  (the workspace cap)
//   • "No limit"          → 0                     (explicit UNLIMITED for this workspace)
//   • "Use platform default" → null              (CLEAR the override, inherit the env default)
// It emits `submit(cap: number | null)`; the page confirms, calls the store, toasts, and the refreshed
// summary re-seeds this form. Seeded from the current `cap_source` so the open state reflects reality.
import { computed, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import type { AiUsageSummary } from '../../app/stores/aiUsage';

type CapMode = 'limit' | 'unlimited' | 'default';

const props = defineProps<{
  summary: AiUsageSummary;
  saving: boolean;
}>();

const emit = defineEmits<{ (e: 'submit', cap: number | null): void }>();

const { t } = useI18n();

/** Seed the mode from the effective cap source (workspace → limit; default → default; unlimited → unlimited). */
function modeFor(summary: AiUsageSummary): CapMode {
  if (summary.cap_source === 'workspace') return 'limit';
  if (summary.cap_source === 'default') return 'default';
  return 'unlimited';
}

const mode = ref<CapMode>(modeFor(props.summary));
const amount = ref<number | null>(props.summary.cost_cap > 0 ? props.summary.cost_cap : null);

// Re-seed whenever the summary changes (after a successful save the refreshed summary flows back in).
watch(
  () => props.summary,
  (s) => {
    mode.value = modeFor(s);
    amount.value = s.cost_cap > 0 ? s.cost_cap : null;
  },
);

// RadioGroup models a string|null; bridge it to the typed CapMode.
const modeModel = computed<string | null>({
  get: () => mode.value,
  set: (value) => {
    if (value === 'limit' || value === 'unlimited' || value === 'default') mode.value = value;
  },
});

const amountValid = computed(() => amount.value != null && Number.isFinite(amount.value) && amount.value >= 0);
const canSave = computed(() => !props.saving && (mode.value !== 'limit' || amountValid.value));

function onSubmit(): void {
  if (!canSave.value) return;
  if (mode.value === 'limit') emit('submit', Number(amount.value));
  else if (mode.value === 'unlimited') emit('submit', 0);
  else emit('submit', null);
}
</script>

<template>
  <form class="flex flex-col gap-next-4" novalidate @submit.prevent="onSubmit">
    <FormField :label="t('workspaces.aiUsage.editor.modeLabel')">
      <RadioGroup v-model="modeModel" :aria-label="t('workspaces.aiUsage.editor.modeLabel')">
        <Radio
          value="limit"
          :label="t('workspaces.aiUsage.editor.limitOption')"
          :description="t('workspaces.aiUsage.editor.limitHelp')"
        />
        <Radio
          value="unlimited"
          :label="t('workspaces.aiUsage.editor.unlimitedOption')"
          :description="t('workspaces.aiUsage.editor.unlimitedHelp')"
        />
        <Radio
          value="default"
          :label="t('workspaces.aiUsage.editor.defaultOption')"
          :description="t('workspaces.aiUsage.editor.defaultHelp')"
        />
      </RadioGroup>
    </FormField>

    <!-- The $ amount only matters for "Set a limit". -->
    <FormField
      v-if="mode === 'limit'"
      :label="t('workspaces.aiUsage.editor.amountLabel')"
      :description="t('workspaces.aiUsage.editor.amountHint')"
    >
      <NumberInput
        v-model="amount"
        :min="0"
        :step="1"
        prefix="$"
        class="max-w-[12rem]"
        :aria-label="t('workspaces.aiUsage.editor.amountLabel')"
      />
    </FormField>

    <div class="flex items-center gap-next-3">
      <Button
        type="submit"
        variant="primary"
        leading-icon="check"
        :loading="saving"
        :disabled="!canSave"
      >
        {{ t('workspaces.aiUsage.editor.save') }}
      </Button>
      <p class="text-next-xs text-next-muted-foreground">
        {{ t('workspaces.aiUsage.editor.estimatedNote') }}
      </p>
    </div>
  </form>
</template>
