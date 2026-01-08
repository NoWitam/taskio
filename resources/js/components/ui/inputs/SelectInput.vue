<script setup lang="ts">
import { ref, computed, watch, nextTick, useSlots, onMounted, onBeforeUnmount } from "vue";
import { cn } from "@/lib/helpers";
import DropdownMenu from "@/components/ui/DropdownMenu.vue";
import Icon from "@/components/ui/Icon.vue";
import Checkbox from "@/components/ui/Checkbox.vue";

type Option = { [k: string]: any } & { label: string; value: any; disabled?: boolean };

const props = withDefaults(
  defineProps<{
    modelValue: any;
    id?: string;
    label?: string;
    placeholder?: string;
    options?: Option[] | string; // array or url
    loader?: (url?: string, query?: string) => Promise<{ data: Option[]; next?: string }>;
    multiple?: boolean;
    optionLabelKey?: string;
    optionValueKey?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;
    fetchPageSize?: number;
  }>(),
  { multiple: false, optionLabelKey: "label", optionValueKey: "value", fetchPageSize: 20 }
);

const emit = defineEmits<{ (e: "update:modelValue", value: any): void }>();

const inputId = props.id ?? `sel_${Math.random().toString(16).slice(2)}`;
const slots = useSlots();
const hasLeft = computed(() => !!slots.left);

const internalItems = ref<Option[]>([]);
const nextUrl = ref<string | null>(typeof props.options === "string" ? (props.options as string) : null);
const loading = ref(false);
const query = ref("");

const isRemote = computed(() => typeof props.options === "string" || !!props.loader);
const hasMore = computed(() => !!nextUrl.value);

// Initialize items for array-based options
watch(
  () => props.options,
  (v) => {
    if (Array.isArray(v)) {
      internalItems.value = v.slice();
      nextUrl.value = null;
    } else if (typeof v === "string") {
      internalItems.value = [];
      nextUrl.value = v;
    }
  },
  { immediate: true }
);

async function fetchNext() {
  if (loading.value) return;
  if (!nextUrl.value && !props.loader) return;
  loading.value = true;
  try {
    let res: any = null;
    if (props.loader) {
      res = await props.loader(nextUrl.value ?? undefined, query.value);
    } else if (nextUrl.value) {
      const url = new URL(nextUrl.value, window.location.origin);
      if (query.value) url.searchParams.set("q", query.value);
      url.searchParams.set("size", String(props.fetchPageSize));
      const r = await fetch(url.toString());
      res = await r.json();
    }

    if (res && Array.isArray(res.data)) {
      // if query changed and we have items, reset before appending
      internalItems.value = internalItems.value.concat(res.data);
      nextUrl.value = res.next ?? null;
    } else {
      nextUrl.value = null;
    }
  } catch (e) {
    console.error(e);
    nextUrl.value = null;
  } finally {
    loading.value = false;
  }
}

// search handler for both local and remote
function setQuery(q: string) {
  query.value = q;
  if (isRemote.value) {
    // reset pagination
    nextUrl.value = typeof props.options === "string" ? (props.options as string) : null;
    internalItems.value = [];
    fetchNext();
  }
}

// filtered for local only (remote is handled server-side)
const filteredItems = computed(() => {
  if (isRemote.value) return internalItems.value;
  if (!query.value) return internalItems.value;
  const q = query.value.toLowerCase();
  return internalItems.value.filter((it) => String(it[props.optionLabelKey]).toLowerCase().includes(q));
});

// model handling
const isMultiple = computed(() => !!props.multiple);

const selectedValues = computed({
  get() {
    return isMultiple.value ? (Array.isArray(props.modelValue) ? props.modelValue : []) : props.modelValue;
  },
  set(v) {
    emit("update:modelValue", v);
  },
});

function valueOf(it: Option) {
  return it[props.optionValueKey as string];
}

function isSelected(it: Option) {
  const val = valueOf(it);
  if (isMultiple.value) return (selectedValues.value as any[]).includes(val);
  return selectedValues.value === val;
}

function selectItem(it: Option, closeMenu?: () => void) {
  const val = valueOf(it);
  if (isMultiple.value) {
    const arr = Array.isArray(selectedValues.value) ? [...selectedValues.value] : [];
    const idx = arr.indexOf(val);
    if (idx >= 0) arr.splice(idx, 1);
    else arr.push(val);
    selectedValues.value = arr;
  } else {
    selectedValues.value = val;
    if (closeMenu) closeMenu();
  }
}

function removeValue(val: any) {
  if (isMultiple.value) {
    const arr = Array.isArray(selectedValues.value) ? [...selectedValues.value] : [];
    const idx = arr.indexOf(val);
    if (idx >= 0) {
      arr.splice(idx, 1);
      selectedValues.value = arr;
    }
  } else {
    selectedValues.value = null;
  }
}

const selectedItems = computed(() => {
  const mapVal = (v: any) => internalItems.value.find((it) => valueOf(it) === v) || { [props.optionLabelKey]: String(v), [props.optionValueKey]: v };
  if (isMultiple.value) return (selectedValues.value as any[]).map(mapVal);
  return selectedValues.value == null ? [] : [mapVal(selectedValues.value)];
});

