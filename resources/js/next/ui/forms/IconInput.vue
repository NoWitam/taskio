<script setup lang="ts">
// IconInput — pick an icon from OUR icon set, for the "next" frontend.
//
// The TRIGGER renders THROUGH FieldShell (shared border + state line, FormField
// context, no-grow). It shows the chosen Icon + its name, or a placeholder. The
// popover (FieldPopover) holds a searchable, scrollable GRID of every icon in
// `ICON_NAMES`, with full grid keyboard navigation.
//
// Model = an `IconName` (a member of ICON_NAMES) or `null` when cleared.
//
// Keyboard (grid): ArrowLeft/Right move within a row, ArrowUp/Down move between
// rows (same column), Home/End jump to the first/last icon of the row, Enter/
// Space select, Esc closes (handled by FieldPopover). The grid uses roving
// `tabindex` so exactly one cell is tab-focusable; `role="grid"` / `row` /
// `gridcell` with `aria-selected` on the chosen icon.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { ICON_NAMES } from '../primitives/icons';
import FieldShell from './FieldShell.vue';
import FieldPopover from './FieldPopover.vue';
import { useFormField, nextId } from './formField';
import { FIELD_PADDING_X, type ControlSize } from './fieldShell';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

type FieldPopoverExpose = {
  openPanel: () => void;
  closePanel: (returnFocus?: boolean) => void;
  toggle: () => void;
};

const props = withDefaults(
  defineProps<{
    size?: ControlSize;
    placeholder?: string;
    disabled?: boolean;
    readonly?: boolean;
    /** Show a clear (✕) affordance when there's a value. */
    clearable?: boolean;
    /** Override the icon pool (defaults to the whole ICON_NAMES set). */
    icons?: IconName[];
    /** Columns in the grid (drives keyboard up/down). */
    columns?: number;
    searchPlaceholder?: string;
    /** Standalone aria-invalid (FormField provides this otherwise). */
    ariaInvalid?: boolean;
    /** Standalone success styling (FormField provides this otherwise). */
    success?: boolean;
    /** Standalone dirty styling (FormField tracks this otherwise). */
    dirty?: boolean;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
  }>(),
  {
    size: 'md',
    disabled: false,
    readonly: false,
    clearable: true,
    columns: 8,
    success: false,
    dirty: false,
  },
);

const searchPlaceholderText = computed(
  () => props.searchPlaceholder ?? t('iconInput.searchPlaceholder', 'Search icons…'),
);

const model = defineModel<IconName | null>({ default: null });

