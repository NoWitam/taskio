<script setup lang="ts">
// EditorAdjustPanel — the "Korekcja" mode: brightness / contrast / saturation sliders over the
// canvas. Dragging previews LIVE through the engine's `setBrightness/…` (which re-render but do NOT
// touch history); a debounced `commitAdjust` records ONE undo step once the value settles, so a
// slider drag is a single undoable action, not a hundred. "Resetuj korekcje" zeroes all three.
import { computed, onBeforeUnmount } from 'vue';
import Slider from '../../../../ui/forms/Slider.vue';
import Button from '../../../../ui/primitives/Button.vue';
import { useI18n } from '../../../../app/i18n';
import type { useImageEditor } from '../useImageEditor';

const props = defineProps<{
  editor: ReturnType<typeof useImageEditor>;
  disabled: boolean;
}>();

const { t } = useI18n();

let commitTimer: ReturnType<typeof setTimeout> | null = null;
function scheduleCommit(): void {
  if (commitTimer) clearTimeout(commitTimer);
  commitTimer = setTimeout(() => {
    props.editor.commitAdjust();
    commitTimer = null;
  }, 350);
}
onBeforeUnmount(() => {
  // Just cancel a pending debounce; an uncommitted live edit already reads `dirty` (the shell's
  // unsaved-changes guard catches it), so there is nothing to flush here.
  if (commitTimer) clearTimeout(commitTimer);
});

/** Live preview (setX re-renders) + a debounced single history step once the value settles. The
 *  Slider model is number|[number,number]; single mode always emits a number, but narrow anyway. */
function onAdjust(set: (v: number) => void, v: number | [number, number]): void {
  set(typeof v === 'number' ? v : v[0]);
  scheduleCommit();
}

const hasAdjust = computed(
  () => props.editor.brightness.value !== 0 || props.editor.contrast.value !== 0 || props.editor.saturation.value !== 0,
);

function resetAdjust(): void {
  props.editor.setBrightness(0);
  props.editor.setContrast(0);
  props.editor.setSaturation(0);
  props.editor.commitAdjust();
}

const rows = computed(() => [
  { key: 'brightness' as const, label: t('disk.editor.adjust.brightness', 'Brightness'), get: () => props.editor.brightness.value, set: props.editor.setBrightness },
  { key: 'contrast' as const, label: t('disk.editor.adjust.contrast', 'Contrast'), get: () => props.editor.contrast.value, set: props.editor.setContrast },
  { key: 'saturation' as const, label: t('disk.editor.adjust.saturation', 'Saturation'), get: () => props.editor.saturation.value, set: props.editor.setSaturation },
]);
</script>

<template>
  <div class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/30 px-next-3 py-next-2">
    <div v-for="row in rows" :key="row.key" class="flex items-center gap-next-3">
      <span class="w-20 shrink-0 text-next-sm text-next-muted-foreground">{{ row.label }}</span>
      <Slider
        :model-value="row.get()"
        :min="-100"
        :max="100"
        :step="1"
        show-value
        :disabled="disabled"
        :aria-label="row.label"
        class="min-w-0 flex-1"
        @update:model-value="(v) => onAdjust(row.set, v)"
      />
    </div>
    <div class="flex justify-end">
      <Button variant="ghost" size="sm" leading-icon="remove-formatting" :disabled="disabled || !hasAdjust" @click="resetAdjust">
        {{ t('disk.editor.adjust.reset', 'Reset adjustments') }}
      </Button>
    </div>
  </div>
</template>