function onScroll(e: Event) {
  const el = e.target as HTMLElement;
  if (!el) return;
  if (el.scrollHeight - el.scrollTop - el.clientHeight < 40) {
    if (!loading.value && hasMore.value) fetchNext();
  }
}

function handleOpened() {
  // when dropdown opens, ensure remote options are fetched on first open
  if (isRemote.value && !internalItems.value.length && !loading.value) {
    fetchNext();
  }
}

const badgesViewportRef = ref<HTMLElement | null>(null);

// Refs do "ukrytej" listy pomiarowej (renderujemy wszystkie badge poza ekranem)
const measureBadgeRefs = ref<HTMLElement[]>([]);
const moreMeasureRef = ref<HTMLElement | null>(null);

const visibleCount = ref<number>(Number.POSITIVE_INFINITY);
const moreText = ref("+0");

function setMeasureBadgeRef(el: HTMLElement | null, idx: number) {
  if (!el) return;
  measureBadgeRefs.value[idx] = el;
}

function getGapPx(el: HTMLElement) {
  const cs = getComputedStyle(el);
  // tailwindowy gap zwykle wpada w `columnGap`/`gap`
  const g = parseFloat(cs.columnGap || cs.gap || "0");
  return Number.isFinite(g) && g > 0 ? g : 8;
}

async function recalcVisibleBadges() {
  // działa tylko dla multi i gdy nie używasz custom slotu "selected"
  if (!isMultiple.value) return;
  if (!!slots.selected) return;

  await nextTick();

  const viewport = badgesViewportRef.value;
  if (!viewport) return;

  const wrapW = viewport.clientWidth;
  const gap = getGapPx(viewport);

  // Upewnij się, że mamy refy dla wszystkich badge
  const widths = selectedItems.value.map((_, i) => measureBadgeRefs.value[i]?.offsetWidth ?? 0);

  const totalWidth = (n: number) => {
    if (n <= 0) return 0;
    let sum = 0;
    for (let i = 0; i < n; i++) sum += widths[i] ?? 0;
    return sum + gap * (n - 1);
  };

  let vis = widths.length;

  // Najpierw spróbuj wszystkie bez "+N"
  if (totalWidth(vis) <= wrapW) {
    visibleCount.value = vis;
    moreText.value = "+0";
    return;
  }

  // Jeśli nie mieści się wszystko, iteracyjnie zmniejszaj vis
  while (vis >= 0) {
    const hidden = widths.length - vis;

    if (hidden > 0) {
      moreText.value = `+${hidden}`;
      await nextTick();
    }

    const moreW = hidden > 0 ? (moreMeasureRef.value?.offsetWidth ?? 0) : 0;
    const needed = totalWidth(vis) + (hidden > 0 ? gap + moreW : 0);

    if (needed <= wrapW) {
      visibleCount.value = vis;
      return;
    }

    vis--;
  }

  // fallback: nic nie wchodzi, pokaż tylko +N
  visibleCount.value = 0;
  moreText.value = `+${widths.length}`;
}

const visibleSelectedItems = computed(() => {
  if (!isMultiple.value) return selectedItems.value;
  const n = Number.isFinite(visibleCount.value) ? visibleCount.value : selectedItems.value.length;
  return selectedItems.value.slice(0, Math.max(0, n));
});

const hiddenSelectedItems = computed(() => {
  if (!isMultiple.value) return [];
  const n = Number.isFinite(visibleCount.value) ? visibleCount.value : selectedItems.value.length;
  return selectedItems.value.slice(Math.max(0, n));
});

const hiddenCount = computed(() => hiddenSelectedItems.value.length);

const hiddenTooltip = computed(() =>
  hiddenSelectedItems.value.map((it) => String(it[props.optionLabelKey])).join(", ")
);

// Recalc przy zmianie selekcji / options / rozmiaru
watch(
  () => [selectedItems.value, isMultiple.value, props.optionLabelKey, props.optionValueKey],
  async () => {
    // reset refs (bo liczba badge mogła się zmienić)
    measureBadgeRefs.value = [];
    visibleCount.value = selectedItems.value.length;
    await recalcVisibleBadges();
  },
  { deep: true, immediate: true }
);

let ro: ResizeObserver | null = null;

onMounted(() => {
  ro = new ResizeObserver(() => recalcVisibleBadges());
  if (badgesViewportRef.value) ro.observe(badgesViewportRef.value);
  window.addEventListener("resize", recalcVisibleBadges, { passive: true });
});

