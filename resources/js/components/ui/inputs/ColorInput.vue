<script setup lang="ts">
import { ref, computed, watch, nextTick, useSlots } from 'vue';
import { cn } from '@/lib/helpers';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import Button from '@/components/ui/Button.vue';
import NumberInput from '@/components/ui/inputs/NumberInput.vue';

const props = withDefaults(
  defineProps<{
    modelValue?: string | null;
    id?: string;
    label?: string;
    placeholder?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    name?: string;
    autocomplete?: string;
    class?: string;
    showAlpha?: boolean;
    swatches?: string[];
  }>(),
  { placeholder: '', disabled: false, showAlpha: false }
);

const emit = defineEmits<{ (e: 'update:modelValue', v: string | null): void }>();

const inputId = props.id ?? `color_${Math.random().toString(16).slice(2)}`;
const slots = useSlots();
const hasLeft = computed(() => !!slots.left);
const hasRight = computed(() => !!slots.right);
const hasCenter = computed(() => !!slots.center);

const menuOpen = ref(false);
const panelId = `${inputId}_panel`;

// resolved swatches (injected via prop, default none)
const swatchesList = computed(() => props.swatches ?? []);

// color helpers
function clamp(n:number, a=0, b=255){ return Math.min(b, Math.max(a, Math.round(n))); }
function rgbToHex(r:number,g:number,b:number){ return `#${[r,g,b].map(x => x.toString(16).padStart(2,'0')).join('').toUpperCase()}`; }
function hexToRgb(hex: string) {
  const s = String(hex).replace('#','');
  if (s.length === 3) {
    return { r: parseInt(s[0]+s[0],16), g: parseInt(s[1]+s[1],16), b: parseInt(s[2]+s[2],16), a:1 };
  }
  if (s.length === 6) {
    return { r: parseInt(s.slice(0,2),16), g: parseInt(s.slice(2,4),16), b: parseInt(s.slice(4,6),16), a:1 };
  }
  if (s.length === 8) {
    return { r: parseInt(s.slice(0,2),16), g: parseInt(s.slice(2,4),16), b: parseInt(s.slice(4,6),16), a: parseInt(s.slice(6,8),16)/255 };
  }
  return null;
}
function rgbaString(r:number,g:number,b:number,a:number){
  if (a == null || a >= 1) return rgbToHex(r,g,b);
  return `rgba(${r}, ${g}, ${b}, ${Number(a.toFixed(2))})`;
}

// conversions RGB <-> HSV (0..360, 0..1, 0..1)
function rgbToHsv(r:number,g:number,b:number){
  const rn=r/255, gn=g/255, bn=b/255;
  const max = Math.max(rn,gn,bn), min = Math.min(rn,gn,bn);
  const d = max-min;
  let h = 0;
  if (d === 0) h = 0;
  else if (max === rn) h = ((gn-bn)/d) % 6;
  else if (max === gn) h = (bn-rn)/d + 2;
  else h = (rn-gn)/d + 4;
  h = Math.round((h*60 + 360) % 360);
  const s = max === 0 ? 0 : d / max;
  const v = max;
  return { h, s, v };
}

function hsvToRgb(h:number, s:number, v:number){
  const C = v * s;
  const X = C * (1 - Math.abs(((h/60) % 2) - 1));
  const m = v - C;
  let r=0,g=0,b=0;
  if (0 <= h && h < 60){ r=C; g=X; b=0; }
  else if (60 <= h && h < 120){ r=X; g=C; b=0; }
  else if (120 <= h && h < 180){ r=0; g=C; b=X; }
  else if (180 <= h && h < 240){ r=0; g=X; b=C; }
  else if (240 <= h && h < 300){ r=X; g=0; b=C; }
  else { r=C; g=0; b=X; }
  return { r: clamp((r+m)*255), g: clamp((g+m)*255), b: clamp((b+m)*255) };
}

// internal state
const hue = ref(0); // 0..360
const sat = ref(1); // 0..1
const val = ref(1); // 0..1
const alpha = ref(1);

const r = ref(255); const g = ref(0); const b = ref(0);

// when true, skip converting HSV -> RGB to avoid overwriting user-edited RGB values
const suppressHsv = ref(false);
// track dragging state for hue handle to update cursor
const isHueDragging = ref(false);