const field = useFormField();
const generatedId = nextId('next-icon');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const panelId = computed(() => `${resolvedId.value}-panel`);
const gridId = computed(() => `${resolvedId.value}-grid`);
const resolvedDescribedBy = computed(
  () => props.describedById ?? field?.describedById.value,
);
const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
const success = computed(() => props.success || (field?.valid.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const required = computed(() => field?.required.value ?? false);
const dirty = computed(() => props.dirty || (field?.dirty.value ?? false));

if (field?.registerValue) {
  const dispose = field.registerValue(() => model.value);
  onBeforeUnmount(dispose);
}

// --- Icon pool + filtering -------------------------------------------------
const pool = computed<IconName[]>(() => props.icons ?? (ICON_NAMES as IconName[]));
const query = ref('');
const filtered = computed<IconName[]>(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return pool.value;
  return pool.value.filter((name) => name.toLowerCase().includes(q));
});

const hasValue = computed(() => model.value != null);

// Track the popover open state so we can prime the grid when it opens.
const open = ref(false);
watch(open, (isOpen) => {
  if (isOpen) onPanelOpen();
});

// --- Grid keyboard nav -----------------------------------------------------
// `activeIndex` is the roving-tabindex cell in the FLAT filtered list.
const activeIndex = ref(0);
const gridRef = ref<HTMLElement | null>(null);
const searchRef = ref<HTMLInputElement | null>(null);

function cellId(index: number): string {
  return `${gridId.value}-cell-${index}`;
}

// When the panel opens, focus search and place the active cell on the selected
// icon (or the first one). Reset when the filter changes.
function onPanelOpen(): void {
  const sel = model.value ? filtered.value.indexOf(model.value) : -1;
  activeIndex.value = sel >= 0 ? sel : 0;
  nextTick(() => searchRef.value?.focus({ preventScroll: true }));
}

watch(filtered, () => {
  activeIndex.value = 0;
});

function focusCell(index: number): void {
  nextTick(() => {
    const node = gridRef.value?.querySelector<HTMLElement>(
      `#${CSS.escape(cellId(index))}`,
    );
    node?.focus({ preventScroll: true });
    node?.scrollIntoView({ block: 'nearest' });
  });
}

function moveActive(delta: number): void {
  const len = filtered.value.length;
  if (!len) return;
  let next = activeIndex.value + delta;
  next = Math.max(0, Math.min(len - 1, next));
  activeIndex.value = next;
  focusCell(next);
}

function onGridKeydown(e: KeyboardEvent): void {
  const cols = props.columns;
  const len = filtered.value.length;
  if (!len) return;
  switch (e.key) {
    case 'ArrowRight':
      e.preventDefault();
      moveActive(1);
      break;
    case 'ArrowLeft':
      e.preventDefault();
      moveActive(-1);
      break;
    case 'ArrowDown':
      e.preventDefault();
      moveActive(cols);
      break;
    case 'ArrowUp':
      e.preventDefault();
      moveActive(-cols);
      break;
    case 'Home':
      e.preventDefault();
      // Start of the current row.
      activeIndex.value = activeIndex.value - (activeIndex.value % cols);
      focusCell(activeIndex.value);
      break;
    case 'End': {
      e.preventDefault();
      const rowStart = activeIndex.value - (activeIndex.value % cols);
      activeIndex.value = Math.min(len - 1, rowStart + cols - 1);
      focusCell(activeIndex.value);
      break;
    }
    case 'Enter':
    case ' ':
      e.preventDefault();
      choose(filtered.value[activeIndex.value]);
      break;
    default:
      break;
  }
}

// From the search box: Down/Enter dive into the grid.
function onSearchKeydown(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown' || (e.key === 'Enter' && filtered.value.length)) {
    e.preventDefault();
    activeIndex.value = Math.max(0, activeIndex.value);
    focusCell(activeIndex.value);
  }
}

const popoverRef = ref<FieldPopoverExpose | null>(null);
function choose(name: IconName | undefined): void {
  if (!name) return;
  model.value = name;
  popoverRef.value?.closePanel();
}

function clear(closePanel?: () => void): void {
  if (disabled.value || readonly.value) return;
  model.value = null;
  closePanel?.();
}

const triggerPadding = computed(() => FIELD_PADDING_X[props.size]);
</script>

