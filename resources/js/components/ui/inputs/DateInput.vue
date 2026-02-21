<script setup lang="ts">
import { ref, computed, watch, nextTick, useSlots, onBeforeUnmount } from "vue";
import { useI18n } from "@/composables/useI18n";
import { cn } from "@/lib/helpers";
import DropdownMenu from "@/components/ui/DropdownMenu.vue";
import Button from "@/components/ui/Button.vue";
import Icon from "@/components/ui/Icon.vue";

// API: modelValue is an ISO date string (YYYY-MM-DD) or empty string/null when unset.
// Display in input uses Polish mask dd.mm.rrrr (dd.mm.yyyy)
const props = withDefaults(
  defineProps<{
    modelValue: string | null;
    id?: string;
    label?: string;
    placeholder?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    name?: string;
    autocomplete?: string;
    class?: string;
    min?: string | null; // min date in YYYY-MM-DD
    max?: string | null; // max date in YYYY-MM-DD
    disabledDates?: string[]; // array of YYYY-MM-DD that are not selectable
    onlyDates?: string[]; // if provided, ONLY these dates are selectable
    clearable?: boolean;
  }>(),
  {
    placeholder: "dd.mm.rrrr",
    disabled: false,
    min: null,
    max: null,
    disabledDates: () => [],
    onlyDates: () => [],
    clearable: false,
  }
);

const emit = defineEmits<{ (e: "update:modelValue", value: string | null): void }>();

const { t } = useI18n();
const inputId = props.id ?? `date_${Math.random().toString(16).slice(2)}`;
const localValue = ref(""); // display value dd.mm.yyyy partial allowed
const inputRef = ref<HTMLInputElement | null>(null);
const slots = useSlots();
const hasLeft = computed(() => !!slots.left);
const hasRight = computed(() => !!slots.right);
const showClear = computed(() => !!props.clearable && !!(props.modelValue || localValue.value));
const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.hint) ids.push(`${inputId}_hint`);
  if (props.error) ids.push(`${inputId}_err`);
  return ids.length ? ids.join(' ') : undefined;
});

const menuOpen = ref(false);
const panelId = `${inputId}_panel`;

// helpers for date parsing/formatting
function digitsOnly(s: string) { return (s || "").replace(/\D/g, ""); }

function formatFromDigits(d: string) {
  const dd = d.slice(0, 8);
  if (!dd) return "";
  if (dd.length <= 2) return dd;
  if (dd.length <= 4) return `${dd.slice(0,2)}.${dd.slice(2)}`;
  return `${dd.slice(0,2)}.${dd.slice(2,4)}.${dd.slice(4,8)}`;
}

function pad(n: number) { return String(n).padStart(2, '0'); }
function ymdFromDate(d: Date) { return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }
function dateFromYmd(s: string) { const m = String(s).match(/(\d{4})-(\d{2})-(\d{2})/); return m ? new Date(Number(m[1]), Number(m[2]) -1, Number(m[3])) : null; }
function displayFromYmd(s: string | null) { if (!s) return ""; const d = dateFromYmd(s); if (!d) return ""; return `${pad(d.getDate())}.${pad(d.getMonth()+1)}.${d.getFullYear()}`; }

function clampDateStr(s: string) {
  if (!s) return s;
  const d = dateFromYmd(s);
  if (!d) return null;
  if (props.min && props.min > s) return props.min;
  if (props.max && props.max < s) return props.max;
  return s;
}

// Convert display like dd.mm.yyyy (partial allowed) to ISO yyyy-mm-dd when full
function parseDisplayToYmd(display: string) {
  const d = digitsOnly(display);
  if (d.length < 8) return null;
  const day = Number(d.slice(0,2));
  const month = Number(d.slice(2,4));
  const year = Number(d.slice(4,8));
  if (year < 1 || month < 1 || month > 12 || day < 1) return null;
  const dt = new Date(year, month-1, day);
  if (dt.getFullYear() !== year || dt.getMonth() !== month-1 || dt.getDate() !== day) return null;
  return ymdFromDate(dt);
}

