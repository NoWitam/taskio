<script setup lang="ts">
    import { computed, nextTick, ref, watch, getCurrentInstance } from "vue";
    import { cn } from "@/lib/helpers";
    import { useClickOutside } from "@/composables/useClickOutside";

    type MenuItem = {
        id: string;
        label: string;
        tone?: "neutral" | "danger";
        disabled?: boolean;
    };

    const props = withDefaults(
        defineProps<{
            items?: MenuItem[];
            open?: boolean;               // v-model:open (opcjonalnie)
            align?: "start" | "end";      // wyrównanie menu względem triggera
            width?: "sm" | "md" | "lg";   // szerokość menu
            class?: string;
            matchTriggerWidth?: boolean; // jeśli true, menu przyjmie szerokość triggera
        }>(),
        { items: [], align: "end", width: "md", matchTriggerWidth: false }
    );

    const emit = defineEmits<{
        (e: "update:open", value: boolean): void;
        (e: "opened"): void;
    }>();


    const rootRef = ref<HTMLElement | null>(null);
    const menuRef = ref<HTMLElement | null>(null);
    const itemRefs = ref<Array<HTMLButtonElement | null>>([]);

    const uncontrolledOpen = ref(false);

    // Detect whether the parent actually passed an `open` prop (v-model:open).
    // Relying on typeof props.open === 'boolean' is unreliable because boolean props default to false.
    const _instance = getCurrentInstance();
    const isControlled = computed(() => {
        const vnodeProps = _instance?.vnode?.props as Record<string, any> | undefined;
        return !!(vnodeProps && Object.prototype.hasOwnProperty.call(vnodeProps, 'open'));
    });

    const open = computed(() => (isControlled.value ? !!props.open : uncontrolledOpen.value));

    const enabledIndexes = computed(() =>
        (props.items ?? [])
            .map((it, idx) => ({ it, idx }))
            .filter(({ it }) => !it.disabled)
            .map(({ idx }) => idx)
    );

    const alignCls = computed(() => (props.align === "end" ? "right-0" : "left-0"));

    const widthCls: Record<NonNullable<typeof props.width>, string> = {
        sm: "w-44",
        md: "w-56",
        lg: "w-72",
    };

    function setOpen(v: boolean) {
        if (isControlled.value) emit("update:open", v);
        else uncontrolledOpen.value = v;
    }

    function syncMenuWidth() {
        if (!props.matchTriggerWidth) return;
        if (!menuRef.value || !rootRef.value) return;
        try {
            const triggerRect = (rootRef.value as HTMLElement).getBoundingClientRect();
            (menuRef.value as HTMLElement).style.minWidth = `${Math.round(triggerRect.width)}px`;
        } catch (e) {
            // noop
        }
    }

    function openMenu() {
        setOpen(true);

        // Give parent a tick to react to update:open (controlled case). If it doesn't, fall back to internal open.
        nextTick(() => {
            if (!open.value) {
                // fallback to internal state so the menu actually mounts
                uncontrolledOpen.value = true;
            }

            // Now focus, sync width and notify parent after mount
            nextTick(() => {
                syncMenuWidth();
                focusFirstEnabled();
                nextTick(() => {
                    // notify parent that menu finished opening (mounted)
                    emit('opened');
                });
            });
        });
    }

    function closeMenu() {
        setOpen(false);
    }

    function toggle() {
        if (open.value) closeMenu();
        else openMenu();
    }

    function setItemRef(el: HTMLButtonElement | null, idx: number) {
        itemRefs.value[idx] = el;
    }

    function focusIndex(idx: number) {
        const el = itemRefs.value[idx];
        el?.focus();
    }

    function focusFirstEnabled() {
        const idx = enabledIndexes.value[0];
        if (typeof idx === "number") focusIndex(idx);
        else menuRef.value?.focus();
    }

    function focusLastEnabled() {
        const last = enabledIndexes.value[enabledIndexes.value.length - 1];
        if (typeof last === "number") focusIndex(last);
        else menuRef.value?.focus();
    }



    function nextEnabled(from: number) {
        const enabled = enabledIndexes.value;
        if (!enabled.length) return from;
        const pos = enabled.indexOf(from);
        const nextPos = pos >= 0 ? (pos + 1) % enabled.length : 0;
        return enabled[nextPos];
    }

    function prevEnabled(from: number) {
        const enabled = enabledIndexes.value;
        if (!enabled.length) return from;
        const pos = enabled.indexOf(from);
        const prevPos = pos >= 0 ? (pos - 1 + enabled.length) % enabled.length : enabled.length - 1;
        return enabled[prevPos];
    }

    function onTriggerKeydown(e: KeyboardEvent) {
        // Standardowe zachowanie menu: ArrowDown otwiera i focusuje pierwszy element
        if (e.key === "ArrowDown") {
            e.preventDefault();
            if (!open.value) openMenu();
            else nextTick(() => focusFirstEnabled());
        }
        if (e.key === "ArrowUp") {
            e.preventDefault();
            if (!open.value) openMenu();
            else nextTick(() => focusLastEnabled());
        }
    }

    function onMenuKeydown(e: KeyboardEvent) {
        if (!open.value) return;

        const activeEl = document.activeElement as HTMLElement | null;
        const currentIdx = itemRefs.value.findIndex((el) => el === activeEl);

        switch (e.key) {
            case "Escape":
                e.preventDefault();
                closeMenu();
                nextTick(() => (rootRef.value?.querySelector<HTMLElement>("[data-dd-trigger]")?.focus()));
            break;

            case "ArrowDown":
                e.preventDefault();
                if (currentIdx >= 0) focusIndex(nextEnabled(currentIdx));
                else focusFirstEnabled();
            break;

            case "ArrowUp":
                e.preventDefault();
                if (currentIdx >= 0) focusIndex(prevEnabled(currentIdx));
                else focusLastEnabled();
            break;

            case "Home":
                e.preventDefault();
                focusFirstEnabled();
            break;

            case "End":
                e.preventDefault();
                focusLastEnabled();
            break;

            case "Tab":
                // UX: zamykamy menu przy tabowaniu (jak w wielu appkach)
                closeMenu();
            break;
        }
    }

    function selectItem(item: MenuItem) {
        if (item.disabled) return;
        closeMenu();
    }

    useClickOutside(rootRef, () => {
        if (open.value) closeMenu();
    });

    // Keep menu width in sync on resize while open (only when matchTriggerWidth is true)
    function onWindowResize() {
        if (open.value && props.matchTriggerWidth) syncMenuWidth();
    }

    watch(open, (v) => {
        if (v && props.matchTriggerWidth) {
            window.addEventListener('resize', onWindowResize);
            // ensure width synced freshly
            nextTick(syncMenuWidth);
        } else {
            window.removeEventListener('resize', onWindowResize);
            // when closed, remove inline minWidth so old value doesn't persist if not desired
            if (menuRef.value && props.matchTriggerWidth) {
                (menuRef.value as HTMLElement).style.minWidth = '';
            }
        }
    });

    watch(
        () => open.value,
        (v) => {
            if (!v) itemRefs.value = [];
        }
    );

    watch(
        () => open.value,
        (v) => {
            if (!v) itemRefs.value = [];
        }
    );