const rString = computed({ get: () => String(r.value), set: (v: string) => { const n = Number(v); r.value = clamp(isNaN(n) ? 0 : n); suppressHsv.value = true; const h = rgbToHsv(r.value,g.value,b.value); hue.value = h.h; sat.value = h.s; val.value = h.v; emit('update:modelValue', colorString.value); nextTick(() => { suppressHsv.value = false; }); } });
const gString = computed({ get: () => String(g.value), set: (v: string) => { const n = Number(v); g.value = clamp(isNaN(n) ? 0 : n); suppressHsv.value = true; const h = rgbToHsv(r.value,g.value,b.value); hue.value = h.h; sat.value = h.s; val.value = h.v; emit('update:modelValue', colorString.value); nextTick(() => { suppressHsv.value = false; }); } });
const bString = computed({ get: () => String(b.value), set: (v: string) => { const n = Number(v); b.value = clamp(isNaN(n) ? 0 : n); suppressHsv.value = true; const h = rgbToHsv(r.value,g.value,b.value); hue.value = h.h; sat.value = h.s; val.value = h.v; emit('update:modelValue', colorString.value); nextTick(() => { suppressHsv.value = false; }); } });
function setFromRgb(rr:number,gg:number,bb:number,aa?:number){ r.value=clamp(rr); g.value=clamp(gg); b.value=clamp(bb); alpha.value = props.showAlpha ? (aa == null ? 1 : Math.max(0, Math.min(1, aa))) : 1; const h = rgbToHsv(r.value,g.value,b.value); hue.value = h.h; sat.value = h.s; val.value = h.v; }
function setFromHexOrRgba(s: string | null){ if (!s) return; const t = String(s).trim(); if (t.startsWith('#')){ const rgb = hexToRgb(t); if (rgb) { const aa = props.showAlpha ? rgb.a : undefined; setFromRgb(rgb.r, rgb.g, rgb.b, aa); } } else if (t.startsWith('rgb')){ const m = t.match(/rgba?\((\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s]+([0-9\.]+))?\)/); if (m){ const aa = props.showAlpha && m[4] ? Number(m[4]) : undefined; setFromRgb(Number(m[1]), Number(m[2]), Number(m[3]), aa); } } }

// compute output string to emit
const colorString = computed(() => {
  if (alpha.value < 1) return rgbaString(r.value,g.value,b.value,alpha.value);
  return rgbToHex(r.value,g.value,b.value);
});

// when HSV changes, update RGB
watch([hue, sat, val], () => {
  if (suppressHsv.value) return;
  const rgb = hsvToRgb(hue.value, sat.value, val.value);
  r.value = rgb.r; g.value = rgb.g; b.value = rgb.b;
  emit('update:modelValue', colorString.value);
});

watch(alpha, () => { emit('update:modelValue', colorString.value); });

// keep in sync with external model
watch(() => props.modelValue, (v) => {
  if (!v) return;
  setFromHexOrRgba(v);
}, { immediate: true });

// SV box interactions
const svRef = ref<HTMLElement | null>(null);
function svSetFromPointer(clientX: number, clientY:number){ const el = svRef.value; if (!el) return; const rect = el.getBoundingClientRect(); const x = Math.max(0, Math.min(rect.width, clientX - rect.left)); const y = Math.max(0, Math.min(rect.height, clientY - rect.top)); sat.value = x / rect.width; val.value = 1 - (y / rect.height); }
function startSv(e: PointerEvent){
  if (props.disabled) return;
  svRef.value?.setPointerCapture?.(e.pointerId);
  svSetFromPointer(e.clientX, e.clientY);
  window.addEventListener('pointermove', onSvMove);
  window.addEventListener('pointerup', endSv);
}
function onSvMove(e: PointerEvent){ svSetFromPointer(e.clientX, e.clientY); }
function endSv(){ window.removeEventListener('pointermove', onSvMove); window.removeEventListener('pointerup', endSv); }

// Hue slider
const hueRef = ref<HTMLElement | null>(null);
function hueSetFromPointer(clientX:number){ const el = hueRef.value; if (!el) return; const rect = el.getBoundingClientRect(); const x = Math.max(0, Math.min(rect.width, clientX - rect.left)); const pct = x / rect.width; hue.value = Math.round(pct * 360); }
function startHue(e: PointerEvent){ if (props.disabled) return; isHueDragging.value = true; hueSetFromPointer(e.clientX); window.addEventListener('pointermove', onHueMove); window.addEventListener('pointerup', endHue); }
function onHueMove(e: PointerEvent){ hueSetFromPointer(e.clientX); }
function endHue(){ isHueDragging.value = false; window.removeEventListener('pointermove', onHueMove); window.removeEventListener('pointerup', endHue); }

// Handle manual RGB/A inputs
function onRgbInput(which: 'r'|'g'|'b'|'a', v: string){ const n = Number(v.replace(/[^0-9\.]/g,'')); if (which === 'a'){ alpha.value = Math.max(0, Math.min(1, isNaN(n) ? 1 : n)); } else { const nv = isNaN(n) ? 0 : clamp(n); if (which === 'r') r.value = nv; if (which === 'g') g.value = nv; if (which === 'b') b.value = nv; suppressHsv.value = true; const h = rgbToHsv(r.value,g.value,b.value); hue.value = h.h; sat.value = h.s; val.value = h.v; nextTick(()=> { suppressHsv.value = false; }); } }