function normalizeOnBlur() {
  const parsed = parseDisplayToYmd(localValue.value);
  if (parsed) {
    const clamped = clampDateStr(parsed);
    if (clamped) {
      localValue.value = displayFromYmd(clamped);
      emit('update:modelValue', clamped);
      selectedDate.value = dateFromYmd(clamped);
    } else {
      // out of range -> set to closest bound
      localValue.value = displayFromYmd(clampDateStr(parsed));
      emit('update:modelValue', clampDateStr(parsed));
      selectedDate.value = dateFromYmd(clampDateStr(parsed) as string);
    }
  } else {
    // keep partial value but do not emit invalid full value
    if (!localValue.value) {
      // Avoid emitting when value is already null (prevents needless refreshes in filters)
      if (props.modelValue !== null) emit('update:modelValue', null);
      selectedDate.value = null;
    }
  }
}

function onInput(e: Event) {
  const v = (e.target as HTMLInputElement).value;
  const d = digitsOnly(v);
  localValue.value = formatFromDigits(d);
  if (d.length >= 8) {
    const ymd = parseDisplayToYmd(localValue.value);
    if (ymd) {
      const clamped = clampDateStr(ymd);
      if (clamped) {
        emit('update:modelValue', clamped);
        selectedDate.value = dateFromYmd(clamped);
      } else {
        emit('update:modelValue', null);
      }
    } else {
      emit('update:modelValue', null);
    }
  } else {
    // API contract: emit only ISO (YYYY-MM-DD) or null.
    // Keep partial typing local; only emit null when the field is cleared.
    if (!d.length) {
      emit('update:modelValue', null);
      selectedDate.value = null;
    }
  }
}

function onPaste(e: ClipboardEvent) {
  e.preventDefault();
  const raw = (e.clipboardData?.getData('text') ?? '');
  // Accept both dd.mm.yyyy and yyyy-mm-dd
  const iso = raw.match(/(\d{4}-\d{2}-\d{2})/)?.[1];
  if (iso) {
    localValue.value = displayFromYmd(iso);
    emit('update:modelValue', iso);
    selectedDate.value = dateFromYmd(iso);
    return;
  }
  const digits = digitsOnly(raw).slice(0,8);
  localValue.value = formatFromDigits(digits);
  const ymd = parseDisplayToYmd(localValue.value);
  if (ymd) {
    emit('update:modelValue', ymd);
    selectedDate.value = dateFromYmd(ymd);
  }
}