onBeforeUnmount(() => {
  if (ro && badgesViewportRef.value) ro.unobserve(badgesViewportRef.value);
  ro = null;
  window.removeEventListener("resize", recalcVisibleBadges as any);
});
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu :class="class" align="start" :matchTriggerWidth="true" @opened="handleOpened">
      <template #activator="{ open, toggle }">
        <!-- do mierzania szerokości badge -->
        <div class="relative flex">
          <div
            class="absolute -left-[9999px] top-0 h-0 overflow-hidden whitespace-nowrap pointer-events-none opacity-0"
            aria-hidden="true"
          >
            <div class="flex gap-2 items-center">
              <template v-for="(it, idx) in selectedItems" :key="it[props.optionValueKey]">
                <span
                  :ref="(el) => setMeasureBadgeRef(el as any, idx)"
                  class="inline-flex items-center gap-2 rounded-full bg-secondary/40 px-2 py-0.5 text-xs font-medium"
                >
                  <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                  <span class="text-muted-foreground">✕</span>
                </span>
              </template>

              <span
                ref="moreMeasureRef"
                class="inline-flex items-center rounded-full bg-secondary/40 px-2 py-0.5 text-xs font-medium"
              >
                {{ moreText }}
              </span>
            </div>
          </div>

          <div v-if="hasLeft" class="absolute inset-y-0 left-0 flex items-center justify-center w-10 pointer-events-none">
            <slot name="left" />
          </div>

          <div
            :id="inputId"
            @click.stop="toggle()"
            :class="cn(
              'min-h-[44px] w-full flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm text-foreground cursor-pointer',
              hasLeft && 'pl-10',
              'border-border hover:border-border/80',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
              disabled && 'opacity-60 cursor-not-allowed',
              error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger'
            )"
          >
          <div ref="badgesViewportRef" class="flex flex-1 min-w-0 gap-2 items-center overflow-hidden">
            <template v-if="$slots.selected">
              <template v-for="(it, idx) in selectedItems" :key="idx">
                <slot name="selected" :item="it" :remove="() => removeValue(it[props.optionValueKey] ?? it.value)" />
              </template>
              <span v-if="!selectedItems.length && placeholder" class="text-muted-foreground">{{ placeholder }}</span>
            </template>

            <template v-else>
              <template v-if="selectedItems.length">
                <!-- Multi select: badges -->
                <template v-if="isMultiple">
                  <div class="flex flex-nowrap gap-2 items-center min-w-0 overflow-hidden">
                    <template v-for="it in visibleSelectedItems" :key="it[props.optionValueKey]">
                      <span class="inline-flex items-center gap-2 rounded-full bg-secondary/40 px-2 py-0.5 text-xs font-medium">
                        <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                        <button
                          class="text-muted-foreground"
                          type="button"
                          @click.stop="removeValue(it[props.optionValueKey])"
                        >✕</button>
                      </span>
                    </template>

                    <!-- +N badge -->
                    <span
                      v-if="hiddenCount"
                      class="inline-flex items-center rounded-full bg-secondary/40 px-2 py-0.5 text-xs font-medium"
                      :title="hiddenTooltip"
                    >
                      {{ moreText }}
                    </span>
                  </div>
                </template>
                <!-- Single select: plain text -->
                <template v-else>
                  <span class="truncate">{{ selectedItems[0][props.optionLabelKey] }}</span>
                </template>
              </template>
              <span v-else class="text-muted-foreground">{{ placeholder }}</span>
            </template>
          </div>

            <div class="ml-2 text-muted-foreground flex items-center">
              <Icon name="chevron-down" :size="16" />
            </div>
          </div>
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="p-2">
          <slot name="panel-top" :query="query" :setQuery="setQuery" :loading="loading" :loadMore="fetchNext" :hasMore="hasMore" />

          <div class="flex flex-col gap-2 max-h-60 overflow-auto" @scroll="onScroll">
            <template v-if="$slots.item">
              <template v-for="(it, idx) in filteredItems" :key="it[props.optionValueKey]">
                <div class="flex items-center gap-2" @click="selectItem(it, closeMenu)">
                  <!-- Checkbox dla multi select -->
                  <Checkbox v-if="isMultiple" :checked="isSelected(it)" />
                  <slot name="item" :item="it" :index="idx" :select="() => selectItem(it, closeMenu)" :isSelected="isSelected(it)" />
                </div>
              </template>
            </template>

            <template v-else>
              <button
                v-for="(it, idx) in filteredItems"
                :key="it[props.optionValueKey]"
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2 font-semibold text-left text-sm transition"
                :class="[
                  it.disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer rounded-sm hover:text-background hover:bg-primary/80',
                  !it.disabled && !isMultiple && 'focus:bg-primary/80',
                  !isMultiple && isSelected(it) && 'bg-primary text-background'
                ]"
                :disabled="it.disabled"
                @click="selectItem(it, closeMenu)"
              >
                <!-- Checkbox dla multi select -->
                <Checkbox v-if="isMultiple" :checked="isSelected(it)" />
                <span class="truncate">{{ it[props.optionLabelKey] }}</span>
              </button>
            </template>
          </div>

          <div v-if="loading" class="p-2 text-center text-sm text-muted-foreground">Loading...</div>

          <div v-if="hasMore && !loading" class="p-2 text-center">
            <button class="text-sm" type="button" @click="fetchNext">Load more</button>
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
