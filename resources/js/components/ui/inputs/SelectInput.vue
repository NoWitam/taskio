<script setup lang="ts">
import { ref, computed, watch, nextTick } from "vue";
import { cn } from "@/lib/helpers";
import DropdownMenu from "@/components/ui/DropdownMenu.vue";

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
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu :class="class" align="start" :matchTriggerWidth="true" @opened="handleOpened">
      <template #activator="{ open, toggle }">
        <div
          :id="inputId"
          @click.stop="toggle()"
          class="min-h-[44px] w-full flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm text-foreground"
        >
          <div class="flex flex-1 flex-wrap gap-2 items-center">
            <template v-if="$slots.selected">
              <template v-for="(it, idx) in selectedItems" :key="idx">
                <slot name="selected" :item="it" :remove="() => removeValue(it[props.optionValueKey] ?? it.value)" />
              </template>
              <span v-if="!selectedItems.length && placeholder" class="text-muted-foreground">{{ placeholder }}</span>
            </template>

            <template v-else>
              <template v-if="selectedItems.length">
                <template v-for="it in selectedItems" :key="it[props.optionValueKey]">
                  <span
                    class="inline-flex items-center gap-2 rounded-full bg-secondary/40 px-2 py-0.5 text-xs font-medium"
                  >
                    <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                    <button class="text-muted-foreground" type="button" @click.stop="removeValue(it[props.optionValueKey])">✕</button>
                  </span>
                </template>
              </template>
              <span v-else class="text-muted-foreground">{{ placeholder }}</span>
            </template>
          </div>

          <div class="ml-2 text-muted-foreground">{{ open ? "▲" : "▼" }}</div>
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="p-2">
          <slot name="panel-top" :query="query" :setQuery="setQuery" :loading="loading" :loadMore="fetchNext" :hasMore="hasMore" />

          <div class="max-h-60 overflow-auto" @scroll="onScroll">
            <template v-if="$slots.item">
              <template v-for="(it, idx) in filteredItems" :key="it[props.optionValueKey]">
                <slot name="item" :item="it" :index="idx" :select="() => selectItem(it, closeMenu)" :isSelected="isSelected(it)" />
              </template>
            </template>

            <template v-else>
              <button
                v-for="(it, idx) in filteredItems"
                :key="it[props.optionValueKey]"
                type="button"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition"
                :class="it.disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60'"
                :disabled="it.disabled"
                @click="selectItem(it, closeMenu)"
              >
                <span class="truncate">{{ it[props.optionLabelKey] }}</span>
                <span v-if="isSelected(it)" class="text-primary">✔</span>
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