function onKeyDown(e: KeyboardEvent) {
  const allowed = ["Backspace","Delete","ArrowLeft","ArrowRight","Home","End","Tab","Enter"];
  if (menuOpen.value && (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
    e.preventDefault();
    // open panel navigation uses panel handler instead
    return;
  }
  if (allowed.includes(e.key)) return;
  if (e.ctrlKey || e.metaKey) return;
  if (/^\d$/.test(e.key)) return;
  // allow dot and hyphen for convenience
  if (e.key === '.' || e.key === '-') return;
  e.preventDefault();
}

function onBlur() { normalizeOnBlur(); }

function clearValue(e?: Event) {
  if (e) {
    e.preventDefault();
    e.stopPropagation();
  }
  if (props.disabled) return;
  localValue.value = "";
  selectedDate.value = null;
  emit('update:modelValue', null);
}

// Calendar state
const viewYear = ref<number>((new Date()).getFullYear());
const viewMonth = ref<number>((new Date()).getMonth()); // 0-based
const selectedDate = ref<Date | null>(null);
const panelMode = ref<'day'|'month'|'year'>('day');
const months = Array.from({ length: 12 }).map((_, i) => i);
const yearsFor = computed(() => {
  const start = 1970;
  const end = 2050;
  return Array.from({ length: end - start + 1 }).map((_, i) => start + i);
});

function prevYear() { viewYear.value--; }
function nextYear() { viewYear.value++; }

const todayDate = new Date();
const todayYmd = ymdFromDate(todayDate);
function isToday(d: Date) { return ymdFromDate(d) === todayYmd; }

function dateLabel(d: Date) {
  let s = d.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  if (isToday(d)) s += ' (dzisiaj)';
  if (!isSelectable(d)) s += ' (wyłączony)';
  return s;
} 

// Refs for year scrolling and centering
const yearListRef = ref<HTMLElement | null>(null);
const yearItemRefs = ref<Record<number, HTMLElement | null>>({});
function setYearItemRef(el: HTMLElement | null, y: number) {
  if (el) yearItemRefs.value[y] = el;
  else delete yearItemRefs.value[y];
}

// Refs for day buttons to support focus management
const dayItemRefs = ref<Record<string, HTMLElement | null>>({});
function setDayItemRef(el: HTMLElement | null, d: Date) {
  const k = ymdFromDate(d);
  if (el) dayItemRefs.value[k] = el;
  else delete dayItemRefs.value[k];
}

function focusDay(d: Date | null) {
  nextTick(() => {
    if (!d) return;
    const el = dayItemRefs.value[ymdFromDate(d)];
    if (el) el.focus();
  });
}

// Live region for screen reader announcements
const liveAnnouncement = ref<string>('');
watch(selectedDate, (d) => {
  if (d) {
    liveAnnouncement.value = `Wybrano ${d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })}`;
  } else {
    liveAnnouncement.value = '';
  }
});

function scrollYearIntoView(targetYear?: number) {
  nextTick(() => {
    const container = yearListRef.value;
    if (!container) return;
    const ty = targetYear ?? viewYear.value ?? todayDate.getFullYear();
    const el = yearItemRefs.value[ty] ?? yearItemRefs.value[todayDate.getFullYear()];
    if (!el) return;
    const offsetTop = el.offsetTop - (container.clientHeight / 2) + (el.clientHeight / 2);
    container.scrollTop = Math.max(0, Math.round(offsetTop));
  });
}

function handleOpened() {
  const v = props.modelValue ?? null;
  panelMode.value = 'day';
  if (v) {
    const d = dateFromYmd(v);
    if (d) {
      selectedDate.value = d;
      viewYear.value = d.getFullYear();
      viewMonth.value = d.getMonth();
    }
  } else {
    selectedDate.value = null;
    const now = new Date();
    viewYear.value = now.getFullYear();
    viewMonth.value = now.getMonth();
  }
  nextTick(() => { inputRef.value?.focus(); try { inputRef.value?.select(); } catch (e) {} });
}  

function daysForMonth(year: number, month: number) {
  // Return array of Date objects covering a 6x7 grid starting Sunday
  const first = new Date(year, month, 1);
  const startDay = first.getDay(); // 0..6 where 0 is Sunday
  const start = new Date(year, month, 1 - startDay);
  const days: Date[] = [];
  for (let i=0;i<42;i++) {
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    days.push(d);
  }
  return days;
}

function daysInMonth(year: number, month: number) {
  return new Date(year, month + 1, 0).getDate();
}

function isMonthSelectable(year: number, month: number) {
  // If onlyDates provided, month selectable if any of those dates is in the month
  if (props.onlyDates && props.onlyDates.length) {
    return props.onlyDates.some((s) => {
      const d = dateFromYmd(s);
      return d && d.getFullYear() === year && d.getMonth() === month;
    });
  }

  // Otherwise, check if there exists any selectable day in that month
  const days = daysInMonth(year, month);
  for (let day = 1; day <= days; day++) {
    const d = new Date(year, month, day);
    if (isSelectable(d)) return true;
  }
  return false;
}

function isYearSelectable(year: number) {
  if (props.onlyDates && props.onlyDates.length) {
    return props.onlyDates.some((s) => {
      const d = dateFromYmd(s);
      return d && d.getFullYear() === year;
    });
  }

  for (let m = 0; m < 12; m++) {
    if (isMonthSelectable(year, m)) return true;
  }
  return false;
}

function isSelectable(d: Date) {
  const ymd = ymdFromDate(d);
  if (props.onlyDates && props.onlyDates.length) {
    return props.onlyDates.includes(ymd);
  }
  if (props.disabledDates && props.disabledDates.includes(ymd)) return false;
  if (props.min && ymd < props.min) return false;
  if (props.max && ymd > props.max) return false;
  return true;
}

function selectDate(d: Date) {
  if (!isSelectable(d)) return;
  selectedDate.value = d;
  localValue.value = displayFromYmd(ymdFromDate(d));
  emit('update:modelValue', ymdFromDate(d));
  // keep menu open so user can adjust; only 'Wybierz' will close
  focusDay(d);
} 

function clearDate(close?: () => void) {
  selectedDate.value = null;
  localValue.value = '';
  emit('update:modelValue', null);
  if (typeof close === 'function') close();
}

function prevMonth() { if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value--; } else viewMonth.value--; }
function nextMonth() { if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++; } else viewMonth.value++; }

