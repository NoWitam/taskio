<template>
  <svg
    :class="['icon', `icon-${name}`, sizeClass]"
    :width="sizePixels"
    :height="sizePixels"
    :viewBox="viewBox"
    :fill="fillValue"
    :stroke="strokeValue"
    :stroke-width="strokeWidth"
    stroke-linecap="round"
    stroke-linejoin="round"
    v-bind="$attrs"
  >
    <component :is="iconComponent" :key="name" />
  </svg>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue';

const props = defineProps({
  name: {
    type: String,
    required: true,
  },
  size: {
    type: [String, Number],
    default: 'md',
  },
  // Jak renderować ikonę: 'stroke' | 'fill' | 'both'
  variant: {
    type: String,
    default: 'stroke',
    validator: (v) => ['stroke', 'fill', 'both'].includes(v),
  },
  // Szerokość obrysu dla wariantu stroke/both
  strokeWidth: {
    type: [String, Number],
    default: 2,
  },
});

// Mapa rozmiarów
const sizeMap = {
  xs: { pixels: 16, class: 'w-4 h-4' },
  sm: { pixels: 20, class: 'w-5 h-5' },
  md: { pixels: 24, class: 'w-6 h-6' },
  lg: { pixels: 32, class: 'w-8 h-8' },
  xl: { pixels: 40, class: 'w-10 h-10' },
};

const sizePixels = computed(() => {
  if (typeof props.size === 'number') return props.size;
  return sizeMap[props.size]?.pixels || 24;
});

const sizeClass = computed(() => {
  if (typeof props.size === 'number') return '';
  return sizeMap[props.size]?.class || 'w-6 h-6';
});

const viewBox = '0 0 24 24';

// Sterowanie kolorem przez Tailwind: używamy currentColor
const fillValue = computed(() => (props.variant === 'fill' || props.variant === 'both' ? 'currentColor' : 'none'));
const strokeValue = computed(() => (props.variant === 'stroke' || props.variant === 'both' ? 'currentColor' : 'none'));

// Lazy-load wszystkie ikony (Vite) i wybieraj po nazwie.
// To zapewnia, że zmiana `name` tworzy NOWY async komponent i przeładowuje SVG.
const iconLoaders = import.meta.glob('../../assets/icons/*.vue');

const iconComponent = computed(() => {
  const requested = props.name;
  const key = `../../assets/icons/${requested}.vue`;

  const loader = iconLoaders[key] ?? iconLoaders['../../assets/icons/question-mark.vue'];

  if (!iconLoaders[key]) {
    console.warn(`Icon not found: ${requested}`);
  }

  return defineAsyncComponent(async () => {
    // loader zawsze istnieje (fallback)
    const mod = await loader();
    return mod;
  });
});
</script>

<style scoped>
.icon {
  display: inline-block;
  vertical-align: middle;
  color: currentColor;
}
</style>
