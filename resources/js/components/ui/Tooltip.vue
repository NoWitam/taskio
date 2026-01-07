<script setup lang="ts">
    import { computed, mergeProps, ref, useSlots, cloneVNode, h } from "vue";
    import type { VNode } from "vue";
    import { cn } from "@/lib/helpers";

    type Side = "top" | "right" | "bottom" | "left";

    const props = withDefaults(
        defineProps<{
            side?: Side;
            disabled?: boolean;
            class?: string;        // wrapper
            contentClass?: string; // tooltip wrapper
        }>(),
        { side: "top", disabled: false }
    );

    const slots = useSlots();
    const open = ref(false);
    const id = `tt_${Math.random().toString(16).slice(2)}`;

    const hasContent = computed(() => !!slots.content?.().length);
    const canShow = computed(() => hasContent.value && !props.disabled);

    function show() {
        if (!canShow.value) return;
        open.value = true;
    }
    function hide() {
        open.value = false;
    }
    function onKeydown(e: KeyboardEvent) {
        if (e.key === "Escape") hide();
    }

    const posCls = computed(() => {
        switch (props.side) {
            case "bottom":
            return "top-full left-1/2 -translate-x-1/2 translate-y-2";
            case "left":
            return "right-full top-1/2 -translate-y-1/2 -translate-x-2";
            case "right":
            return "left-full top-1/2 -translate-y-1/2 translate-x-2";
            case "top":
            default:
            return "bottom-full left-1/2 -translate-x-1/2 -translate-y-2";
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
        :class="cn('pointer-events-none absolute z-50', posCls, props.contentClass)"
      >
        <span class="relative block max-w-xs rounded-lg bg-foreground px-3 py-2 text-xs font-medium text-background shadow-lg">
          <slot name="content" />

          <!-- arrow -->
          <span
            :class="cn('absolute h-2 w-2 rotate-45 bg-foreground', arrowCls)"
            aria-hidden="true"
          />
        </span>
      </span>
    </Transition>
  </span>
</template>
