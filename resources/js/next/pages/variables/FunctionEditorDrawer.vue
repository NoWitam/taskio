<script setup lang="ts">
// FunctionEditorDrawer — the create / edit form for a CUSTOM FUNCTION.
//
// A right Drawer hosting four sections:
//   1. IDENTITY   — `name` (required) + an optional `description`.
//   2. SIGNATURE  — an INPUT-type Select + a RETURN-type Select (VariableType options).
//   3. ARGS       — a repeatable builder of typed named args ({name, description?, type}). Names are
//                   validated client-side against the SAME rule the backend enforces (unique, safe
//                   identifier, never the reserved scope names input/element/index).
//   4. BODY       — the pipeline over {input + args}. This is the SHARED `VariableReferenceEditor`
//                   (the exact body every reference surface uses) rooted at a `source:'scope'` draft
//                   `{path:'input', type:input_type}`, fed the function's {input, <argName>…} SCOPE
//                   VARS as its op-argument pool (`arg-variables`) — NEVER global variables. The
//                   editor's own terminal gate (`pipelineSatisfies`) requires the body to END on the
//                   RETURN type, so a wrong-terminal body cannot be saved. Because the SAME scope vars
//                   are threaded at every pipeline level and the shared editor already MERGES an array
//                   transform's `element`/`index` on top, a map/filter/sort/reduce inside the body
//                   shows the whole FRAME STACK (element/index + input/args) with no extra wiring.
//
// The form is PURE: it validates client-side (mirroring FunctionDefinitionValidator — the server
// stays authoritative) and, when valid, EMITS a `{name, description?, input_type, args, return_type,
// body}` payload. The parent (FunctionsView) owns the store call, toasts, and feeds back 422 errors.
// A function's identity is its editable NAME; the wire op id (`fn:<uuid>`) is never shown here.
import { computed, onMounted, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Alert from '../../ui/feedback/Alert.vue';
import VariableReferenceEditor from '../../ui/variables/VariableReferenceEditor.vue';
import PipelineArgLiteralInput from '../../ui/editor/extensions/PipelineArgLiteralInput.vue';
import ValueOrVariableField from '../workflows/ValueOrVariableField.vue';
import { buildVariableTree } from '../../ui/variables/variableTree';
import {
  getVariableIconLabel,
  getVariableIconName,
  pipelineSatisfies,
} from '../../ui/editor/extensions/operationHelpers';
import { argToUnion, unionToArg } from '../workflows/argVariableAdapters';
import { CONDITION_LIMITS } from '../workflows/workflowConditions';
import {
  FUNCTION_VARIABLE_TYPES,
  argToDraft,
  draftToArg,
  emptyArgDraft,
  functionOpId,
  functionScopeVars,
  validateArgDrafts,
  type FunctionArgDraft,
} from './functions';
import { useI18n } from '../../app/i18n';
import type {
  CatalogVariable,
  CustomFunction,
  CustomFunctionWritePayload,
  WorkflowFieldPipelineStep,
  WorkflowFieldValue,
  WorkflowVariableType,
} from '../workflows/types';
import type { VariableNode, VariableRefDraft } from '../../ui/variables/types';
import type {
  VariableArgValue,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The function being edited, or null/undefined for a create. */
    func?: CustomFunction | null;
    /**
     * The RESOLVED operations catalog (built-in ops + every `fn:<uuid>` function op, labels attached)
     * the body pipeline runs on. Fed by the view from the workflow catalog. Empty ⇒ only the body's
     * literal ops are unavailable until it loads (the editor still renders).
     */
    operationsCatalog?: VariableOperationDefinition[];
    /** True while the parent's store call is in flight (disables Save + inputs). */
    submitting?: boolean;
    /** Backend 422 field errors keyed by dotted path (name / description / input_type / return_type / args.* / body*). */
    serverErrors?: Record<string, string> | null;
  }>(),
  { func: null, operationsCatalog: () => [], submitting: false, serverErrors: null },
);

