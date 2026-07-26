<script setup lang="ts">
// ImageAiPanel — the image editor's AI actions (P11 / F2-3), applied to the CURRENT canvas through
// the editor's applyAi (POST /disk/ai/image → poll → the edited image becomes the new base; saving
// stays manual via Zapisz / Zapisz jako). Two op families:
//   • FULL IMAGE — remove background / enhance / sharpen / line-art / watercolor / product-on-white:
//     one click, no mask, whole-image edit.
//   • EDIT AN AREA (masked) — remove-object (fixed prompt) / replace (uses the free prompt): selecting
//     one ARMS the mask brush; the Apply is disabled until the user paints a mask region. On apply the
//     brushed mask (transparent = repaint) is exported and sent alongside the prompt.
// Single-flight: one AI call at a time (long, provider-billed, QUEUED + polled — tens of seconds), with
// a live indicator + Cancel while it runs.
import { computed, ref, watch } from 'vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Spinner from '../../../ui/primitives/Spinner.vue';
import Slider from '../../../ui/forms/Slider.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import { useToast } from '../../../app/composables/useToast';
import { useI18n } from '../../../app/i18n';
import { FULL_IMAGE_PRESETS, MASKED_PRESETS, type AiPreset } from './aiPresets';
import type { useImageEditor } from './useImageEditor';

const props = defineProps<{
  /** The stage's editor (useImageEditor return). */
  editor: ReturnType<typeof useImageEditor>;
  disabled?: boolean;
}>();

const { t } = useI18n();
const toast = useToast();

const BRUSH_MIN = 8;
const BRUSH_MAX = 160;

const prompt = ref('');
/** Key of the op currently in flight — drives the per-button spinner; null when idle. */
const running = ref<string | null>(null);
/** The armed MASKED preset key (mask mode on), or null. */
const armedKey = ref<string | null>(null);

const armedPreset = computed<AiPreset | null>(() => MASKED_PRESETS.find((p) => p.key === armedKey.value) ?? null);
const aiBusy = computed(() => props.editor.aiBusy.value);
const busy = computed(() => running.value !== null || aiBusy.value);
const hasStrokes = computed(() => props.editor.hasMaskStrokes.value);
/** Replace needs a description; remove-object does not. */
const maskApplyDisabled = computed(
  () => !!props.disabled || busy.value || !hasStrokes.value || (!!armedPreset.value?.usesPrompt && !prompt.value.trim()),
);

/** Arm/disarm a masked preset — arming turns mask mode on, disarming turns it off + clears strokes. */
function arm(key: string | null): void {
  armedKey.value = key;
  props.editor.setMaskMode(!!key);
}

// Mask mode can be turned off from OUTSIDE the panel (leaving the AI tab, entering crop) — keep the
// armed state in sync so the masked controls hide and the chip un-presses.
watch(
  () => props.editor.maskMode.value,
  (on) => {
    if (!on && armedKey.value) armedKey.value = null;
  },
);

function fail(err: unknown): void {
  if ((err as Error)?.message === 'cancelled') return; // the user cancelled — not an error
  const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
  toast.danger(message ?? t('disk.preview.ai.error', 'The AI image edit failed. Please try again.'));
}

/** Run a FULL-IMAGE preset immediately (no mask). */
async function runFull(preset: AiPreset): Promise<void> {
  if (busy.value || props.disabled) return;
  arm(null); // ensure no stale mask is sent
  running.value = preset.key;
  try {
    await props.editor.applyAi(preset.prompt);
  } catch (err) {
    fail(err);
  } finally {
    running.value = null;
  }
}

/** Apply the armed MASKED preset with the brushed mask + the op's instruction. */
async function runMasked(): Promise<void> {
  const preset = armedPreset.value;
  if (!preset || maskApplyDisabled.value) return;
  const instruction = preset.usesPrompt ? prompt.value.trim() : preset.prompt;
  if (!instruction) return;
  running.value = preset.key;
  try {
    const mask = await props.editor.exportMask();
    await props.editor.applyAi(instruction, mask);
    if (preset.usesPrompt) prompt.value = '';
    arm(null); // mask off after a successful apply
  } catch (err) {
    fail(err);
  } finally {
    running.value = null;
  }
}

/** Run the free-form prompt on the whole image (or the painted region if a mask happens to exist). */
async function runPrompt(): Promise<void> {
  const instruction = prompt.value.trim();
  if (busy.value || props.disabled || !instruction) return;
  running.value = 'prompt';
  try {
    const mask = hasStrokes.value ? await props.editor.exportMask() : null;
    await props.editor.applyAi(instruction, mask);
    prompt.value = '';
    arm(null);
  } catch (err) {
    fail(err);
  } finally {
    running.value = null;
  }
}