function onPanelKeydown(e: KeyboardEvent, close?: () => void) {
  switch (e.key) {
    case 'Escape': menuOpen.value = false; if (typeof close === 'function') close(); break;
    case 'Enter': e.preventDefault(); if (panelMode.value !== 'day') { panelMode.value = 'day'; } else if (selectedDate.value && typeof close === 'function') { close(); menuOpen.value = false; } break;
    case 'Home': e.preventDefault(); if (panelMode.value === 'year') { const start = yearsFor.value[0]; viewYear.value = start; } break;
    case 'PageUp': e.preventDefault(); if (panelMode.value === 'month') prevMonth(); else if (panelMode.value === 'year') { viewYear.value -= 12; } else prevMonth(); break;
    case 'PageDown': e.preventDefault(); if (panelMode.value === 'month') nextMonth(); else if (panelMode.value === 'year') { viewYear.value += 12; } else nextMonth(); break;
    case 'ArrowLeft': e.preventDefault(); if (panelMode.value === 'month') { viewMonth.value = (viewMonth.value + 11) % 12; } else if (panelMode.value === 'year') { viewYear.value--; } else { if (selectedDate.value) { const d = selectedDate.value; selectedDate.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() - 1); } else { selectedDate.value = new Date(viewYear.value, viewMonth.value, 1); } } break;
    case 'ArrowRight': e.preventDefault(); if (panelMode.value === 'month') { viewMonth.value = (viewMonth.value + 1) % 12; } else if (panelMode.value === 'year') { viewYear.value++; } else { if (selectedDate.value) { const d = selectedDate.value; selectedDate.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1); } else { selectedDate.value = new Date(viewYear.value, viewMonth.value, 1); } } break;
    case 'ArrowUp': e.preventDefault(); if (panelMode.value === 'month') { viewMonth.value = (viewMonth.value + 12 - 3) % 12; } else if (panelMode.value === 'year') { viewYear.value -= 4; } else { if (selectedDate.value) { const d = selectedDate.value; selectedDate.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() - 7); } else { selectedDate.value = new Date(viewYear.value, viewMonth.value, 1); } } break;
    case 'ArrowDown': e.preventDefault(); if (panelMode.value === 'month') { viewMonth.value = (viewMonth.value + 3) % 12; } else if (panelMode.value === 'year') { viewYear.value += 4; } else { if (selectedDate.value) { const d = selectedDate.value; selectedDate.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 7); } else { selectedDate.value = new Date(viewYear.value, viewMonth.value, 1); } } break;
  }

  // After keyboard navigation, focus the currently selected day if any
  nextTick(() => { if (selectedDate.value) { const el = dayItemRefs.value[ymdFromDate(selectedDate.value)]; if (el) el.focus(); } });
} 

watch(() => panelMode.value, (m) => { if (m === 'year') scrollYearIntoView(); });