const emit = defineEmits<{
  submit: [CustomFunctionWritePayload];
  close: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const isEdit = computed(() => props.func != null);

// --- Identity ---------------------------------------------------------------
const name = ref('');
const description = ref('');

// --- Signature --------------------------------------------------------------
const inputType = ref<WorkflowVariableType>('text');
const returnType = ref<WorkflowVariableType>('text');

const typeOptions = computed<SelectOption[]>(() =>
  FUNCTION_VARIABLE_TYPES.map((type) => ({
    value: type,
    label: getVariableIconLabel(type as VariablePrimitive),
    icon: getVariableIconName(type as VariablePrimitive),
  })),
);

// --- Args -------------------------------------------------------------------
const args = ref<FunctionArgDraft[]>([]);

function addArg(): void {
  args.value = [...args.value, emptyArgDraft()];
}
function removeArg(id: string): void {
  args.value = args.value.filter((a) => a.id !== id);
}

/** Reactive client arg validation (index → error code), mirroring the backend. */
const argErrors = computed(() => validateArgDrafts(args.value));
const hasArgErrors = computed(() => Object.keys(argErrors.value).length > 0);

/** The message under an arg row: the server error wins, else the localized client code. */
function argErrorText(index: number): string | null {
  const serverName = props.serverErrors?.[`args.${index}.name`];
  if (serverName) return serverName;
  const serverType = props.serverErrors?.[`args.${index}.type`];
  if (serverType) return serverType;
  const code = argErrors.value[index];
  return code ? t(`variables.functions.form.errors.${code}`) : null;
}
function argDescriptionError(index: number): string | null {
  return props.serverErrors?.[`args.${index}.description`] ?? null;
}

// --- Body pipeline ----------------------------------------------------------
// The body is a scope-rooted reference draft: source `input`, base type = the input type, plus the
// authored pipeline. The default-when-empty is suppressed (a function has no such concept) and the
// change-source affordance is off (the source is always `input`).
const bodyDraft = ref<VariableRefDraft>({
  source: 'scope',
  path: 'input',
  type: 'text',
  pipeline: [],
  default: null,
});

/** The input type as a pipeline primitive (the body's base type). */
const inputPrimitive = computed<VariablePrimitive>(() => inputType.value as VariablePrimitive);
/** The body's required terminal — its declared RETURN type. */
const resultTypes = computed<VariablePrimitive[]>(() => [returnType.value as VariablePrimitive]);

/**
 * The {input, <argName>…} SCOPE variables the body's op-argument pool offers (as `source:'scope'`).
 * NOT global variables — the body sees ONLY its own frame; an array transform merges element/index on top.
 */
const scopeVars = computed(() =>
  functionScopeVars(inputType.value, args.value, t('variables.functions.form.inputScopeName')),
);
/** The scope tree the reference editor's read-only source header resolves `input` against. */
const scopeTree = computed<VariableNode[]>(() => buildVariableTree(scopeVars.value, {}));

/**
 * The scope pool as the value-or-variable field's catalog type. A `source:'scope'` var is
 * structurally a `CatalogVariable` but for the narrower `source` union, so the cast is asserted here
 * ONCE (the runtime only ever feeds it into `buildVariableTree`, which accepts the scope source).
 */
const scopePool = computed<CatalogVariable[]>(() => scopeVars.value as unknown as CatalogVariable[]);

/**
 * The body's operations catalog: the resolved catalog MINUS this function's own `fn:<uuid>` op (a
 * function may never call itself — the backend rejects it too, this just keeps it out of the add menu).
 */
const bodyCatalog = computed<VariableOperationDefinition[]>(() => {
  const selfId = props.func ? functionOpId(props.func.id) : null;
  return selfId ? props.operationsCatalog.filter((op) => op.id !== selfId) : props.operationsCatalog;
});

/** Whether the body pipeline ENDS on the return type — the same gate the editor's status strip uses. */
const bodyValid = computed(() =>
  pipelineSatisfies(bodyCatalog.value, inputPrimitive.value, bodyDraft.value.pipeline, resultTypes.value),
);

// --- Wire <-> editor step projection (the same shape a condition pipeline uses) --
let stepSeq = 0;
function nextStepId(): string {
  stepSeq += 1;
  return `fn-body-step-${stepSeq}`;
}
function toWireStep(step: VariablePipelineStep): WorkflowFieldPipelineStep {
  return { op: step.operationId, args: step.args as Record<string, unknown> };
}
function fromWireStep(wire: WorkflowFieldPipelineStep): VariablePipelineStep {
  return {
    stepId: nextStepId(),
    operationId: wire.op,
    args: (wire.args ?? {}) as VariablePipelineStep['args'],
    outputType: props.operationsCatalog.find((op) => op.id === wire.op)?.outputType ?? 'text',
  };
}

// --- Seeding ----------------------------------------------------------------
let seeding = false;

function seed(): void {
  seeding = true;
  clearClientErrors();
  const fn = props.func;
  if (fn) {
    name.value = fn.name;
    description.value = fn.description ?? '';
    inputType.value = fn.input_type;
    returnType.value = fn.return_type;
    args.value = (fn.args ?? []).map(argToDraft);
    bodyDraft.value = {
      source: 'scope',
      path: 'input',
      type: fn.input_type,
      pipeline: (fn.body ?? []).map(fromWireStep),
      default: null,
    };
  } else {
    name.value = '';
    description.value = '';
    inputType.value = 'text';
    returnType.value = 'text';
    args.value = [];
    bodyDraft.value = { source: 'scope', path: 'input', type: 'text', pipeline: [], default: null };
  }
  // Release the guard AFTER the reactive flush so the inputType watch doesn't wipe the seeded body.
  void Promise.resolve().then(() => {
    seeding = false;
  });
}

// Re-seed whenever the drawer OPENS; onMounted covers a drawer mounted already-open.
watch(open, (isOpen) => {
  if (isOpen) seed();
});
onMounted(() => {
  if (open.value) seed();
});

// Changing the input type re-roots the body (its base type changed), so the pipeline resets — the
// SAME rule the reference editor applies when its source changes. Skipped while seeding an edit.
watch(inputType, (type) => {
  if (seeding) return;
  bodyDraft.value = { source: 'scope', path: 'input', type, pipeline: [], default: null };
});

// --- Validation + submit ----------------------------------------------------
const clientNameError = ref<string | null>(null);

function clearClientErrors(): void {
  clientNameError.value = null;
}

const nameErrorText = computed(
  () => props.serverErrors?.name ?? (clientNameError.value ? t(clientNameError.value) : null),
);
const inputTypeErrorText = computed(() => props.serverErrors?.input_type ?? null);
const returnTypeErrorText = computed(() => props.serverErrors?.return_type ?? null);
const descriptionErrorText = computed(() => props.serverErrors?.description ?? null);
/** The body-level 422 (a cycle, a scope-only violation, a terminal mismatch the server re-checks). */
const bodyErrorText = computed(() => {
  const errors = props.serverErrors;
  if (!errors) return null;
  for (const [key, message] of Object.entries(errors)) {
    if (key === 'body' || key.startsWith('body.')) return message;
  }
  return null;
});

/**
 * The Save gate: the body must hit its return TERMINAL (the reference-editor gate) AND every arg must
 * be valid (no reserved/duplicate/invalid/empty name). The NAME requirement is checked in `submit()`
 * so a fresh identity function stays saveable and an empty name surfaces its own message on attempt.
 */
const canSave = computed(() => !props.submitting && bodyValid.value && !hasArgErrors.value);

function submit(): void {
  clearClientErrors();
  let ok = true;

  if (name.value.trim() === '') {
    clientNameError.value = 'variables.functions.form.errors.nameRequired';
    ok = false;
  }
  if (hasArgErrors.value) ok = false;
  if (!bodyValid.value) ok = false;

  if (!ok) return;

  const payload: CustomFunctionWritePayload = {
    name: name.value.trim(),
    input_type: inputType.value,
    return_type: returnType.value,
    args: args.value.map(draftToArg),
    body: bodyDraft.value.pipeline.map(toWireStep),
  };
  const trimmedDescription = description.value.trim();
  if (trimmedDescription !== '') payload.description = trimmedDescription;

  emit('submit', payload);
}

function cancel(): void {
  open.value = false;
  emit('close');
}

/**
 * STRUCTURAL ENTRIES (Defect-3): a CHOICE entry (its `resultTypes` is exactly `['enum']`) must map INTO
 * the destination options, so it threads `targetOptions` to the nested field; other entries thread none.
 */
function isChoiceEntryTypes(types: unknown): boolean {
  return Array.isArray(types) && types.length === 1 && types[0] === 'enum';
}
</script>

<template>
  <Drawer
    v-model:open="open"
    side="right"
    size="xl"
    :show-close="false"
    :aria-label="isEdit ? t('variables.functions.form.editTitle') : t('variables.functions.form.createTitle')"
  >
    <template #title>
      {{ isEdit ? t('variables.functions.form.editTitle') : t('variables.functions.form.createTitle') }}
    </template>

    <form class="flex flex-col gap-next-6" @submit.prevent="submit">
      <!-- 1. Identity ---------------------------------------------------- -->
      <section class="flex flex-col gap-next-4">
        <FormField
          :label="t('variables.functions.form.nameLabel')"
          required
          :error="nameErrorText ?? undefined"
        >
          <TextInput
            v-model="name"
            :disabled="submitting"
            :placeholder="t('variables.functions.form.namePlaceholder')"
          />
        </FormField>

        <FormField
          :label="t('variables.functions.form.descriptionLabel')"
          :error="descriptionErrorText ?? undefined"
        >
          <Textarea
            v-model="description"
            :disabled="submitting"
            :rows="2"
            :placeholder="t('variables.functions.form.descriptionPlaceholder')"
          />
        </FormField>
      </section>

      <!-- 2. Signature --------------------------------------------------- -->
      <section class="flex flex-col gap-next-4">
        <div class="grid grid-cols-1 gap-next-4 sm:grid-cols-2">
          <FormField
            :label="t('variables.functions.form.inputTypeLabel')"
            :description="t('variables.functions.form.inputTypeHint')"
            :error="inputTypeErrorText ?? undefined"
          >
            <Select
              v-model="inputType"
              :options="typeOptions"
              :disabled="submitting"
              :aria-label="t('variables.functions.form.inputTypeLabel')"
            />
          </FormField>
          <FormField
            :label="t('variables.functions.form.returnTypeLabel')"
            :description="t('variables.functions.form.returnTypeHint')"
            :error="returnTypeErrorText ?? undefined"
          >
            <Select
              v-model="returnType"
              :options="typeOptions"
              :disabled="submitting"
              :aria-label="t('variables.functions.form.returnTypeLabel')"
            />
          </FormField>
        </div>
      </section>

      <!-- 3. Args builder ------------------------------------------------ -->
      <section class="flex flex-col gap-next-3">
        <div class="flex flex-col gap-next-0_5">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('variables.functions.form.argsLabel') }}</span>
          <span class="text-next-xs text-next-muted-foreground">{{ t('variables.functions.form.argsHint') }}</span>
        </div>

        <div
          v-for="(arg, index) in args"
          :key="arg.id"
          class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-3"
        >
          <div class="flex items-start gap-next-2">
            <div class="min-w-0 flex-1">
              <TextInput
                v-model="arg.name"
                size="sm"
                :disabled="submitting"
                :aria-invalid="!!argErrorText(index)"
                leading-icon="tag"
                :aria-label="t('variables.functions.form.argName')"
                :placeholder="t('variables.functions.form.argName')"
              />
            </div>
            <div class="w-36 shrink-0">
              <Select
                v-model="arg.type"
                :options="typeOptions"
                size="sm"
                :disabled="submitting"
                :aria-label="t('variables.functions.form.argType')"
              />
            </div>
            <Button
              variant="ghost"
              size="icon-sm"
              type="button"
              :disabled="submitting"
              :aria-label="t('variables.functions.form.removeArg')"
              @click="removeArg(arg.id)"
            >
              <Icon name="trash" />
            </Button>
          </div>
          <TextInput
            v-model="arg.description"
            size="sm"
            :disabled="submitting"
            :aria-invalid="!!argDescriptionError(index)"
            :aria-label="t('variables.functions.form.argDescription')"
            :placeholder="t('variables.functions.form.argDescription')"
          />
          <p v-if="argErrorText(index)" class="text-next-xs text-next-danger">{{ argErrorText(index) }}</p>
          <p v-else-if="argDescriptionError(index)" class="text-next-xs text-next-danger">{{ argDescriptionError(index) }}</p>
        </div>

        <p v-if="args.length === 0" class="text-next-xs text-next-muted-foreground">
          {{ t('variables.functions.form.noArgs') }}
        </p>
        <div>
          <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addArg">
            {{ t('variables.functions.form.addArg') }}
          </Button>
        </div>
      </section>

      <!-- 4. Body pipeline ----------------------------------------------- -->
      <section class="flex flex-col gap-next-2">
        <div class="flex flex-col gap-next-0_5">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('variables.functions.form.bodyLabel') }}</span>
          <span class="text-next-xs text-next-muted-foreground">{{ t('variables.functions.form.bodyHint') }}</span>
        </div>

        <!-- The SHARED reference editor rooted at the `input` scope var. Its op-argument pool is the
             function's {input, args} scope (NEVER globals); a nested array transform merges its own
             element/index on top. The terminal gate requires the body to END on the return type. -->
        <VariableReferenceEditor
          v-model="bodyDraft"
          :nodes="scopeTree"
          :change-source="false"
          :operations-catalog="bodyCatalog"
          :result-types="resultTypes"
          :max-steps="CONDITION_LIMITS.maxPipelineSteps"
          :arg-variables="scopeVars"
          :hide-presence-ops="false"
          :hide-default="true"
          :disabled="submitting"
        >
          <!-- ARG-VARIABLE: an op argument in the body may itself be a variable — recursively, bounded
               by the depth cap. The pool is this function's scope ({input, args}), enriched by an
               array transform's element/index. The SAME value-or-variable field the workflow surfaces
               use, its literal control (PipelineArgLiteralInput) filling the VALUE slot. -->
          <template
            #argVariable="{
              arg,
              value,
              depth: hostedDepth,
              setValue,
              disabled: argDisabled,
              sourceOptions: argSourceOptions,
              targetOptions: argTargetOptions,
              variables: argPool,
              operationsCatalog: argCatalog,
              resultTypes: argTypes,
            }"
          >
            <ValueOrVariableField
              :model-value="argToUnion(value as VariableArgValue)"
              :variables="(argPool as CatalogVariable[])"
              :arg-variables="scopePool"
              :operations-catalog="(argCatalog as VariableOperationDefinition[])"
              :result-types="(argTypes as WorkflowVariableType[])"
              :target-options="isChoiceEntryTypes(argTypes) ? (argTargetOptions as VariableOption[]) : []"
              :depth="(hostedDepth as number)"
              :disabled="(argDisabled as boolean)"
              :picker-label="arg.label"
              @update:model-value="(u: WorkflowFieldValue | null) => setValue(unionToArg(u, arg))"
            >
              <template #default="{ value: litValue, setValue: setLit, disabled: litDisabled }">
                <PipelineArgLiteralInput
                  :arg="arg"
                  :value="litValue"
                  :source-options="argSourceOptions"
                  :target-options="argTargetOptions"
                  :disabled="litDisabled"
                  @update:value="setLit"
                />
              </template>
            </ValueOrVariableField>
          </template>
        </VariableReferenceEditor>

        <Alert v-if="bodyErrorText" variant="danger" size="sm">{{ bodyErrorText }}</Alert>
      </section>
    </form>

    <template #footer>
      <Button variant="outline" type="button" :disabled="submitting" @click="cancel">
        {{ t('common.cancel') }}
      </Button>
      <Button variant="primary" type="button" :loading="submitting" :disabled="!canSave" @click="submit">
        {{ isEdit ? t('common.save') : t('variables.functions.form.create') }}
      </Button>
    </template>
  </Drawer>
</template>