function onBrush(v: number | [number, number]): void {
  props.editor.brushSize.value = typeof v === 'number' ? v : v[0];
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- In-flight indicator + cancel (the edit is queued + polled, so it can take tens of seconds). -->
    <div
      v-if="aiBusy"
      class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted/40 px-next-3 py-next-2"
      role="status"
      aria-live="polite"
    >
      <Spinner size="sm" decorative />
      <span class="flex-1 text-next-sm text-next-muted-foreground">
        {{ t('disk.preview.ai.processing', 'Editing your image… this can take a moment.') }}
      </span>
      <Button variant="ghost" size="sm" leading-icon="x" @click="editor.cancelAi">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
    </div>

    <!-- Full-image ops. -->
    <div class="flex flex-col gap-next-1_5">
      <span class="text-next-xs font-next-medium uppercase tracking-wide text-next-muted-foreground">
        {{ t('disk.preview.ai.fullImage', 'Whole image') }}
      </span>
      <div class="flex flex-wrap items-center gap-next-2">
        <Button
          v-for="preset in FULL_IMAGE_PRESETS"
          :key="preset.key"
          size="sm"
          variant="ghost"
          :leading-icon="preset.icon ?? 'sparkles'"
          :disabled="disabled || busy"
          :loading="running === preset.key"
          @click="runFull(preset)"
        >
          {{ t(preset.labelKey) }}
        </Button>
      </div>
    </div>

    <!-- Masked ops (edit a specific area). -->
    <div class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 px-next-3 py-next-2">
      <div class="flex flex-wrap items-center gap-next-2">
        <span class="text-next-xs font-next-medium uppercase tracking-wide text-next-muted-foreground">
          {{ t('disk.preview.ai.masked', 'Edit an area') }}
        </span>
        <Button
          v-for="preset in MASKED_PRESETS"
          :key="preset.key"
          size="sm"
          :variant="armedKey === preset.key ? 'secondary' : 'ghost'"
          :leading-icon="preset.icon ?? 'sparkles'"
          :aria-pressed="armedKey === preset.key ? 'true' : 'false'"
          :disabled="disabled || busy"
          @click="arm(armedKey === preset.key ? null : preset.key)"
        >
          {{ t(preset.labelKey) }}
        </Button>
      </div>

      <template v-if="armedPreset">
        <div class="flex items-center gap-next-2">
          <p class="flex flex-1 items-center gap-next-1_5 text-next-xs text-next-muted-foreground">
            <Icon name="pencil" aria-hidden="true" />
            {{ t('disk.preview.ai.maskHint', 'Paint over the area to edit.') }}
          </p>
          <!-- Explicit way OUT of the area-edit mode back to whole-image editing — re-clicking the
               armed chip also works, but is not discoverable, so this is the obvious exit. -->
          <Button variant="ghost" size="sm" leading-icon="arrow-left" :disabled="busy" @click="arm(null)">
            {{ t('disk.preview.ai.exitArea', 'Edit whole image') }}
          </Button>
        </div>

        <div class="flex items-center gap-next-3">
          <span class="w-20 shrink-0 text-next-sm text-next-muted-foreground">{{ t('disk.preview.ai.brush', 'Brush size') }}</span>
          <Slider
            :model-value="editor.brushSize.value"
            :min="BRUSH_MIN"
            :max="BRUSH_MAX"
            :step="2"
            show-value
            :disabled="disabled || busy"
            :aria-label="t('disk.preview.ai.brush', 'Brush size')"
            class="min-w-0 flex-1"
            @update:model-value="onBrush"
          />
          <Tooltip :label="t('disk.preview.ai.clearMask', 'Clear mask')">
            <Button
              variant="ghost"
              size="icon-sm"
              leading-icon="x"
              :disabled="disabled || busy || !hasStrokes"
              :aria-label="t('disk.preview.ai.clearMask', 'Clear mask')"
              @click="editor.clearMask"
            />
          </Tooltip>
        </div>

        <!-- Replace: describe what to place into the masked area. -->
        <TextInput
          v-if="armedPreset.usesPrompt"
          v-model="prompt"
          size="sm"
          :placeholder="t('disk.preview.ai.replacePlaceholder', 'Describe what to place in the selected area…')"
          :aria-label="t('disk.preview.ai.replaceLabel', 'Replacement description')"
          :disabled="disabled || busy"
          @keydown.enter="runMasked"
        />

        <div class="flex items-center justify-end gap-next-3">
          <span v-if="!hasStrokes" class="flex-1 text-next-xs text-next-muted-foreground">
            {{ t('disk.preview.ai.maskRequired', 'Paint a mask to enable this action.') }}
          </span>
          <Button
            size="sm"
            variant="primary"
            leading-icon="sparkles"
            :disabled="maskApplyDisabled"
            :loading="running === armedPreset.key"
            @click="runMasked"
          >
            {{ t(armedPreset.labelKey) }}
          </Button>
        </div>
      </template>
    </div>

    <!-- Free prompt (whole image) — hidden while a masked op is armed (its prompt lives above). -->
    <div v-if="!armedPreset" class="flex min-w-56 items-center gap-next-2">
      <TextInput
        v-model="prompt"
        size="sm"
        class="min-w-0 flex-1"
        :placeholder="t('disk.preview.ai.promptPlaceholder', 'Tell the AI what to change…')"
        :aria-label="t('disk.preview.ai.promptLabel', 'AI instruction')"
        :disabled="disabled || busy"
        @keydown.enter="runPrompt"
      />
      <Button
        size="sm"
        variant="secondary"
        leading-icon="sparkles"
        :disabled="disabled || busy || !prompt.trim()"
        :loading="running === 'prompt'"
        @click="runPrompt"
      >
        {{ t('disk.preview.ai.apply', 'Apply AI') }}
      </Button>
    </div>
  </div>
</template>
