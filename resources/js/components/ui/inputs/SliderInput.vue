<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';

const props = withDefaults(
  defineProps<{ 
    modelValue?: number | [number, number] | null;
    id?: string;
    label?: string;
    min?: number;
    max?: number;
    step?: number;
    range?: boolean;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;
  }>(),
  { min: 0, max: 100, step: 1, range: false, disabled: false }
);

const emit = defineEmits<{ (e: 'update:modelValue', value: number | [number, number]): void }>();

const trackRef = ref<HTMLElement | null>(null);
const dragging = ref<null | 'low' | 'high' | 'single'>(null);
const hoverHandle = ref<null | 'low' | 'high' | 'single'>(null);

const internal = ref<number | [number, number]>(
  props.range
    ? (Array.isArray(props.modelValue) ? props.modelValue.slice() : [props.min!, props.max!])
    : (typeof props.modelValue === 'number' ? props.modelValue : props.min!)
);

watch(
  () => props.modelValue,
  (v) => {
    if (props.range) {
      if (Array.isArray(v)) internal.value = [v[0], v[1]];
    } else {
      if (typeof v === 'number') internal.value = v;
    }
  }
);

function valueToPct(val: number) {
  const range = props.max! - props.min!;
  return ((val - props.min!) / range) * 100;
}

function pctToValue(pct: number) {
  const raw = props.min! + (pct / 100) * (props.max! - props.min!);
  // quantize to step
  const stepped = Math.round(raw / props.step!) * props.step!;
  return Math.min(props.max!, Math.max(props.min!, stepped));
}

function onPointerDown(e: PointerEvent, which: 'low' | 'high' | 'single') {
  if (props.disabled) return;
  (e.target as Element).setPointerCapture(e.pointerId);
  dragging.value = which;
}

function onPointerMove(e: PointerEvent) {
  if (!dragging.value || !trackRef.value) return;
  const rect = trackRef.value.getBoundingClientRect();
  const pct = ((e.clientX - rect.left) / rect.width) * 100;
  const val = pctToValue(pct);

  if (props.range) {
    const [low, high] = (internal.value as [number, number]);
    if (dragging.value === 'low') {
      const newLow = Math.min(val, high);
      internal.value = [newLow, high];
      emit('update:modelValue', internal.value as [number, number]);
    } else if (dragging.value === 'high') {
      const newHigh = Math.max(val, low);
      internal.value = [low, newHigh];
      emit('update:modelValue', internal.value as [number, number]);
    }
  } else {
    internal.value = val;
    emit('update:modelValue', internal.value as number);
  }
}

function onPointerUp(e: PointerEvent) {
  if (!dragging.value) return;
  try { (e.target as Element).releasePointerCapture(e.pointerId); } catch (err) {}
  dragging.value = null;
}

onMounted(() => {
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);
});

onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onPointerMove);
  window.removeEventListener('pointerup', onPointerUp);
});

function setHover(which: null | 'low' | 'high' | 'single') {
  hoverHandle.value = which;
}

function handleTrackClick(e: MouseEvent) {
  if (props.disabled || !trackRef.value) return;
  const rect = trackRef.value.getBoundingClientRect();
  const pct = ((e.clientX - rect.left) / rect.width) * 100;
  const val = pctToValue(pct);
  if (props.range) {
    const [low, high] = (internal.value as [number, number]);
    const distLow = Math.abs(val - low);
    const distHigh = Math.abs(val - high);
    if (distLow <= distHigh) {
      internal.value = [Math.min(val, high), high];
    } else {
      internal.value = [low, Math.max(val, low)];
    }
    emit('update:modelValue', internal.value as [number, number]);
  } else {
    internal.value = val;
    emit('update:modelValue', internal.value as number);
  }
}

// computed positions
const lowPct = computed(() => props.range ? valueToPct((internal.value as [number, number])[0]) : 0);
const highPct = computed(() => props.range ? valueToPct((internal.value as [number, number])[1]) : valueToPct(internal.value as number));
const singlePct = computed(() => !props.range ? valueToPct(internal.value as number) : 0);