</script>

<template>
  <div ref="rootRef" :class="cn('relative inline-block', props.class)">
    <!-- Trigger (activator slot) -->
    <div data-dd-trigger @keydown="onTriggerKeydown" @click="toggle" class="cursor-pointer" role="button" tabindex="0">

      <slot name="activator" :open="open" :toggle="toggle">
        <!-- fallback trigger (opcjonalny) -->
        <button
          type="button"
          class="inline-flex h-10 items-center justify-center rounded-lg border border-secondary/60 bg-background px-3 text-sm font-semibold text-foreground hover:bg-secondary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
          :aria-expanded="open"
          aria-haspopup="menu"
        >
          Menu
        </button>
      </slot>
    </div>

    <!-- Menu -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-1 scale-[0.98]"
      enter-to-class="opacity-100 translate-y-0 scale-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0 translate-y-1 scale-[0.99]"
    >
      <div
        v-if="open"
        ref="menuRef"
        :class="cn(
          'absolute z-50 mt-2 origin-top rounded-xl border border-secondary/60 bg-background shadow-lg outline-none outline-blue-300 outline-2',
          alignCls,
          widthCls[width]
        )"
        role="menu"
        tabindex="-1"
        @keydown="onMenuKeydown"
      >
        <div class="py-1">
          <template v-if="$slots.default">
            <slot :closeMenu="closeMenu" />
          </template>

          <template v-else>
            <button
              v-for="(it, idx) in items"
              :key="it.id"
              :ref="(el) => setItemRef(el as HTMLButtonElement | null, idx)"
              type="button"
              role="menuitem"
              :aria-disabled="it.disabled || undefined"
              :disabled="it.disabled"
              @click="selectItem(it)"
              :class="cn(
                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/25',
                it.disabled
                  ? 'cursor-not-allowed opacity-50'
                  : 'cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60',
                it.tone === 'danger' && !it.disabled
                  ? 'text-red-600'
                  : 'text-foreground'
              )"
            >
              <span class="truncate">{{ it.label }}</span>
            </button>
          </template>
        </div>
      </div>
    </Transition>
  </div>
</template>
