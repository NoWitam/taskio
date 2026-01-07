<script setup lang="ts">
import { ref, computed, watch, nextTick, useSlots, onBeforeUnmount } from "vue";
import { cn } from "@/lib/helpers";
import DropdownMenu from "@/components/ui/DropdownMenu.vue";
import Button from "@/components/ui/Button.vue";

const props = withDefaults(
  defineProps<{
    modelValue: string;
    id?: string;
    label?: string;
    placeholder?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    name?: string;
    autocomplete?: string;
    class?: string;
  }>(),
  { placeholder: "--:--", disabled: false }
);

const emit = defineEmits<{ (e: "update:modelValue", value: string): void }>();

const inputId = props.id ?? `time_${Math.random().toString(16).slice(2)}`;

// Local display value (what is shown in the input)
const localValue = ref(props.modelValue ?? "");
// input ref to allow focusing when dropdown opens
const inputRef = ref<HTMLInputElement | null>(null);
const slots = useSlots();
const hasLeft = computed(() => !!slots.left);
const hasRight = computed(() => !!slots.right);

// Controlled open state for the dropdown so keyboard handlers can reference it
const menuOpen = ref(false);
const panelId = `${inputId}_panel`;

// keyboard helpers
function incHour(delta: number) {
  const cur = selectedHour.value ?? 0;
  selectedHour.value = (cur + delta + 24) % 24;
  if (selectedMinute.value != null) {
    const hh = String(selectedHour.value).padStart(2, '0');
    const mm = String(selectedMinute.value).padStart(2, '0');
    localValue.value = `${hh}:${mm}`;
    emit('update:modelValue', localValue.value);
  }
}

function incMinute(delta: number) {
  const cur = selectedMinute.value ?? 0;
  let nxt = (cur + delta) % 60;
  if (nxt < 0) nxt += 60;
  selectedMinute.value = nxt;
  const hh = String(selectedHour.value ?? 0).padStart(2, '0');
  const mm = String(selectedMinute.value).padStart(2, '0');
  localValue.value = `${hh}:${mm}`;
  emit('update:modelValue', localValue.value);
}

function commitOrMoveNext(closeMenu?: () => void) {
  // If minute selected, commit and close; otherwise move from hour to minute
  if (mode.value === 'hour') {
    mode.value = 'minute';
  } else if (selectedHour.value != null && selectedMinute.value != null) {
    if (typeof closeMenu === 'function') closeMenu();
    menuOpen.value = false;
  }
}

// Drag state for minute fine-tuning
const dragging = ref(false);
const dragStartY = ref(0);
const dragStartMinute = ref<number>(0);

function startDrag(e: PointerEvent) {
  if (props.disabled) return;
  e.preventDefault();
  dragging.value = true;
  dragStartY.value = e.clientY;
  dragStartMinute.value = selectedMinute.value ?? 0;
  window.addEventListener("pointermove", onDrag);
  window.addEventListener("pointerup", endDrag);
}

function onDrag(e: PointerEvent) {
  if (!dragging.value) return;
  const dy = dragStartY.value - e.clientY; // up increases
  const delta = Math.round(dy / 8); // one minute per ~8px
  let newMin = (dragStartMinute.value + delta) % 60;
  if (newMin < 0) newMin += 60;
  if (newMin !== selectedMinute.value) {
    selectMinute(newMin);
  }
}

function endDrag() {
  dragging.value = false;
  window.removeEventListener("pointermove", onDrag);
  window.removeEventListener("pointerup", endDrag);
}

onBeforeUnmount(() => {
  if (dragging.value) endDrag();
});

watch(
  () => props.modelValue,
  (v) => {
    localValue.value = v ?? "";
  }
);

// Helper: keep only digits from input
function digitsOnly(s: string) {
  return (s || "").replace(/\D/g, "");
}

// Format digits to HH:MM masked format (partial allowed)
function formatFromDigits(d: string) {
  const dd = d.slice(0, 4);
  if (!dd) return "";
  if (dd.length <= 2) return dd;
  return `${dd.slice(0, 2)}:${dd.slice(2)}`;
}

