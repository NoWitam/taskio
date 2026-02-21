<script setup lang="ts">
import { ref, computed, watch, nextTick, useSlots, onMounted, onBeforeUnmount } from "vue";
import { cn } from "@/lib/helpers";
import DropdownMenu from "@/components/ui/DropdownMenu.vue";
import Icon from "@/components/ui/Icon.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Badge from "@/components/ui/Badge.vue";
import Tooltip from "@/components/ui/Tooltip.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import Button from "../Button.vue";

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
    clearable?: boolean;
    /**
     * Resolver dla zaznaczonych wartości (np. po deep-linku), gdy nie ma ich w internalItems.
     * Powinien zwrócić obiekt kompatybilny z Option (musi mieć przynajmniej optionLabelKey/optionValueKey).
     */
    resolveSelected?: (value: any) => Option | null | undefined;
    optionLabelKey?: string;
    optionValueKey?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;
    fetchPageSize?: number;
  }>(),
  { multiple: false, clearable: false, optionLabelKey: "label", optionValueKey: "value", fetchPageSize: 20 }
);

const emit = defineEmits<{ (e: "update:modelValue", value: any): void }>();

const inputId = props.id ?? `sel_${Math.random().toString(16).slice(2)}`;
const slots = useSlots();
const hasLeft = computed(() => !!slots.left);

const internalItems = ref<Option[]>([]);
const nextUrl = ref<string | null>(typeof props.options === "string" ? (props.options as string) : null);
const loading = ref(false);
const query = ref("");
const hasFetched = ref(false);
const menuOpen = ref(false);

const disabled = computed(() => !!props.disabled);

const isRemote = computed(() => typeof props.options === "string" || !!props.loader);
const hasMore = computed(() => !!nextUrl.value);

