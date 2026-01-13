<script setup lang="ts">
    import { computed, nextTick, ref, watch, getCurrentInstance, onBeforeUnmount, useSlots } from "vue";
    import { cn } from "@/lib/helpers";
    import { useClickOutside } from "@/composables/useClickOutside";
    import { useOverlayStack } from "@/composables/useOverlayStack";

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
            align?: "start" | "end" | "auto";      // wyrównanie menu względem triggera
            width?: "sm" | "md" | "lg" | "xl" | "2xl";   // szerokość menu
            class?: string;
            matchTriggerWidth?: boolean; // jeśli true, menu przyjmie szerokość triggera
            teleport?: boolean;           // jeśli true, panel renderuje się w <body> (nie jest ucinany przez overflow)
        }>(),
        { items: () => [], align: "end", width: "md", matchTriggerWidth: false, teleport: true }
    );

    const emit = defineEmits<{
        (e: "update:open", value: boolean): void;
        (e: "opened"): void;
    }>();

    const slots = useSlots();
    const hasDefaultSlot = computed(() => !!slots.default);


    const rootRef = ref<HTMLElement | null>(null);
    const menuRef = ref<HTMLElement | null>(null);
    const itemRefs = ref<Array<HTMLButtonElement | null>>([]);

    const menuId = `dd_${Math.random().toString(16).slice(2)}`;
    const parentMenuId = ref<string | null>(null);

    const shouldTeleport = computed(() => props.teleport !== false);
    const menuStyle = ref<Record<string, string>>({});

    const uncontrolledOpen = ref(false);

    // Detect whether the parent actually passed an `open` prop (v-model:open).
    // Relying on typeof props.open === 'boolean' is unreliable because boolean props default to false.
    const _instance = getCurrentInstance();
    const isControlled = computed(() => {
        const vnodeProps = _instance?.vnode?.props as Record<string, any> | undefined;
        return !!(vnodeProps && Object.prototype.hasOwnProperty.call(vnodeProps, 'open'));
    });

    const open = computed(() => (isControlled.value ? !!props.open : uncontrolledOpen.value));

    const { zIndex, bringToFront } = useOverlayStack(open as any, 'menu');

    const enabledIndexes = computed(() =>
        (props.items ?? [])
            .map((it, idx) => ({ it, idx }))
            .filter(({ it }) => !it.disabled)
            .map(({ idx }) => idx)
    );

    const resolvedAlign = ref<"start" | "end">("end");
    const alignCls = computed(() => {
        const a = props.align === 'auto' ? resolvedAlign.value : props.align;
        return a === "end" ? "right-0" : "left-0";
    });

    function getTriggerEl() {
        const root = rootRef.value;
        if (!root) return null;
        return root.querySelector<HTMLElement>("[data-dd-trigger]") ?? root;
    }

    function computeParentMenuId() {
        const triggerEl = getTriggerEl();
        const parentMenuEl = triggerEl?.closest?.('[data-dd-menu]') as HTMLElement | null;
        const pid = parentMenuEl?.getAttribute?.('data-dd-menu-id');
        parentMenuId.value = pid || null;
    }

    function getMenuElById(id: string) {
        if (!id) return null;
        return document.querySelector<HTMLElement>(`[data-dd-menu][data-dd-menu-id="${id}"]`);
    }

    function menuHasAncestor(menuEl: HTMLElement, ancestorId: string) {
        let pid = menuEl.getAttribute('data-dd-parent-menu-id') || '';
        while (pid) {
            if (pid === ancestorId) return true;
            const pEl = getMenuElById(pid);
            if (!pEl) return false;
            pid = pEl.getAttribute('data-dd-parent-menu-id') || '';
        }
        return false;
    }

    function computeResolvedAlign(triggerRect: DOMRect, menuWidth: number) {
        if (props.align !== 'auto') {
            resolvedAlign.value = (props.align as any) ?? 'end';
            return;
        }

        const viewportW = window.innerWidth || 0;
        const spaceRight = viewportW - triggerRect.right;
        const spaceLeft = triggerRect.left;

        // Prefer start by default, but flip to end if we'd overflow on the right.
        if (menuWidth && spaceRight < menuWidth && spaceLeft > spaceRight) {
            resolvedAlign.value = 'end';
        } else {
            resolvedAlign.value = 'start';
        }
    }

    function positionMenu() {
        if (!shouldTeleport.value) return;
        const triggerEl = getTriggerEl();
        const menuEl = menuRef.value;
        if (!triggerEl || !menuEl) return;

        try {
            const triggerRect = triggerEl.getBoundingClientRect();

            // Need the menu width to decide alignment / clamp.
            const menuWidth = menuEl.offsetWidth || 0;
            computeResolvedAlign(triggerRect, menuWidth);

            const viewportW = window.innerWidth || 0;
            const viewportH = window.innerHeight || 0;
            const padding = 8;
            const gap = 8; // like mt-2

            let left = resolvedAlign.value === 'end'
                ? triggerRect.right - menuWidth
                : triggerRect.left;

            // Clamp horizontally into viewport.
            if (Number.isFinite(viewportW) && menuWidth) {
                left = Math.max(padding, Math.min(left, viewportW - padding - menuWidth));
            }

            // Default open downward.
            let top = triggerRect.bottom + gap;
            // If it would overflow heavily, try opening upward.
            const menuHeight = menuEl.offsetHeight || 0;
            if (menuHeight && top + menuHeight > viewportH - padding) {
                const upTop = triggerRect.top - gap - menuHeight;
                if (upTop >= padding) top = upTop;
            }

            menuStyle.value = {
                top: `${Math.round(top)}px`,
                left: `${Math.round(left)}px`,
            };

            // sync minWidth when requested
            if (props.matchTriggerWidth) {
                menuEl.style.minWidth = `${Math.round(triggerRect.width)}px`;
            }
        } catch (e) {
            // noop
        }
    }

    function resolveAutoAlign() {
        if (props.align !== 'auto') return;
        const triggerEl = getTriggerEl();
        const menuEl = menuRef.value;
        if (!triggerEl || !menuEl) return;

        try {
            const triggerRect = triggerEl.getBoundingClientRect();
            const menuWidth = menuEl.offsetWidth || 0;
            computeResolvedAlign(triggerRect, menuWidth);
        } catch (e) {
            resolvedAlign.value = 'end';
        }
    }

    const widthCls: Record<NonNullable<typeof props.width>, string> = {
        sm: "w-44",
        md: "w-56",
        lg: "w-72",
        xl: "w-96",
        '2xl': "w-md"
    };

    function setOpen(v: boolean) {
        if (isControlled.value) emit("update:open", v);
        else uncontrolledOpen.value = v;
    }

    function syncMenuWidth() {
        if (!props.matchTriggerWidth) return;
        if (!menuRef.value || !rootRef.value) return;
        try {
            const triggerEl = getTriggerEl();
            if (!triggerEl) return;
            const triggerRect = triggerEl.getBoundingClientRect();
            (menuRef.value as HTMLElement).style.minWidth = `${Math.round(triggerRect.width)}px`;
        } catch (e) {
            // noop
        }
    }

    function openMenu() {
        computeParentMenuId();
        bringToFront();
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
                // after mount and width sync, resolve auto align to avoid viewport overflow
                nextTick(() => {
                    resolveAutoAlign();
                    positionMenu();
                });
                if (!hasDefaultSlot.value) focusFirstEnabled();
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

    function onDialogOpened() {
        if (open.value) closeMenu();
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
        if (hasDefaultSlot.value) return;
        const idx = enabledIndexes.value[0];
        if (typeof idx === "number") focusIndex(idx);
        else menuRef.value?.focus();
    }

    function focusLastEnabled() {
        if (hasDefaultSlot.value) return;
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
            else if (!hasDefaultSlot.value) nextTick(() => focusFirstEnabled());
        }
        if (e.key === "ArrowUp") {
            e.preventDefault();
            if (!open.value) openMenu();
            else if (!hasDefaultSlot.value) nextTick(() => focusLastEnabled());
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

    useClickOutside([rootRef, menuRef], (ev) => {
        const rawTarget = ev.target as Node | null;
        const targetEl = rawTarget instanceof Element
            ? rawTarget
            : ((rawTarget as any)?.parentElement as Element | null);

        // Nested menus: allow clicks inside our own menu, or any descendant menu (teleported).
        // Example: outer DateRangeSelect should stay open when interacting with inner DateInput menu.
        const path = (typeof (ev as any).composedPath === 'function') ? ((ev as any).composedPath() as any[]) : [];
        const menuInPath = path.find((n) => n instanceof Element && (n as Element).hasAttribute?.('data-dd-menu')) as HTMLElement | undefined;
        const closestMenu = (targetEl?.closest?.('[data-dd-menu]') as HTMLElement | null) ?? null;
        const clickedMenuEl = (menuInPath || closestMenu) ?? null;

        if (clickedMenuEl) {
            const clickedId = clickedMenuEl.getAttribute('data-dd-menu-id') || '';
            if (clickedId === menuId) return;
            if (menuHasAncestor(clickedMenuEl, menuId)) return;
        }

        if (open.value) closeMenu();
    });

    // Keep menu width in sync on resize while open (only when matchTriggerWidth is true)
    function onWindowResize() {
        if (!open.value) return;
        if (props.matchTriggerWidth) syncMenuWidth();
        resolveAutoAlign();
        positionMenu();
    }

    function onWindowScroll() {
        if (!open.value) return;
        positionMenu();
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

    watch(open, (v) => {
        if (!shouldTeleport.value) return;
        if (v) {
            // capture=true catches scroll on any scrollable parent
            window.addEventListener('scroll', onWindowScroll, true);
            nextTick(positionMenu);
        } else {
            window.removeEventListener('scroll', onWindowScroll, true);
            menuStyle.value = {};
        }
    });

    // When any dialog opens, close dropdown menus globally to prevent layering issues.
    if (typeof window !== 'undefined') {
        window.addEventListener('overlay:dialog-opened', onDialogOpened as any);
    }

    onBeforeUnmount(() => {
        window.removeEventListener('scroll', onWindowScroll, true);
        window.removeEventListener('resize', onWindowResize);
        window.removeEventListener('overlay:dialog-opened', onDialogOpened as any);
    });

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
        <Teleport v-if="shouldTeleport" to="body">
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
                    data-dd-menu
                    :data-dd-menu-id="menuId"
                    :data-dd-parent-menu-id="parentMenuId || undefined"
                    @mousedown.stop
                    @click.stop
                    :style="{ ...menuStyle, zIndex }"
                    :class="cn(
                        'fixed origin-top rounded-xl border border-secondary/60 bg-background shadow-lg outline-none outline-blue-300 outline-2',
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
        </Teleport>

        <Transition
            v-else
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
                data-dd-menu
                :data-dd-menu-id="menuId"
                :data-dd-parent-menu-id="parentMenuId || undefined"
                @mousedown.stop
                @click.stop
                :style="{ zIndex }"
                :class="cn(
                    'absolute mt-2 origin-top rounded-xl border border-secondary/60 bg-background shadow-lg outline-none outline-blue-300 outline-2',
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