// Prevent typing non-numeric characters and limit digits to 4 (respect selection)
function onKeyDown(e: KeyboardEvent) {
  // Allow navigation, editing and common ctrl/meta combos
  const allowed = ["Backspace", "Delete", "ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End", "Tab", "Enter"];
  // If menu open and Arrow keys pressed, use them to change selection instead of moving caret
  if (menuOpen.value && (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
    e.preventDefault();
    if (e.key === 'ArrowUp' || e.key === 'ArrowRight') {
      if (mode.value === 'hour') incHour(1);
      else incMinute(1);
    } else {
      if (mode.value === 'hour') incHour(-1);
      else incMinute(-1);
    }
    return;
  }

  if (allowed.includes(e.key)) return;
  if (e.ctrlKey || e.metaKey) return; // allow copy/paste/select all

  // allow colon for convenience
  if (e.key === ":") return;

  // allow digits but limit to 4 digits - take selection into account
  if (/^\d$/.test(e.key)) {
    const el = inputRef.value;
    const current = localValue.value ?? "";
    const selStart = el?.selectionStart ?? current.length;
    const selEnd = el?.selectionEnd ?? current.length;
    const before = current.slice(0, selStart);
    const after = current.slice(selEnd);
    const newDigits = digitsOnly(before + e.key + after);
    if (newDigits.length > 4) {
      e.preventDefault();
    }
    return;
  }

  // otherwise block
  e.preventDefault();
}

// Sanitize pasted content to digits (max 4) and insert at caret position
function onPaste(e: ClipboardEvent) {
  e.preventDefault();
  const raw = (e.clipboardData?.getData("text") ?? "");
  const pasteDigits = digitsOnly(raw);
  const el = inputRef.value;
  const current = localValue.value ?? "";
  const selStart = el?.selectionStart ?? current.length;
  const selEnd = el?.selectionEnd ?? current.length;
  const before = current.slice(0, selStart);
  const after = current.slice(selEnd);
  const composed = digitsOnly(before + pasteDigits + after).slice(0, 4);
  localValue.value = formatFromDigits(composed);
  if (composed.length >= 4) {
    const hh = composed.slice(0, 2);
    const mm = composed.slice(2, 4);
    const hhN = String(Math.min(23, Number(hh))).padStart(2, "0");
    const mmN = String(Math.min(59, Number(mm))).padStart(2, "0");
    emit("update:modelValue", `${hhN}:${mmN}`);
    selectedHour.value = Number(hhN);
    selectedMinute.value = Number(mmN);
  } else {
    emit("update:modelValue", localValue.value);
  }
}

// Validate and normalize to HH:MM; clamp hours/minutes
function normalizeToTime(val: string) {
  const d = digitsOnly(val);
  const hh = d.slice(0, 2);
  const mm = d.slice(2, 4);

  const hnum = hh ? Math.min(23, Number(hh)) : null;
  const mnum = mm ? Math.min(59, Number(mm)) : null;

  if (hnum == null && mnum == null) return "";
  const hhStr = hnum == null ? "" : String(hnum).padStart(2, "0");
  const mmStr = mnum == null ? "" : String(mnum).padStart(2, "0");
  if (hnum == null) return `:${mmStr}`;
  if (mnum == null) return `${hhStr}`;
  return `${hhStr}:${mmStr}`;
}

function onInput(e: Event) {
  const v = (e.target as HTMLInputElement).value;
  const d = digitsOnly(v);
  localValue.value = formatFromDigits(d);
  // emit on-the-fly when we have full hhmm
  if (d.length >= 4) {
    const hh = d.slice(0, 2);
    const mm = d.slice(2, 4);
    const hhN = String(Math.min(23, Number(hh))).padStart(2, "0");
    const mmN = String(Math.min(59, Number(mm))).padStart(2, "0");
    emit("update:modelValue", `${hhN}:${mmN}`);
    // reflect typed value in selection so UI matches input
    selectedHour.value = Number(hhN);
    selectedMinute.value = Number(mmN);
  } else {
    // update model to partial value so parent can show state if desired
    emit("update:modelValue", localValue.value);
  }
}

function onBlur() {
  const normalized = normalizeToTime(localValue.value);
  // If it is partial like '7' or ':5' try to turn into valid time
  if (normalized) {
    // ensure full HH:MM when possible
    const d = digitsOnly(normalized).padEnd(4, "0");
    const hh = String(Math.min(23, Number(d.slice(0, 2)))).padStart(2, "0");
    const mm = String(Math.min(59, Number(d.slice(2, 4)))).padStart(2, "0");
    const full = `${hh}:${mm}`;
    localValue.value = full;
    emit("update:modelValue", full);
    // reflect normalized value in selection
    selectedHour.value = Number(hh);
    selectedMinute.value = Number(mm);
  } else {
    localValue.value = "";
    emit("update:modelValue", "");
    selectedHour.value = null;
    selectedMinute.value = null;
  }
}

// Clock state
const mode = ref<"hour" | "minute">("hour");
const selectedHour = ref<number | null>(null);
const selectedMinute = ref<number | null>(null);

// When dropdown opens, initialize clock from current value
function handleOpened() {
  const v = props.modelValue ?? "";
  const m = v.match(/(\d{1,2}):(\d{1,2})/);
  if (m) {
    selectedHour.value = Number(m[1]);
    selectedMinute.value = Number(m[2]);
    mode.value = "hour";
  } else {
    selectedHour.value = null;
    selectedMinute.value = null;
    mode.value = "hour";
  }

  // Focus the input so the user can type immediately after opening
  nextTick(() => {
    inputRef.value?.focus();
    try { inputRef.value?.select(); } catch (e) { /* noop */ }
  });
}

function selectHour(h: number, closeMenu?: () => void) {
  selectedHour.value = h;
  // move to minute selection
  mode.value = "minute";
}

function selectMinute(m: number) {
  selectedMinute.value = m;
  // commit time but keep menu open so user can adjust
  const hh = String(selectedHour.value ?? 0).padStart(2, "0");
  const mm = String(m).padStart(2, "0");
  const time = `${hh}:${mm}`;
  localValue.value = time;
  emit("update:modelValue", time);
}

function clearTime(closeMenu?: () => void) {
  selectedHour.value = null;
  selectedMinute.value = null;
  localValue.value = "";
  emit("update:modelValue", "");
  if (typeof closeMenu === "function") closeMenu();
}

// Keep selectedHour/selectedMinute in sync when parent updates the modelValue externally
watch(
  () => props.modelValue,
  (v) => {
    if (!v) {
      selectedHour.value = null;
      selectedMinute.value = null;
      return;
    }
    const m = String(v).match(/(\d{1,2}):(\d{1,2})/);
    if (m) {
      selectedHour.value = Number(m[1]);
      selectedMinute.value = Number(m[2]);
    } else {
      const d = digitsOnly(String(v));
      if (d.length >= 2) selectedHour.value = Number(d.slice(0, 2));
      else selectedHour.value = null;
      if (d.length >= 4) selectedMinute.value = Number(d.slice(2, 4));
      else selectedMinute.value = null;
    }
  }
);

// For rendering clock nodes we compute positions for N nodes
function nodePos(i: number, total: number, radius = 80) {
  // angle start at -90 (top)
  const angle = (i / total) * Math.PI * 2 - Math.PI / 2;
  const x = Math.round(Math.cos(angle) * radius);
  const y = Math.round(Math.sin(angle) * radius);
  return { left: `calc(50% + ${x}px)`, top: `calc(50% + ${y}px)` };
}

const hoursOuter = Array.from({ length: 12 }).map((_, i) => i);
const hoursInner = Array.from({ length: 12 }).map((_, i) => i + 12);
const minutes = Array.from({ length: 12 }).map((_, i) => i * 5);

// Ref to the clock DOM for click coordinate calculations
const clockRef = ref<HTMLElement | null>(null);

function minuteFromClick(e: MouseEvent, el: HTMLElement) {
  const rect = el.getBoundingClientRect();
  const cx = rect.left + rect.width / 2;
  const cy = rect.top + rect.height / 2;
  const dx = e.clientX - cx;
  const dy = e.clientY - cy;
  let a = Math.atan2(dy, dx); // -PI..PI, 0 is to the right
  // Convert so 0 is at top (12 o'clock)
  let aTop = a + Math.PI / 2;
  if (aTop < 0) aTop += Math.PI * 2;
  const minute = Math.round((aTop / (Math.PI * 2)) * 60) % 60;
  return minute;
}

function minutePos(minute: number, radius = 76) {
  const angle = (minute / 60) * Math.PI * 2 - Math.PI / 2;
  const x = Math.round(Math.cos(angle) * radius);
  const y = Math.round(Math.sin(angle) * radius);
  return { left: `calc(50% + ${x}px)`, top: `calc(50% + ${y}px)` };
}

function onClockClick(e: MouseEvent, closeMenu?: () => void) {
  // If not in minute mode yet, switch to minute selection
  if (mode.value !== 'minute') {
    mode.value = 'minute';
    return;
  }
  if (!clockRef.value) return;
  const m = minuteFromClick(e, clockRef.value);
  selectMinute(m);
}

function onPanelKeydown(e: KeyboardEvent, closeMenu?: () => void) {
  switch (e.key) {
    case 'Escape':
      menuOpen.value = false;
      if (typeof closeMenu === 'function') closeMenu();
      break;
    case 'Enter':
      e.preventDefault();
      commitOrMoveNext(closeMenu);
      break;
    case 'Home':
      e.preventDefault();
      if (mode.value === 'hour') selectedHour.value = 0;
      else selectedMinute.value = 0;
      break;
    case 'End':
      e.preventDefault();
      if (mode.value === 'hour') selectedHour.value = 23;
      else selectedMinute.value = 59;
      break;
    case 'PageUp':
      e.preventDefault();
      if (mode.value === 'hour') incHour(1);
      else incMinute(5);
      break;
    case 'PageDown':
      e.preventDefault();
      if (mode.value === 'hour') incHour(-1);
      else incMinute(-5);
      break;
  }
}

</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu v-model:open="menuOpen" :class="props.class" align="start" :matchTriggerWidth="true" @opened="handleOpened">
      <template #activator="{ open, toggle }">
        <div class="relative">
          <input
            :id="inputId"
            :value="localValue"
            type="text"
            inputmode="numeric"
            maxlength="5"
            pattern="[0-9]{1,2}:[0-9]{1,2}"
            :name="name"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="!!error || undefined"
            :class="cn(
              'h-10 w-full rounded-lg border bg-card px-3 text-sm text-foreground placeholder:text-muted-foreground/70',
              'border-border hover:border-border/80',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
              disabled && 'opacity-60 cursor-not-allowed',
              error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger',
              hasLeft && 'pl-10',
              hasRight && 'pr-10',
              props.class
            )"
            ref="inputRef"
            @input="onInput"
            @blur="onBlur"
            @keydown.stop="onKeyDown"
            @paste.stop.prevent="onPaste"
            @click.stop="toggle()"
          />

          <div v-if="hasLeft" class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-auto">
            <slot name="left" />
          </div>

          <div v-if="hasRight" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-auto">
            <slot name="right" />
          </div>
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="p-3 w-full">
          <div class="mb-3" />

          <div :id="panelId" role="dialog" aria-label="Wybór godziny" tabindex="0" @keydown="onPanelKeydown($event, closeMenu)" ref="clockRef" @click.self="onClockClick($event, closeMenu)" class="relative mx-auto w-full max-w-64 h-64 rounded-full bg-secondary/40">
            <!-- nodes -->
            <template v-if="mode === 'hour'">
              <!-- outer ring: hours 0-11 -->
              <template v-for="(h, idx) in hoursOuter" :key="`out-${h}`">
                <button
                  type="button"
                  class="absolute -translate-x-1/2 -translate-y-1/2 flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium"
                  :class="[selectedHour === h ? 'bg-primary text-white z-10' : 'text-foreground hover:bg-primary/10 cursor-pointer', 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40']"
                  :aria-pressed="selectedHour === h"
                  :aria-label="`Godzina ${String(h).padStart(2,'0')}`"
                  :style="nodePos(idx, hoursOuter.length, 96)"
                  @click.stop.prevent="selectHour(h)"
                >
                  {{ h }}
                </button>
              </template>

              <!-- inner ring: hours 12-23 -->
              <template v-for="(h, idx) in hoursInner" :key="`in-${h}`">
                <button
                  type="button"
                  class="absolute -translate-x-1/2 -translate-y-1/2 flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium"
                  :class="[selectedHour === h ? 'bg-primary text-white z-10' : 'text-foreground hover:bg-primary/10 cursor-pointer', 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40']"
                  :aria-pressed="selectedHour === h"
                  :aria-label="`Godzina ${String(h).padStart(2,'0')}`"
                  :style="nodePos(idx, hoursInner.length, 56)"
                  @click.stop.prevent="selectHour(h)"
                >
                  {{ h }}
                </button> 
              </template>
            </template>

            <template v-else>
              <template v-for="(m, idx) in minutes" :key="m">
                <button
                  type="button"
                  class="absolute -translate-x-1/2 -translate-y-1/2 flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium"
                  :class="[selectedMinute === m ? 'bg-primary text-white z-10' : 'text-foreground hover:bg-primary/10 cursor-pointer', 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40']"
                  :aria-pressed="selectedMinute === m"
                  :aria-label="`Minuta ${String(m).padStart(2,'0')}`"
                  :style="nodePos(idx, minutes.length, 96)"
                  @click.stop.prevent="selectMinute(m)"
                >
                  {{ String(m).padStart(2, '0') }}
                </button>
              </template>
              <!-- marker for exact selected minute (visible also when it's not a multiple of 5) -->
              <!-- filled marker for exact selected minute (visible when not multiple of 5) -->
              <div
                v-if="selectedMinute != null && (selectedMinute % 5) !== 0"
                :style="minutePos(selectedMinute, 72)"
                class="absolute -translate-x-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center text-xs font-medium z-30 cursor-grab"
                @pointerdown.stop.prevent="startDrag($event as PointerEvent)"
              >
                {{ String(selectedMinute).padStart(2, '0') }}
              </div>

              <!-- small drag handle for multiples of 5 (visible but not duplicate display) -->
              <div
                v-if="selectedMinute != null && (selectedMinute % 5) === 0"
                :style="minutePos(selectedMinute, 72)"
                class="absolute -translate-x-1/2 -translate-y-1/2 w-4 h-4 rounded-full ring-2 ring-primary z-30 cursor-grab"
                @pointerdown.stop.prevent="startDrag($event as PointerEvent)"
              ></div>
            </template>

            <!-- center marker -->
            <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-2 h-2 rounded-full bg-foreground"></div>
          </div>

          <!-- panel actions -->
          <div class="absolute right-3 bottom-3">
            <Button variant="primary" size="sm" @click="closeMenu()">Wybierz</Button>
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
