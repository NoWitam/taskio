<script setup lang="ts">
    import { computed, mergeProps, ref, useSlots, cloneVNode, h, onMounted, onBeforeUnmount, nextTick } from "vue";
    import type { VNode } from "vue";
    import { cn } from "@/lib/helpers";

    type Side = "top" | "right" | "bottom" | "left";

    const props = withDefaults(
        defineProps<{
            side?: Side;
            disabled?: boolean;
            class?: string;        // wrapper
            contentClass?: string; // tooltip wrapper
            arrowClass?: string;
        }>(),
        { side: "top", disabled: false }
    );

    const slots = useSlots();
    const open = ref(false);
    const id = `tt_${Math.random().toString(16).slice(2)}`;
    const triggerRef = ref<HTMLElement | null>(null);
    const tooltipStyle = ref<Record<string, string>>({});

    const hasContent = computed(() => !!slots.content?.().length);
    const canShow = computed(() => hasContent.value && !props.disabled);

    function updatePosition() {
        if (!triggerRef.value || !open.value) return;
        
        const rect = triggerRef.value.getBoundingClientRect();
        const offset = 8;
        
        let top = 0;
        let left = 0;
        
        switch (props.side) {
            case "bottom":
                top = rect.bottom + offset;
                left = rect.left + rect.width / 2;
                break;
            case "left":
                top = rect.top + rect.height / 2;
                left = rect.left - offset;
                break;
            case "right":
                top = rect.top + rect.height / 2;
                left = rect.right + offset;
                break;
            case "top":
            default:
                top = rect.top - offset;
                left = rect.left + rect.width / 2;
                break;
        }
        
        tooltipStyle.value = {
            top: `${top}px`,
            left: `${left}px`,
        };
    }

    function show() {
        if (!canShow.value) return;
        open.value = true;
        nextTick(updatePosition);
    }
    function hide() {
        open.value = false;
    }
    function onKeydown(e: KeyboardEvent) {
        if (e.key === "Escape") hide();
    }

    onMounted(() => {
        window.addEventListener("scroll", updatePosition, true);
        window.addEventListener("resize", updatePosition);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("scroll", updatePosition, true);
        window.removeEventListener("resize", updatePosition);
    });

    const posCls = computed(() => {
        switch (props.side) {
            case "bottom":
            return "-translate-x-1/2";
            case "left":
            return "-translate-y-1/2 -translate-x-full";
            case "right":
            return "-translate-y-1/2";
            case "top":
            default:
            return "-translate-x-1/2 -translate-y-full";
        }
    });

    const arrowCls = computed(() => {
        switch (props.side) {
            case "bottom":
            return "-top-1 left-1/2 -translate-x-1/2";
            case "left":
            return "-right-1 top-1/2 -translate-y-1/2";
            case "right":
            return "-left-1 top-1/2 -translate-y-1/2";
            case "top":
            default:
            return "-bottom-1 left-1/2 -translate-x-1/2";
        }
    });

    // Wstrzykujemy eventy + aria-describedby do triggera (jeśli jest single root)
    const triggerProps = computed(() => ({
        ref: triggerRef,
        onMouseenter: show,
        onMouseleave: hide,
        onFocus: show,
        onBlur: hide,
        onKeydown,
        "aria-describedby": open.value ? id : undefined,
    }));

    const Trigger = () => {
        const vnodes = slots.default?.() ?? [];
        if (vnodes.length === 1) {
            const node = vnodes[0] as VNode;
            return cloneVNode(node, mergeProps(node.props ?? {}, triggerProps.value));
        }
        // fallback: gdy ktoś poda kilka elementów w slocie
        return h("span", mergeProps({ class: "inline-flex" }, triggerProps.value), vnodes);
    };
</script>

<template>
  <span :class="cn('relative inline-flex', props.class)">
    <Trigger />

    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 scale-[0.98]"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-[0.99]"
      >
        <span
          v-if="open"
          :id="id"
          role="tooltip"
          :style="tooltipStyle"
          :class="cn('pointer-events-none fixed z-50', posCls)"
        >
          <span :class="cn('relative block rounded-lg bg-foreground/80 px-3 py-2 text-xs font-medium text-background shadow-lg max-w-xs', props.contentClass)">
            <slot name="content" />
            <!-- arrow -->
            <span
              :class="cn('absolute h-2 w-2 rotate-45 bg-foreground/80', arrowCls, props.arrowClass)"
              aria-hidden="true"
            />
          </span>
        </span>
      </Transition>
    </Teleport>
  </span>
</template>