</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu v-model:open="menuOpen" :class="props.class" align="start" :matchTriggerWidth="true">
      <template #activator="{ open, toggle }">
        <div class="relative">
          <input
            :id="inputId"
            :value="colorString"
            readonly
            type="text"
            inputmode="none"
            maxlength="40"
            :name="name"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-label="`Wybrany kolor ${colorString}`"
            :aria-haspopup="'dialog'"
            :aria-expanded="menuOpen ? 'true' : 'false'"
            :aria-controls="panelId"
            :class="cn(
              'h-10 w-full rounded-lg border px-3 text-sm placeholder:text-muted-foreground/70',
              hasLeft && 'pl-10',
              hasRight && 'pr-10',
              'border-border hover:border-border/80',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
              'text-transparent',
              props.class
            )"
            @click.stop="toggle()"
            @keydown.enter.stop.prevent="toggle()"
            @keydown.space.stop.prevent="toggle()"
          />

          <div v-if="hasLeft" class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-auto">
            <slot name="left" />
          </div>

          <div v-if="hasRight" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-auto">
            <slot name="right" />
          </div>

          <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <slot name="center" :color="colorString">
              <div class="mx-2 w-full">
                <div :style="{ background: colorString }" class="h-6 rounded-md border border-border w-full" />
              </div>
            </slot>
          </div> 
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="flex justify-center">
            <div class="p-3 w-[320px]">
            <div class="grid gap-3 mx-auto w-full max-w-[280px]">
                <!-- SV box -->
                <div class="w-full h-40 rounded-md overflow-hidden relative" ref="svRef" @pointerdown.prevent="startSv($event as PointerEvent)" :style="{ background: `linear-gradient(90deg, hsl(${hue},100%,50%) 0%, hsl(${hue},100%,50%) 100%)` }">
                <div class="absolute inset-0" :style="{ background: `linear-gradient(0deg, rgba(0,0,0,1), rgba(0,0,0,0))` }"></div>
                <div class="absolute inset-0" :style="{ background: `linear-gradient(90deg, rgba(255,255,255,1), rgba(255,255,255,0))` }"></div>
                <div class="absolute rounded-full w-3 h-3 -translate-x-1/2 -translate-y-1/2" :style="{ left: `${sat*100}%`, top: `${(1-val)*100}%`, border: '2px solid white', boxShadow: '0 0 0 1px rgba(0,0,0,0.2)' }" />
                </div>

                <!-- Hue slider -->
                <div class="h-3 w-full rounded-md relative overflow-visible cursor-ew-resize" ref="hueRef" @pointerdown.prevent="startHue($event as PointerEvent)">
                <div class="absolute inset-0 rounded-md" :style="{ background: `linear-gradient(90deg, red 0%, yellow 17%, lime 33%, cyan 50%, blue 67%, magenta 83%, red 100%)` }"></div>
              <div :class="['absolute -translate-x-1/2 top-[-10px] z-20 pointer-events-auto', isHueDragging ? 'cursor-grabbing' : 'cursor-grab']" :style="{ left: `${(hue/360)*100}%` }" tabindex="0" role="slider" :aria-valuemin="0" :aria-valuemax="360" :aria-valuenow="hue" @pointerdown.stop.prevent="startHue($event as PointerEvent)" @pointerup.stop.prevent="endHue()">
                <div class="w-5 h-7 rounded-lg bg-primary border-2 border-white shadow-lg"></div>
                </div>
                </div>

                <!-- swatches -->
                <div 
                    v-if="swatches"
                    class="flex gap-2 flex-wrap my-4"
                >
                    <button v-for="s in swatchesList" :key="s" type="button" class="w-14 h-8 rounded-md cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" :style="{ background: s }" @click="() => { setFromHexOrRgba(s); emit('update:modelValue', colorString); }" aria-label="Wybierz kolor" />
                </div>

                <!-- numeric inputs (NumberInput) -->
                <div class="grid grid-cols-3 gap-2">
                <label class="flex flex-col items-center text-sm font-semibold text-primary">
                    R
                    <NumberInput v-model="rString" class="mt-1 w-full" :min="0" :max="255" :step="1" />
                </label>
                <label class="flex flex-col items-center text-sm font-semibold text-primary">
                    G
                    <NumberInput v-model="gString" class="mt-1 w-full" :min="0" :max="255" :step="1" />
                </label>
                <label class="flex flex-col items-center text-sm font-semibold text-primary">
                    B
                    <NumberInput v-model="bString" class="mt-1 w-full" :min="0" :max="255" :step="1" />
                </label>
                </div>

                <div class="flex justify-end gap-2 mt-2">
                <Button variant="primary" size="sm" @click="() => { closeMenu(); menuOpen = false; }">Wybierz</Button>
                </div> 
            </div>
            </div>
        </div>
      </template>
    </DropdownMenu>

    <p v-if="hint" :id="`${inputId}_hint`" class="text-xs text-muted-foreground text-left pl-0">
      {{ hint }}
    </p>
    <p v-if="error" :id="`${inputId}_err`" class="text-xs text-danger text-left pl-0">
      {{ error }}
    </p>
  </div>
</template>
