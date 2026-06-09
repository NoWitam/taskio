<script setup lang="ts">
    import { computed, nextTick, onMounted, ref, watch } from "vue";
    import { cn } from "@/lib/helpers";
    import Icon from "@/components/ui/Icon.vue";

    type TabItem = {
        id: string;
        label: string;
        badge?: number;
        icon?: string;
        disabled?: boolean;
    };

    const props = defineProps<{
        modelValue: string;
        tabs: TabItem[];
        class?: string;
    }>();

    const emit = defineEmits<{
        (e: "update:modelValue", value: string): void;
    }>();

    // Stabilny "base id" na potrzeby aria-controls / aria-labelledby
    const baseId = `tabs_${Math.random().toString(16).slice(2)}`;

    const activeIndex = computed(() => {
        const idx = props.tabs.findIndex((t) => t.id === props.modelValue);
        return idx >= 0 ? idx : 0;
    });

    const activeId = computed(() => props.tabs[activeIndex.value]?.id);

    const tabId = (id: string) => `${baseId}_tab_${id}`;
    const panelId = (id: string) => `${baseId}_panel_${id}`;

    // Refs do przycisków tabów (do nawigacji klawiaturą)
    const tabRefs = ref<Array<HTMLButtonElement | null>>([]);

    function setTabRef(el: HTMLButtonElement | null, idx: number) {
        tabRefs.value[idx] = el;
    }

    function focusTab(idx: number) {
        const el = tabRefs.value[idx];
        if (!el) return;
        el.focus();
    }

    function selectTab(idx: number, focus = true) {
        const t = props.tabs[idx];
        if (!t || t.disabled) return;
        emit("update:modelValue", t.id);

        if (focus) {
            // po update warto dać tick, żeby roving tabindex był już poprawny
            nextTick(() => focusTab(idx));
        }
    }

    function findNextEnabled(from: number, direction: 1 | -1): number {
        const len = props.tabs.length;
        for (let i = 1; i <= len; i++) {
            const idx = (from + i * direction + len) % len;
            if (!props.tabs[idx].disabled) return idx;
        }
        return from;
    }

    function onKeyDown(e: KeyboardEvent) {
        if (props.tabs.length === 0) return;

        const current = activeIndex.value;
        let next = current;

        switch (e.key) {
            case "ArrowRight":
            e.preventDefault();
            next = findNextEnabled(current, 1);
            selectTab(next);
            break;
            case "ArrowLeft":
            e.preventDefault();
            next = findNextEnabled(current, -1);
            selectTab(next);
            break;
            case "Home":
            e.preventDefault();
            next = props.tabs.findIndex((t) => !t.disabled);
            if (next >= 0) selectTab(next);
            break;
            case "End":
            e.preventDefault();
            for (let i = props.tabs.length - 1; i >= 0; i--) {
                if (!props.tabs[i].disabled) { next = i; break; }
            }
            selectTab(next);
            break;
        }
    }

    // Jeśli modelValue nie pasuje do tabs, ustaw na pierwszy (bez pytania)
    function ensureValidValue() {
        if (props.tabs.length === 0) return;
        const exists = props.tabs.some((t) => t.id === props.modelValue);
        if (!exists) emit("update:modelValue", props.tabs[0].id);
    }

    onMounted(ensureValidValue);

    watch(
        () => props.tabs,
        () => ensureValidValue(),
        { deep: true }
    );

    watch(
        () => props.modelValue,
        () => ensureValidValue()
    );
</script>

<template>
  <div :class="cn('w-full', props.class)">
    <!-- Tab list -->
    <div
      class="inline-flex max-w-full items-center gap-1 rounded-xl border border-border bg-secondary p-1"
      role="tablist"
      aria-orientation="horizontal"
      @keydown="onKeyDown"
    >
      <button
        v-for="(t, idx) in tabs"
        :key="t.id"
        :ref="(el) => setTabRef(el as HTMLButtonElement | null, idx)"
        type="button"
        role="tab"
        :id="tabId(t.id)"
        :aria-controls="panelId(t.id)"
        :aria-selected="t.id === activeId"
        :aria-disabled="t.disabled || undefined"
        :tabindex="t.id === activeId ? 0 : -1"
        :disabled="t.disabled"
        @click="selectTab(idx, false)"
        :class="cn(
          'relative inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30',
          t.disabled
            ? 'text-muted-foreground/40 cursor-not-allowed'
            : 'cursor-pointer',
          !t.disabled && t.id === activeId
            ? 'bg-primary text-background shadow-sm'
            : '',
          !t.disabled && t.id !== activeId
            ? 'text-foreground/70 hover:text-background hover:bg-primary/60'
            : ''
        )"
      >
        <Icon v-if="t.icon" :name="t.icon" size="sm" />
        <span class="whitespace-nowrap">{{ t.label }}</span>

        <span
          v-if="typeof t.badge === 'number'"
          :class="cn(
            'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-bold',
            t.id === activeId ? 'bg-background text-foreground/70' : 'bg-background text-foreground/70 border border-border'
          )"
          aria-label="Liczba elementów"
        >
          {{ t.badge }}
        </span>
      </button>
    </div>

    <div>
      <slot name="before" />
    </div>
    <!-- Panel -->
    <div
      v-if="$slots.panel && activeId && !$slots['panel-'+activeId]"
      class="mt-4"
      role="tabpanel"
      :id="panelId(activeId)"
      :aria-labelledby="tabId(activeId)"
      tabindex="0"
    >
      <slot name="panel" :activeId="activeId" :activeIndex="activeIndex" />
    </div>
    <div
      v-else-if="$slots['panel-'+activeId]"
      class="mt-4"
      role="tabpanel"
      :id="panelId(activeId)"
      :aria-labelledby="tabId(activeId)"
      tabindex="0"
    >
      <slot :name="'panel-'+activeId" />
    </div>
  </div>
</template>