// Tooltip content
const showTooltipLow = computed(() => dragging.value === 'low' || hoverHandle.value === 'low');
const showTooltipHigh = computed(() => dragging.value === 'high' || hoverHandle.value === 'high');
const showTooltipSingle = computed(() => dragging.value === 'single' || hoverHandle.value === 'single');
</script>

<template>
  <div :class="['space-y-1.5 flex flex-col', props.class]">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0">{{ label }}</label>

    <div class="relative">
      <div
        ref="trackRef"
        class="h-2 rounded-full bg-secondary/40 relative w-full cursor-pointer"
        @click.prevent.stop="handleTrackClick"
      >
        <!-- filled range -->
        <div v-if="props.range" :style="{ left: lowPct + '%', right: (100 - highPct) + '%' }" class="absolute inset-y-0 bg-primary rounded-full"></div>
        <div v-else :style="{ width: singlePct + '%' }" class="absolute inset-y-0 bg-primary rounded-full"></div>

        <!-- handles -->
        <button
          v-if="props.range"
          type="button"
          :class="['absolute top-1/2 w-5 h-5 rounded-full bg-card border border-border shadow flex items-center justify-center -translate-x-1/2 -translate-y-1/2', dragging === 'low' ? 'cursor-grabbing' : 'cursor-grab']"
          :style="{ left: lowPct + '%' }"
          @pointerdown.prevent="(e) => onPointerDown(e, 'low')"
          @pointerenter="() => setHover('low')"
          @pointerleave="() => setHover(null)"
          :aria-valuemin="props.min"
          :aria-valuemax="props.max"
          :aria-valuenow="(internal as [number, number])[0]"
        >
          <div v-if="showTooltipLow" class="absolute -top-8 left-1/2 -translate-x-1/2 bg-card border border-border rounded px-2 py-1 text-xs shadow z-10">
            {{ (internal as [number, number])[0] }}
          </div>
        </button>

        <button
          v-if="props.range"
          type="button"
          :class="['absolute top-1/2 w-5 h-5 rounded-full bg-card border border-border shadow flex items-center justify-center -translate-x-1/2 -translate-y-1/2', dragging === 'high' ? 'cursor-grabbing' : 'cursor-grab']"
          :style="{ left: highPct + '%' }"
          @pointerdown.prevent="(e) => onPointerDown(e, 'high')"
          @pointerenter="() => setHover('high')"
          @pointerleave="() => setHover(null)"
          :aria-valuemin="props.min"
          :aria-valuemax="props.max"
          :aria-valuenow="(internal as [number, number])[1]"
        >
          <div v-if="showTooltipHigh" class="absolute -top-8 left-1/2 -translate-x-1/2 bg-card border border-border rounded px-2 py-1 text-xs shadow z-10">
            {{ (internal as [number, number])[1] }}
          </div>
        </button>



        <button
          v-if="!props.range"
          type="button"
          :class="['absolute top-1/2 w-5 h-5 rounded-full bg-card border border-border shadow flex items-center justify-center -translate-x-1/2 -translate-y-1/2', dragging === 'single' ? 'cursor-grabbing' : 'cursor-grab']"
          :style="{ left: singlePct + '%' }"
          @pointerdown.prevent="(e) => onPointerDown(e, 'single')"
          @pointerenter="() => setHover('single')"
          @pointerleave="() => setHover(null)"
          :aria-valuemin="props.min"
          :aria-valuemax="props.max"
          :aria-valuenow="internal as number"
        >
          <div v-if="showTooltipSingle" class="absolute -top-8 left-1/2 -translate-x-1/2 bg-card border border-border rounded px-2 py-1 text-xs shadow z-10">
            {{ internal as number }}
          </div>
        </button>
      </div>
    </div>

    <p v-if="hint" class="text-xs text-muted-foreground text-left pl-0">{{ hint }}</p>
    <p v-if="error" class="text-xs text-danger text-left pl-0">{{ error }}</p>
  </div>
</template>
