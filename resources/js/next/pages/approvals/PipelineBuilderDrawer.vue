<script setup lang="ts">
// PipelineBuilderDrawer — create / edit an approval pipeline (next frontend).
//
// Hosted inside ApprovalsModuleLayout's query-driven Drawer. Owns its OWN header
// (title + Cancel/Save) and a scrolling body, so the Drawer adds no chrome.
//
// Edits a LOCAL state seeded once from the store's prefetched detail (when
// editing). `structuredClone` throws on Vue reactive proxies, so detail is cloned
// via `clonePlain` (JSON). The component is keyed by id in the layout, so it
// remounts (and re-seeds) per pipeline — seed once in setup.
//
// Layout: metadata fields (name / icon / description) + an ORDERED stage list.
// Each stage row has name, icon, description, an approver_type picker (Person|AI)
// and — for `user` — a person picker (UserSelect) for approver_id. Reorder is via
// ▲▼ buttons (move up/down); add/remove with a min of ONE stage enforced
// client-side. Submitting builds the EXACT FormRequest payload (order = array
// index; AI stages send approver_id: null) and maps server 422 → field errors /
// a translated toast (incl. the active-processes message).
import { computed, reactive, ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import IconInput from '../../ui/forms/IconInput.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import BotSelect from '../../ui/forms/BotSelect.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import { useApprovalPipelinesStore, activeProcessesError } from '../../app/stores/approvalPipelines';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type {
  ApprovalPipeline,
  ApproverType,
  PipelineStagePayload,
  PipelineWritePayload,
} from './types';
import { resolveApprover } from './approver';
import { buildStagePayload } from './pipelinePayload';

const props = defineProps<{
  /** Pipeline id to edit, or null to create a new one. */
  pipelineId: string | null;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', pipeline: ApprovalPipeline): void;
}>();

const { t } = useI18n();
const store = useApprovalPipelinesStore();
const toast = useToast();

// Deep-clone PLAIN data via JSON — reads THROUGH Vue reactive proxies (unlike
// structuredClone, which throws DataCloneError on a proxy).
function clonePlain<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

const isEdit = computed(() => props.pipelineId !== null);

// --- Local builder state (one editable stage row) -------------------------
interface StageDraft {
  /** A stable local key so ▲▼ reorder remounts cleanly. */
  uid: string;
  name: string;
  icon: string | null;
  /** Always a string in local state ('' = unset); nulled in the payload. */
  description: string;
  approver_type: ApproverType;
  approver_id: string | null;
  /** Seed for UserSelect so an existing user approver renders by name immediately. */
  approverSeed: Array<{ id: string; name: string; email?: string | null; avatar?: string | null }>;
  /** Seed for BotSelect so an existing bot approver renders by name immediately. */
  botSeed: Array<{ id: string; name: string }>;
}

let uidSeq = 0;
function nextUid(): string {
  return `stage-${(uidSeq += 1)}`;
}

function emptyStage(): StageDraft {
  return {
    uid: nextUid(),
    name: '',
    icon: null,
    description: '',
    approver_type: 'user',
    approver_id: null,
    approverSeed: [],
    botSeed: [],
  };
}

const form = reactive<{
  name: string;
  icon: string | null;
  /** Always a string in local state ('' = unset); nulled in the payload. */
  description: string;
  stages: StageDraft[];
}>({
  name: '',
  icon: null,
  description: '',
  stages: [emptyStage()],
});

// Seed ONCE from the prefetched detail when editing (the layout keys this
// component by id, so it remounts per pipeline → setup runs fresh each time).
const detailError = ref(false);
if (isEdit.value) {
  const detail = store.detail && store.detail.id === props.pipelineId ? store.detail : null;
  if (detail) {
    const cloned = clonePlain(detail);
    form.name = cloned.name;
    form.icon = cloned.icon;
    form.description = cloned.description ?? '';
    form.stages = cloned.stages
      .slice()
      .sort((a, b) => a.order - b.order)
      .map((s) => {
        // Resolve the existing approver (prefers approver_identity → user|bot|null).
        const resolved = resolveApprover(s);
        return {
          uid: nextUid(),
          name: s.name,
          icon: s.icon,
          description: s.description ?? '',
          approver_type: s.approver_type,
          // approver_id is meaningful for user/bot stages only (ai → null).
          approver_id:
            s.approver_type === 'ai' ? null : resolved ? resolved.id : null,
          approverSeed:
            resolved && !resolved.isBot
              ? [{ id: resolved.id, name: resolved.name ?? '', avatar: resolved.avatar ?? null }]
              : [],
          botSeed:
            resolved && resolved.isBot
              ? [{ id: resolved.id, name: resolved.name ?? '' }]
              : [],
        };
      });
    if (form.stages.length === 0) form.stages = [emptyStage()];
  } else {
    // Deep link without a prefetch (store has no detail) — show a clear error.
    detailError.value = true;
  }
}

// --- Approver type picker options (3-way: Person | AI | Bot) ---------------
const approverOptions = computed<SegmentOption<ApproverType>[]>(() => [
  { value: 'user', label: t('approvals.builder.approverUser'), icon: 'user' },
  { value: 'ai', label: t('approvals.builder.approverAi'), icon: 'sparkles' },
  { value: 'bot', label: t('approvals.builder.approverBot'), icon: 'sparkles' },
]);

// IconInput speaks IconName | null; we store legacy icon strings, so bridge them
// with a cast (the icon picker only offers the `next` set; existing legacy values
// round-trip untouched).
const pipelineIconModel = computed<IconName | null>({
  get: () => (form.icon ? (form.icon as IconName) : null),
  set: (value) => {
    form.icon = value ?? null;
  },
});
// Read/write bridges for a stage's legacy icon string ↔ IconInput's IconName.
function stageIconValue(stage: StageDraft): IconName | null {
  return stage.icon ? (stage.icon as IconName) : null;
}
function setStageIcon(stage: StageDraft, value: IconName | null): void {
  stage.icon = value ?? null;
}

// --- Stage list mutations -------------------------------------------------
function addStage(): void {
  form.stages.push(emptyStage());
}

function removeStage(index: number): void {
  if (form.stages.length <= 1) return; // min 1 stage (client-side)
  form.stages.splice(index, 1);
}

function moveStage(index: number, dir: -1 | 1): void {
  const target = index + dir;
  if (target < 0 || target >= form.stages.length) return;
  const [s] = form.stages.splice(index, 1);
  form.stages.splice(target, 0, s);
}

function onApproverTypeChange(stage: StageDraft, type: ApproverType | null): void {
  stage.approver_type = type ?? 'user';
  // Switching the type ALWAYS clears the stale approver_id so a user id can never
  // be sent as a bot (or vice versa); an `ai` stage carries no id at all.
  stage.approver_id = null;
}

// --- Validation (client-side, mirrors the FormRequest) --------------------
const errors = reactive<{ name: string | null; stages: Record<string, { name?: string; approver?: string }> }>({
  name: null,
  stages: {},
});

function validate(): boolean {
  errors.name = null;
  errors.stages = {};
  let ok = true;

  if (!form.name.trim()) {
    errors.name = t('approvals.builder.validation.nameRequired');
    ok = false;
  }
  form.stages.forEach((stage) => {
    const stageErr: { name?: string; approver?: string } = {};
    if (!stage.name.trim()) {
      stageErr.name = t('approvals.builder.validation.stageNameRequired');
      ok = false;
    }
    // A user OR bot stage needs a selected approver id; an ai stage needs none.
    if (stage.approver_type !== 'ai' && !stage.approver_id) {
      stageErr.approver =
        stage.approver_type === 'bot'
          ? t('approvals.builder.validation.botApproverRequired')
          : t('approvals.builder.validation.approverRequired');
      ok = false;
    }
    if (stageErr.name || stageErr.approver) errors.stages[stage.uid] = stageErr;
  });

  return ok;
}

// --- Submit ---------------------------------------------------------------
const saving = ref(false);

function buildPayload(): PipelineWritePayload {
  const stages: PipelineStagePayload[] = form.stages.map((stage) => buildStagePayload(stage));
  return {
    name: form.name.trim(),
    icon: form.icon || null,
    description: form.description.trim() || null,
    stages,
  };
}

/** Map a server 422 validation bag onto the local field errors (best-effort). */
function applyServerErrors(err: unknown): void {
  const bag = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  if (!bag) return;
  if (bag.name?.length) errors.name = bag.name[0];
  // stages.<i>.name / .approver_id / .approver_type → map back to the row by index.
  Object.entries(bag).forEach(([key, msgs]) => {
    const m = key.match(/^stages\.(\d+)\.(name|approver_id|approver_type)$/);
    if (!m || !msgs.length) return;
    const stage = form.stages[Number(m[1])];
    if (!stage) return;
    const existing = errors.stages[stage.uid] ?? {};
    if (m[2] === 'name') existing.name = msgs[0];
    else existing.approver = msgs[0]; // approver_id OR approver_type → the picker
    errors.stages[stage.uid] = existing;
  });
}

async function onSubmit(): Promise<void> {
  if (saving.value) return;
  if (!validate()) return;
  saving.value = true;
  const payload = buildPayload();
  try {
    const result = isEdit.value && props.pipelineId
      ? await store.updatePipeline(props.pipelineId, payload)
      : await store.createPipeline(payload);
    toast.success(t(isEdit.value ? 'approvals.builder.toasts.updated' : 'approvals.builder.toasts.created'));
    emit('saved', result);
  } catch (err: unknown) {
    const active = activeProcessesError(err);
    if (active) {
      toast.danger(t(active.messageKey));
    } else {
      applyServerErrors(err);
      toast.danger(t('approvals.builder.toasts.error'));
    }
  } finally {
    saving.value = false;
  }
}

function onCancel(): void {
  emit('close');
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Header: title + actions (the drawer adds no chrome). -->
    <header class="flex items-center justify-between gap-next-3 border-b border-next-border p-next-4">
      <h2 class="min-w-0 truncate text-next-lg font-next-semibold text-next-fg">
        {{ isEdit ? t('approvals.builder.editTitle') : t('approvals.builder.createTitle') }}
      </h2>
      <div class="flex shrink-0 items-center gap-next-2">
        <Button variant="ghost" :disabled="saving" @click="onCancel">
          {{ t('approvals.builder.cancel') }}
        </Button>
        <Button leading-icon="check" :loading="saving" :disabled="detailError" @click="onSubmit">
          {{ saving ? t('approvals.builder.saving') : t('approvals.builder.save') }}
        </Button>
      </div>
    </header>

    <!-- Deep-link without a prefetched detail → a clear error (no blank form). -->
    <EmptyState
      v-if="detailError"
      variant="error"
      class="m-next-4"
      :title="t('approvals.builder.editTitle')"
      :description="t('approvals.builder.detailError')"
    >
      <template #action>
        <Button variant="outline" size="sm" @click="onCancel">
          {{ t('approvals.builder.cancel') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Body: metadata + ordered stage list (its own scroll region). -->
    <div v-else class="flex min-h-0 flex-1 flex-col gap-next-6 overflow-y-auto p-next-4">
      <!-- Metadata -->
      <div class="flex flex-col gap-next-4">
        <FormField :label="t('approvals.builder.nameLabel')" required :error="errors.name ?? undefined">
          <TextInput v-model="form.name" :placeholder="t('approvals.builder.namePlaceholder')" />
        </FormField>

        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
          <FormField :label="t('approvals.builder.iconLabel')">
            <IconInput v-model="pipelineIconModel" />
          </FormField>
          <FormField :label="t('approvals.builder.descriptionLabel')">
            <Textarea
              v-model="form.description"
              :rows="2"
              :placeholder="t('approvals.builder.descriptionPlaceholder')"
            />
          </FormField>
        </div>
      </div>

      <!-- Stages -->
      <section class="flex flex-col gap-next-3">
        <div class="flex items-baseline justify-between gap-next-3">
          <div>
            <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('approvals.builder.stagesTitle') }}</h3>
            <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('approvals.builder.stagesHint') }}</p>
          </div>
          <Button variant="outline" size="sm" leading-icon="plus" @click="addStage">
            {{ t('approvals.builder.addStage') }}
          </Button>
        </div>

        <ol class="flex flex-col gap-next-3">
          <li
            v-for="(stage, index) in form.stages"
            :key="stage.uid"
            class="rounded-next-lg border border-next-border bg-next-card p-next-4"
          >
            <div class="mb-next-3 flex items-center justify-between gap-next-2">
              <span class="flex items-center gap-next-2 text-next-sm font-next-medium text-next-fg">
                <span
                  class="flex h-6 w-6 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-2xs font-next-semibold text-next-primary-subtle-foreground"
                  aria-hidden="true"
                >
                  {{ index + 1 }}
                </span>
                {{ t('approvals.builder.stageLabel', '', { index: index + 1 }) }}
              </span>
              <!-- Trailing controls: conditional remove (X) BEFORE the permanent
                   ▲▼ reorder controls; permanent controls never move. -->
              <div class="flex items-center gap-next-1">
                <Button
                  v-if="form.stages.length > 1"
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="x"
                  :aria-label="t('approvals.builder.removeStage')"
                  @click="removeStage(index)"
                />
                <Button
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="chevron-up"
                  :disabled="index === 0"
                  :aria-label="t('approvals.builder.moveUp')"
                  @click="moveStage(index, -1)"
                />
                <Button
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="chevron-down"
                  :disabled="index === form.stages.length - 1"
                  :aria-label="t('approvals.builder.moveDown')"
                  @click="moveStage(index, 1)"
                />
              </div>
            </div>

            <div class="flex flex-col gap-next-4">
              <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[1fr_12rem]">
                <FormField
                  :label="t('approvals.builder.stageNameLabel')"
                  required
                  :error="errors.stages[stage.uid]?.name"
                >
                  <TextInput v-model="stage.name" :placeholder="t('approvals.builder.stageNamePlaceholder')" />
                </FormField>
                <FormField :label="t('approvals.builder.stageIconLabel')">
                  <IconInput
                    :model-value="stageIconValue(stage)"
                    @update:model-value="(v) => setStageIcon(stage, v)"
                  />
                </FormField>
              </div>

              <FormField :label="t('approvals.builder.stageDescriptionLabel')">
                <Textarea
                  v-model="stage.description"
                  :rows="2"
                  :placeholder="t('approvals.builder.stageDescriptionPlaceholder')"
                />
              </FormField>

              <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
                <FormField :label="t('approvals.builder.approverTypeLabel')">
                  <SegmentedControl
                    :model-value="stage.approver_type"
                    :options="approverOptions"
                    :aria-label="t('approvals.builder.approverTypeLabel')"
                    equal-width
                    @update:model-value="(v) => onApproverTypeChange(stage, v)"
                  />
                </FormField>

                <!-- user → person picker, bot → bot picker, ai → a static note. -->
                <FormField
                  v-if="stage.approver_type === 'user'"
                  :label="t('approvals.builder.approverUserLabel')"
                  required
                  :error="errors.stages[stage.uid]?.approver"
                >
                  <UserSelect
                    v-model="stage.approver_id"
                    :seed="stage.approverSeed"
                    :aria-invalid="!!errors.stages[stage.uid]?.approver"
                    :placeholder="t('approvals.builder.approverUserPlaceholder')"
                    :aria-label="t('approvals.builder.approverUserLabel')"
                  />
                </FormField>
                <FormField
                  v-else-if="stage.approver_type === 'bot'"
                  :label="t('approvals.builder.approverBotLabel')"
                  required
                  :error="errors.stages[stage.uid]?.approver"
                >
                  <BotSelect
                    v-model="stage.approver_id"
                    :seed="stage.botSeed"
                    :aria-invalid="!!errors.stages[stage.uid]?.approver"
                    :placeholder="t('approvals.builder.approverBotPlaceholder')"
                    :aria-label="t('approvals.builder.approverBotLabel')"
                  />
                </FormField>
                <div v-else class="flex items-end">
                  <p class="flex items-center gap-next-2 rounded-next-md bg-next-muted px-next-3 py-next-2 text-next-xs text-next-muted-foreground">
                    <Icon name="sparkles" class="shrink-0" aria-hidden="true" />
                    {{ t('approvals.builder.approverAiHint') }}
                  </p>
                </div>
              </div>
            </div>
          </li>
        </ol>
      </section>
    </div>
  </div>
</template>
