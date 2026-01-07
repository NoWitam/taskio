<script setup lang="ts">
    import { computed, ref, watch } from "vue";
    import { cn } from "@/lib/helpers";
    import { useFocusTrap } from "@/composables/useFocusTrap";
    import Button from "@/components/ui/Button.vue";

    type Side = "right" | "left";

    const props = withDefaults(
        defineProps<{
            modelValue: boolean;
            title?: string;
            description?: string;
            side?: Side;
            width?: "sm" | "md" | "lg";
            closeOnOverlay?: boolean;
            class?: string;
        }>(),
        { side: "right", width: "md", closeOnOverlay: true }
    );

    const emit = defineEmits<{
        (e: "update:modelValue", v: boolean): void;
        (e: "close"): void;
    }>();

    const panelRef = ref<HTMLElement | null>(null);
    const enabled = computed(() => props.modelValue);
    const { activate, restore } = useFocusTrap(panelRef, enabled);

    const widthCls: Record<NonNullable<typeof props.width>, string> = {
        sm: "w-[360px] max-w-[90vw]",
        md: "w-[480px] max-w-[92vw]",
        lg: "w-[640px] max-w-[94vw]",
    };

    const sideCls = computed(() => (props.side === "left" ? "left-0" : "right-0"));

    const borderSideCls = computed(() =>
        props.side === "left" ? "border-r border-secondary/60" : "border-l border-secondary/60"
    );

    const enterFrom = computed(() => (props.side === "left" ? "-translate-x-2" : "translate-x-2"));

    function close() {
        emit("update:modelValue", false);
        emit("close");
    }

    const onKeyDown = (e: KeyboardEvent) => {
        if (e.key === "Escape") close();
    };

    watch(
        () => props.modelValue,
        (open) => {
            if (open) {
            document.body.style.overflow = "hidden";
            activate();
            document.addEventListener("keydown", onKeyDown);
            } else {
            document.body.style.overflow = "";
            document.removeEventListener("keydown", onKeyDown);
            restore();
            }
        },
        { immediate: true }
    );
</script>

<template>
  <Teleport to="body">
    <!-- Overlay -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 bg-black/40"
        role="presentation"
        @mousedown.self="closeOnOverlay ? close() : undefined"
      >
        <!-- Panel -->
        <Transition
          enter-active-class="transition duration-200 ease-out"
          :enter-from-class="`opacity-0 ${enterFrom}`"
          enter-to-class="opacity-100 translate-x-0"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="opacity-100 translate-x-0"
          :leave-to-class="`opacity-0 ${enterFrom}`"
        >
          <aside
            v-if="modelValue"
            ref="panelRef"
            :class="cn(
              'fixed top-0 z-50 bg-background shadow-2xl outline-none',
              'h-dvh flex flex-col',              /* ✅ pełna wysokość + układ kolumn */
              sideCls,
              widthCls[width],
              borderSideCls,
              props.class
            )"
            role="dialog"
            aria-modal="true"
            :aria-label="title || 'Panel'"
            tabindex="-1"
          >
            <!-- Header (zawsze na górze) -->
            <header class="shrink-0 border-b border-secondary/60 p-5">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <h2 v-if="title" class="text-base font-semibold text-foreground">
                    {{ title }}
                  </h2>
                  <p v-if="description" class="mt-1 text-sm text-foreground/70">
                    {{ description }}
                  </p>
                </div>

                <Button variant="ghost" size="sm" @click="close" aria-label="Zamknij">
                  ✕
                </Button>
              </div>
            </header>

            <!-- Content (tylko to scrolluje) -->
            <div class="flex-1 overflow-y-auto p-5">
              <slot />
            </div>

            <!-- Footer (zawsze na dole) -->
            <footer v-if="$slots.footer" class="shrink-0 border-t border-secondary/60 p-5">
              <slot name="footer" />
            </footer>
          </aside>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