<template>
  <FieldPopover
    ref="popoverRef"
    v-model:open="open"
    :disabled="disabled || readonly"
    :panel-id="panelId"
    :aria-label="t('iconInput.triggerLabel', 'Choose an icon')"
  >
    <template #trigger="{ open, toggle }">
      <FieldShell
        :size="size"
        :disabled="disabled"
        :readonly="readonly"
        :error="invalid"
        :success="success"
        :dirty="dirty"
        :focused="open || undefined"
      >
        <button
          :id="resolvedId"
          type="button"
          class="flex h-full w-full min-w-0 flex-1 items-center gap-next-2 text-left outline-none disabled:cursor-not-allowed"
          :class="triggerPadding"
          :disabled="disabled"
          aria-haspopup="dialog"
          :aria-expanded="open"
          :aria-controls="panelId"
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="resolvedDescribedBy"
          :aria-required="required ? 'true' : undefined"
          :aria-readonly="readonly ? 'true' : undefined"
          :aria-label="ariaLabel ?? (hasValue ? t('iconInput.valueLabel', 'Icon {name}', { name: model! }) : t('iconInput.emptyLabel', 'No icon selected'))"
          @click="toggle"
        >
          <span
            v-if="hasValue"
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-next-sm border border-next-border bg-next-muted text-next-base"
            aria-hidden="true"
          >
            <Icon :name="model!" />
          </span>
          <span v-if="hasValue" class="truncate font-next-medium">{{ model }}</span>
          <span v-else class="truncate text-next-muted-foreground">
            {{ placeholder ?? t('iconInput.placeholder', 'Select an icon…') }}
          </span>
        </button>

        <!-- Trailing controls, in the project-mandated order: the CONDITIONAL clear
             "X" first, then the PERMANENT open/close chevron (which never moves).
             The clear box is reserved whenever the field is editable so toggling
             it as the value comes/goes never shifts the layout — only its
             visibility flips. Mirrors Select.vue's trailing block. -->
        <template #trailing>
          <span class="flex items-center gap-next-1">
            <span
              v-if="clearable && !disabled && !readonly"
              class="flex h-5 w-5 items-center justify-center"
            >
              <button
                type="button"
                class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
                :class="hasValue ? '' : 'invisible'"
                :aria-hidden="hasValue ? undefined : 'true'"
                :aria-label="t('iconInput.clear', 'Clear icon')"
                tabindex="-1"
                @click.stop="clear()"
              >
                <Icon name="x" />
              </button>
            </span>
            <Icon
              name="chevron-down"
              class="shrink-0 text-next-muted-foreground transition-transform"
              :class="open ? 'rotate-180' : ''"
              aria-hidden="true"
            />
          </span>
        </template>
      </FieldShell>
    </template>

    <template #default="{ closePanel }">
      <div class="flex w-[20rem] flex-col">
        <!-- Search -->
        <div class="border-b border-next-border p-next-2">
          <div class="relative flex items-center">
            <Icon
              name="search"
              class="pointer-events-none absolute left-next-3 top-1/2 -translate-y-1/2 text-next-muted-foreground"
            />
            <input
              ref="searchRef"
              v-model="query"
              type="text"
              role="searchbox"
              :placeholder="searchPlaceholderText"
              :aria-label="t('iconInput.searchLabel', 'Search icons')"
              :aria-controls="gridId"
              class="h-9 w-full rounded-next-sm border border-next-input bg-next-card pl-next-8 pr-next-2 text-next-sm text-next-fg outline-none placeholder:text-next-muted-foreground focus-visible:border-next-ring focus-visible:ring-2 focus-visible:ring-next-ring/30"
              @keydown="onSearchKeydown"
            />
          </div>
        </div>

        <!-- Grid -->
        <div class="max-h-64 overflow-y-auto p-next-2">
          <div
            v-if="filtered.length"
            :id="gridId"
            ref="gridRef"
            role="grid"
            :aria-label="t('iconInput.gridLabel', 'Icons')"
            class="grid gap-next-1"
            :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }"
            @keydown="onGridKeydown"
          >
            <button
              v-for="(name, index) in filtered"
              :id="cellId(index)"
              :key="name"
              type="button"
              role="gridcell"
              :aria-selected="model === name"
              :aria-label="name"
              :title="name"
              :tabindex="index === activeIndex ? 0 : -1"
              class="flex aspect-square items-center justify-center rounded-next-sm border text-next-lg outline-none transition-colors focus-visible:ring-2 focus-visible:ring-next-ring"
              :class="[
                model === name
                  ? 'border-next-primary bg-next-primary-subtle text-next-primary-subtle-foreground'
                  : 'border-transparent text-next-fg hover:border-next-input hover:bg-next-accent hover:text-next-accent-foreground',
              ]"
              @click="choose(name)"
              @focus="activeIndex = index"
            >
              <Icon :name="name" />
            </button>
          </div>

          <!-- Empty -->
          <div
            v-else
            class="flex flex-col items-center gap-next-2 py-next-8 text-center"
          >
            <Icon name="search" class="text-next-2xl text-next-muted-foreground" />
            <p class="text-next-sm font-next-medium">{{ t('iconInput.noResults', 'No icons found') }}</p>
            <p class="text-next-xs text-next-muted-foreground">
              {{ t('iconInput.noMatch', 'Nothing matches “{query}”.', { query }) }}
            </p>
          </div>
        </div>

        <!-- Footer -->
        <div
          v-if="clearable && hasValue"
          class="flex items-center justify-between border-t border-next-border px-next-3 py-next-2"
        >
          <span class="font-next-mono text-next-2xs text-next-muted-foreground">
            {{ model }}
          </span>
          <button
            type="button"
            class="rounded-next-sm px-next-2 py-next-1 text-next-xs font-next-medium text-next-muted-foreground hover:text-next-fg"
            @click="clear(closePanel)"
          >
            {{ t('common.clear', 'Clear') }}
          </button>
        </div>
      </div>
    </template>
  </FieldPopover>
</template>