// Keep localValue in sync with external changes (also on mount)
watch(
  () => props.modelValue,
  (v) => {
  if (!v) {
    localValue.value = '';
    selectedDate.value = null;
    return;
  }

  // If parent provided a full ISO date (YYYY-MM-DD), format it and update selection
  const isoMatch = String(v).match(/^\d{4}-\d{2}-\d{2}$/);
  if (isoMatch) {
    localValue.value = displayFromYmd(v);
    selectedDate.value = dateFromYmd(v);
    return;
  }

  // Otherwise treat value as a partial display string (preserve what user typed)
  localValue.value = String(v);
  },
  { immediate: true }
);

</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu v-model:open="menuOpen" :class="props.class" align="auto" width="xl" :matchTriggerWidth="false" @opened="handleOpened">
      <template #activator="{ open, toggle }">
        <div class="relative">
          <input
            :id="inputId"
            :value="localValue"
            type="text"
            inputmode="numeric"
            maxlength="10"
            :name="name"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="!!error || undefined"
            :aria-describedby="describedBy"
            :aria-haspopup="'dialog'"
            :aria-expanded="menuOpen ? 'true' : 'false'"
            :aria-controls="panelId"
            :class="cn(
              'min-h-11 w-full rounded-lg border bg-card px-3 text-sm text-foreground placeholder:text-muted-foreground/70',
              hasLeft && 'pl-10',
              (hasRight || showClear) && 'pr-10',
              'border-border hover:border-border/80',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
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

          <Button
            v-else-if="showClear"
            variant="ghost"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground min-h-11"
            @click="clearValue"
            :disabled="disabled"
            tabindex="-1"
            aria-label="Wyczyść datę"
          >
            <Icon name="x" :size="16" />
          </Button>
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="p-4 w-full">
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center justify-between w-full gap-4">
            <div class="flex items-center gap-2">
              <Button variant="ghost" size="sm" @click="prevMonth" aria-label="Poprzedni miesiąc">‹</Button>
              <Button variant="ghost" :id="`${panelId}_month`" class="text-sm font-medium" @click="panelMode = 'month'" :aria-pressed="panelMode === 'month' ? 'true' : 'false'">{{ new Date(viewYear, viewMonth).toLocaleString('pl-PL', { month: 'long' }) }}</Button> 
              <Button variant="ghost" size="sm" @click="nextMonth" aria-label="Następny miesiąc">›</Button>
            </div>

            <div class="flex items-center gap-2">
              <Button variant="ghost" size="sm" @click="prevYear" aria-label="Poprzedni rok">‹</Button>
              <Button variant="ghost" class="text-sm font-medium" @click="panelMode = 'year'">{{ viewYear }}</Button>
              <Button variant="ghost" size="sm" @click="nextYear" aria-label="Następny rok">›</Button>
            </div>
          </div> 
          </div>

          <div :id="panelId" role="dialog" :aria-labelledby="`${panelId}_heading`" tabindex="0" @keydown="onPanelKeydown($event, closeMenu)" class="w-full min-h-56">
            <div :id="`${panelId}_heading`" class="sr-only">{{ new Date(viewYear, viewMonth).toLocaleString('pl-PL', { month: 'long', year: 'numeric' }) }}</div>
            <div v-if="panelMode === 'day'" class="grid grid-cols-7 gap-2 text-xs text-center mb-3">
              <div class="text-sm font-medium text-primary">S</div>
              <div class="text-sm font-medium text-primary">M</div>
              <div class="text-sm font-medium text-primary">T</div>
              <div class="text-sm font-medium text-primary">W</div>
              <div class="text-sm font-medium text-primary">T</div>
              <div class="text-sm font-medium text-primary">F</div>
              <div class="text-sm font-medium text-primary">S</div>
            </div>

            <!-- month picker -->
            <div v-if="panelMode === 'month'" class="grid grid-cols-3 gap-2 mb-2">
              <button
                v-for="m in months"
                :key="m"
                type="button"
                :disabled="!isMonthSelectable(viewYear, m)"
                :aria-pressed="m === viewMonth ? 'true' : 'false'"
                :class="[
                  'py-2 rounded-md text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
                  m === viewMonth
                    ? 'bg-primary text-white'
                    : (isMonthSelectable(viewYear, m) ? 'hover:bg-primary/10 text-foreground cursor-pointer' : 'opacity-40 cursor-not-allowed'),
                  (viewYear === todayDate.getFullYear() && m === todayDate.getMonth()) ? 'ring-2 ring-primary/30' : ''
                ]"
                @click="() => { if (isMonthSelectable(viewYear, m)) { viewMonth = m; panelMode = 'day'; } }"
              >
                {{ new Date(viewYear, m).toLocaleString('pl-PL', { month: 'short' }) }}
              </button>
            </div>

            <!-- year picker -->
            <div v-else-if="panelMode === 'year'" class="mb-2">
              <div ref="yearListRef" class="max-h-56 overflow-auto scroll-smooth">
                <div class="grid grid-cols-4 gap-2 p-1">
                  <button
                    v-for="y in yearsFor"
                    :key="y"
                    :ref="(el) => setYearItemRef(el as HTMLElement | null, y)"
                    type="button"
                    :disabled="!isYearSelectable(y)"
                    :aria-pressed="y === viewYear ? 'true' : 'false'"
                    :aria-label="`Rok ${y}`"
                    :class="[
                      'py-2 rounded-md text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
                      y === viewYear
                        ? 'bg-primary text-white'
                        : (isYearSelectable(y) ? 'hover:bg-primary/10 text-foreground cursor-pointer' : 'opacity-40 cursor-not-allowed'),
                      y === todayDate.getFullYear() ? 'ring-2 ring-primary/30' : ''
                    ]"
                    @click="() => { if (isYearSelectable(y)) { viewYear = y; panelMode = 'day'; } }"
                  >
                    {{ y }}
                  </button> 
                </div>
              </div>
            </div>  

            <!-- day grid -->
            <div v-else role="grid" :aria-labelledby="`${panelId}_heading`" class="grid grid-cols-7 gap-2">
              <button
                v-for="d in daysForMonth(viewYear, viewMonth)"
                :key="d.toISOString()"
                type="button"
                :ref="(el) => setDayItemRef(el as HTMLElement | null, d)"
                :aria-label="dateLabel(d)"
                :aria-selected="selectedDate && selectedDate.getFullYear() === d.getFullYear() && selectedDate.getMonth() === d.getMonth() && selectedDate.getDate() === d.getDate() ? 'true' : 'false'"
                :aria-disabled="!isSelectable(d) ? 'true' : 'false'"
                :class="cn(
                  'py-2.5 rounded-md text-sm font-semibold',
                  d.getMonth() !== viewMonth ? 'text-muted-foreground' : 'text-foreground',
                  !isSelectable(d)
                    ? 'opacity-40 cursor-not-allowed line-through'
                    : (selectedDate && selectedDate.getFullYear() === d.getFullYear() && selectedDate.getMonth() === d.getMonth() && selectedDate.getDate() === d.getDate()
                        ? ''
                        : 'hover:bg-primary/10 cursor-pointer'),
                  isToday(d) && !(selectedDate && selectedDate.getFullYear() === d.getFullYear() && selectedDate.getMonth() === d.getMonth() && selectedDate.getDate() === d.getDate()) ? 'ring-2 ring-primary/30' : '',
                  selectedDate && selectedDate.getFullYear() === d.getFullYear() && selectedDate.getMonth() === d.getMonth() && selectedDate.getDate() === d.getDate() ? 'bg-primary text-white' : ''
                )"
                @click="selectDate(d)"
                :disabled="!isSelectable(d)"
              >
                {{ d.getDate() }}
              </button>
            </div>   
          </div>

          <div aria-live="polite" class="sr-only" role="status">{{ liveAnnouncement }}</div>

          <div class="mt-4 flex justify-end gap-2">
            <Button
              v-if="clearable"
              variant="ghost"
              size="sm"
              type="button"
              :disabled="disabled"
              @click="(e: any) => { clearValue(e); }"
            >
              {{ t('common.clearButton') }}
            </Button>
            <Button variant="primary" size="sm" @click="closeMenu(); menuOpen = false">{{ t('common.selectButton') }}</Button>
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