// Initialize items for array-based options
watch(
  () => props.options,
  (v) => {
    if (Array.isArray(v)) {
      internalItems.value = v.slice();
      nextUrl.value = null;
      hasFetched.value = true;
    } else if (typeof v === "string") {
      internalItems.value = [];
      nextUrl.value = v;
      hasFetched.value = false;
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
    if (isRemote.value) hasFetched.value = true;
  }
}

// search handler for both local and remote
function setQuery(q: string) {
  query.value = q;
  if (isRemote.value) {
    hasFetched.value = false;
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

function clearSelection(closeMenu?: () => void) {
  if (props.disabled) return;
  selectedValues.value = isMultiple.value ? [] : null;
  if (closeMenu) closeMenu();
}

const selectedItems = computed(() => {
  const mapVal = (v: any): Option => {
    const found = internalItems.value.find((it) => valueOf(it) === v);
    if (found) return found;

    const resolved = props.resolveSelected ? props.resolveSelected(v) : null;
    if (resolved) return resolved;

    const label = String(v);
    return {
      label,
      value: v,
      [props.optionLabelKey]: label,
      [props.optionValueKey]: v,
    } as Option;
  };

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
  if (isRemote.value && !internalItems.value.length && !loading.value) {
    fetchNext();
  }
}

async function refreshItems() {
  // Only refresh live when the menu is currently open.
  if (!menuOpen.value) return;
  if (!isRemote.value) return;

  internalItems.value = [];
  hasFetched.value = false;
  nextUrl.value = typeof props.options === "string" ? (props.options as string) : null;
  await fetchNext();
}

defineExpose({ refreshItems, menuOpen });

/**
 * -----------------------------
 * Overflow badges (+N) handling
 * -----------------------------
 */

const badgesViewportRef = ref<HTMLElement | null>(null);

// trzymamy refy do "czegokolwiek" (HTMLElement albo instancja komponentu)
const measureBadgeRefs = ref<any[]>([]);
const moreMeasureRef = ref<any | null>(null);

const visibleCount = ref<number>(Number.POSITIVE_INFINITY);
const moreText = ref("+0");

function toEl(x: any): HTMLElement | null {
  if (!x) return null;
  // gdy ref jest na natywny element
  if (x instanceof HTMLElement) return x;
  // gdy ref jest na komponent (Badge/Tooltip etc.)
  if (x?.$el instanceof HTMLElement) return x.$el;
  // czasem bywa { value: ... } w zależności od wrapperów
  if (x?.value instanceof HTMLElement) return x.value;
  if (x?.value?.$el instanceof HTMLElement) return x.value.$el;
  return null;
}

function setMeasureBadgeRef(el: any | null, idx: number) {
  if (!el) return;
  measureBadgeRefs.value[idx] = el;
}

function getGapPx(el: HTMLElement) {
  const cs = getComputedStyle(el);
  const g = parseFloat((cs.columnGap || cs.gap || "0") as string);
  return Number.isFinite(g) && g > 0 ? g : 8;
}

async function recalcVisibleBadges() {
  // tylko multi
  if (!isMultiple.value) return;

  await nextTick();

  const viewport = badgesViewportRef.value;
  if (!viewport) return;

  const wrapW = viewport.clientWidth;
  if (!wrapW) return;

  // gap bierzemy z najbliższego flexa z gap-2 (u Ciebie viewport ma gap-2)
  const gap = getGapPx(viewport);

  // szerokości wszystkich badge (mierzone na ukrytej linii)
  const widths = selectedItems.value.map((_, i) => toEl(measureBadgeRefs.value[i])?.offsetWidth ?? 0);

  const totalWidth = (n: number) => {
    if (n <= 0) return 0;
    let sum = 0;
    for (let i = 0; i < n; i++) sum += widths[i] ?? 0;
    return sum + gap * (n - 1);
  };

  let vis = widths.length;

  // wszystkie bez "+N"
  if (totalWidth(vis) <= wrapW) {
    visibleCount.value = vis;
    moreText.value = "+0";
    return;
  }

  while (vis >= 0) {
    const hidden = widths.length - vis;

    if (hidden > 0) {
      moreText.value = `+${hidden}`;
      await nextTick();
    }

    const moreW = hidden > 0 ? (toEl(moreMeasureRef.value)?.offsetWidth ?? 0) : 0;
    const needed = totalWidth(vis) + (hidden > 0 ? gap + moreW : 0);

    if (needed <= wrapW) {
      visibleCount.value = vis;
      return;
    }

    vis--;
  }

  // nic nie wchodzi, tylko +N
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

const showEmptyState = computed(() => {
  if (loading.value) return false;
  if (isRemote.value) return hasFetched.value && filteredItems.value.length === 0;
  return filteredItems.value.length === 0;
});

const emptyIcon = computed(() => (query.value ? "search" : "circle-help"));
const emptyTitle = computed(() => (query.value ? "Brak wyników" : "Brak opcji"));
const emptyDescription = computed(() =>
  query.value
    ? "Nie znaleziono opcji pasujących do wyszukiwania."
    : "Na ten moment nie ma nic do wybrania."
);

// Recalc przy zmianie selekcji / labelKey / valueKey
watch(
  () => [selectedItems.value, isMultiple.value, props.optionLabelKey, props.optionValueKey],
  async () => {
    // reset refs (bo kolejność/ilość badge mogła się zmienić)
    measureBadgeRefs.value = [];
    visibleCount.value = selectedItems.value.length;
    await recalcVisibleBadges();
  },
  { deep: true, immediate: true }
);

let ro: ResizeObserver | null = null;

onMounted(async () => {
  await nextTick();
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

    <DropdownMenu v-model:open="menuOpen" :class="class" align="start" :matchTriggerWidth="true" @opened="handleOpened">
      <template #activator="{ open, toggle }">
        <div class="relative flex">
          <!-- Hidden measuring row (off-screen) -->
          <div
            class="absolute -left-2499.75 top-0 h-0 overflow-hidden whitespace-nowrap pointer-events-none opacity-0"
            aria-hidden="true"
          >
            <div class="flex gap-2 items-center">
              <template v-for="(it, idx) in selectedItems" :key="it[props.optionValueKey] ?? idx">
                <template v-if="$slots.selected">
                  <div :ref="(el: any) => setMeasureBadgeRef(el, idx)" class="inline-flex">
                    <slot name="selected" :item="it" :remove="() => {}" :measuring="true" />
                  </div>
                </template>

                <template v-else>
                  <Badge
                    :ref="(el: any) => setMeasureBadgeRef(el, idx)"
                    class="bg-secondary/40 border-transparent font-medium gap-2"
                  >
                    <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                    <span class="text-muted-foreground">✕</span>
                  </Badge>
                </template>
              </template>

              <Badge
                :ref="(el: any) => (moreMeasureRef = el)"
                class="bg-secondary/40 border-transparent font-medium"
              >
                {{ moreText }}
              </Badge>
            </div>
          </div>

          <div v-if="hasLeft" class="absolute inset-y-0 left-0 flex items-center justify-center w-10 pointer-events-none">
            <slot name="left" />
          </div>

          <div
            :id="inputId"
            @click.stop="toggle()"
            :class="cn(
              'min-h-11 w-full font-semibold flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm text-foreground cursor-pointer',
              hasLeft && 'pl-10',
              'border-border hover:border-border/80',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
              disabled && 'opacity-60 cursor-not-allowed',
              error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger'
            )"
          >
            <div ref="badgesViewportRef" class="flex flex-1 min-w-0 gap-2 items-center overflow-hidden">
              <template v-if="$slots.selected">
                <template v-if="selectedItems.length">
                  <template v-if="isMultiple">
                    <div class="flex flex-nowrap gap-2 items-center min-w-0 overflow-hidden">
                      <template v-for="(it, idx) in visibleSelectedItems" :key="it[props.optionValueKey] ?? idx">
                        <slot
                          name="selected"
                          :item="it"
                          :remove="() => removeValue(it[props.optionValueKey] ?? it.value)"
                          :measuring="false"
                        />
                      </template>

                      <Tooltip v-if="hiddenCount" side="top">
                        <Badge class="bg-secondary/40 border-transparent font-medium">
                          {{ moreText }}
                        </Badge>

                        <template #content>
                          <span class="whitespace-pre-wrap">{{ hiddenTooltip }}</span>
                        </template>
                      </Tooltip>
                    </div>
                  </template>

                  <template v-else>
                    <slot
                      name="selected"
                      :item="selectedItems[0]"
                      :remove="() => removeValue(selectedItems[0]?.[props.optionValueKey] ?? selectedItems[0]?.value)"
                      :measuring="false"
                    />
                  </template>
                </template>

                <span v-else-if="placeholder" class="text-muted-foreground">{{ placeholder }}</span>
              </template>

              <template v-else>
                <template v-if="selectedItems.length">
                  <!-- Multi select: badges -->
                  <template v-if="isMultiple">
                    <div class="flex flex-nowrap gap-2 items-center min-w-0 overflow-hidden">
                      <template v-for="it in visibleSelectedItems" :key="it[props.optionValueKey]">
                        <Badge class="bg-secondary/40 border-transparent font-medium gap-2">
                          <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                          <button
                            class="text-muted-foreground"
                            type="button"
                            @click.stop="removeValue(it[props.optionValueKey])"
                          >
                            ✕
                          </button>
                        </Badge>
                      </template>

                      <!-- +N badge -->
                      <Tooltip v-if="hiddenCount" side="top">
                        <Badge class="bg-secondary/40 border-transparent font-medium">
                          {{ moreText }}
                        </Badge>

                        <template #content>
                          <span class="whitespace-pre-wrap">{{ hiddenTooltip }}</span>
                        </template>
                      </Tooltip>
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
        <div>
          <slot name="panel-top" :query="query" :setQuery="setQuery" :loading="loading" :loadMore="fetchNext" :hasMore="hasMore" />

          <div class="flex flex-col p-2 gap-2 max-h-60 overflow-auto" @scroll="onScroll">
            <template v-if="showEmptyState">
              <slot
                name="empty"
                :query="query"
                :isRemote="isRemote"
                :title="emptyTitle"
                :description="emptyDescription"
                :icon="emptyIcon"
              >
                <div class="py-8 px-3 text-center">
                  <div class="mx-auto mb-2 h-10 w-10 rounded-full bg-secondary flex items-center justify-center text-foreground/60">
                    <Icon :name="emptyIcon" :size="18" />
                  </div>
                  <div class="text-sm font-semibold text-foreground/80">{{ emptyTitle }}</div>
                  <div class="mt-1 text-xs text-muted-foreground">{{ emptyDescription }}</div>
                </div>
              </slot>
            </template>

            <template v-else>
              <template v-if="$slots.item">
                <template v-for="(it, idx) in filteredItems" :key="it[props.optionValueKey]">
                  <button
                    type="button"
                    class="flex w-full items-center gap-3 px-3 py-2 font-semibold text-left text-sm transition"
                    :class="[
                      it.disabled
                        ? 'cursor-not-allowed opacity-50'
                        : 'cursor-pointer rounded-sm hover:text-background hover:bg-primary/80',
                      !it.disabled && !isMultiple && 'focus:bg-primary/80',
                      !isMultiple && isSelected(it) && 'bg-primary text-background'
                    ]"
                    :disabled="it.disabled"
                    @click="selectItem(it, closeMenu)"
                  >
                    <Checkbox v-if="isMultiple" :checked="isSelected(it)" />
                    <slot
                      name="item"
                      :item="it"
                      :index="idx"
                      :select="() => selectItem(it, closeMenu)"
                      :isSelected="isSelected(it)"
                    />
                  </button>
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
                  <Checkbox v-if="isMultiple" :checked="isSelected(it)" />
                  <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                </button>
              </template>

              <template v-if="loading && isRemote">
                <div v-for="i in 3" :key="`sk_${i}`" class="flex items-center gap-3 px-3 py-2">
                  <Skeleton v-if="isMultiple" width="16px" height="16px" rounded="sm" class="shrink-0" />
                  <Skeleton class="flex-1" height="16px" rounded="full" />
                </div>
              </template>
            </template>
          </div>

          <div 
            v-if="clearable"
            class="border-t border-border p-2 flex w-full justify-center"
          >
            <Button
              variant="ghost"
              class="w-full text-muted-foreground"
              :disabled="disabled"
              type="button"
              @click="clearSelection(closeMenu)"
            >
              Wyczyść
            </Button>
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
