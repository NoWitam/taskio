<script setup lang="ts">
    import { computed, ref, watch } from "vue";
    import { cn } from "@/lib/helpers";
    import Button from "./Button.vue";
    import { useFocusTrap } from "@/composables/useFocusTrap"; 
  import { useOverlayStack } from "@/composables/useOverlayStack";

    const props = withDefaults(
        defineProps<{
            modelValue: boolean;
            title?: string;
            description?: string;
            closeOnOverlay?: boolean;
            width?: "sm" | "md" | "lg" | "xl" | "2xl";
            height?: string;
        }>(),
        { closeOnOverlay: true, width: "md", height: 'max-h-[90vh]' }
    );

    const emit = defineEmits<{
        (e: "update:modelValue", value: boolean): void;
        (e: "close"): void;
    }>();

    const panelRef = ref<HTMLElement | null>(null);
    const enabled = computed(() => props.modelValue);
    const { activate, restore } = useFocusTrap(panelRef, enabled);
    const { zIndex } = useOverlayStack(enabled, 'dialog');

    const close = () => {
        emit("update:modelValue", false);
        emit("close");
    };

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

    const widthCls: Record<NonNullable<typeof props.width>, string> = {
        sm: "max-w-md",
        md: "max-w-2xl",
        lg: "max-w-4xl",
        xl: "max-w-6xl",
        "2xl": "max-w-[1600px]",
    };
</script>

<template>
  <Teleport to="body">
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
        class="fixed inset-0 bg-black/40"
        :style="{ zIndex }"
        role="presentation"
        @mousedown.self="closeOnOverlay ? close() : undefined"
      >
        <div class="flex min-h-full items-center justify-center p-6">
          <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-2 scale-[0.98]"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 translate-y-1 scale-[0.99]"
          >
            <div
              ref="panelRef"
              :class="cn(
                'flex flex-col w-full rounded-2xl border border-border bg-card shadow-xl outline-none',
                widthCls[width], height
              )"
              role="dialog"
              aria-modal="true"
              :aria-label="title || 'Dialog'"
              tabindex="-1"
            >
              <div v-if="!$slots.header" class="flex items-start justify-between gap-4 border-b border-border p-6">
                <div>
                  <h2 v-if="title" class="text-lg font-semibold">{{ title }}</h2>
                  <p v-if="description" class="mt-1 text-sm text-muted-foreground">
                    {{ description }}
                  </p>
                </div>

                <Button
                  type="button"
                  variant="secondary"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg"
                  aria-label="Zamknij dialog"
                  @click="close"
                >
                  ✕
                </Button> 
              </div>

              <div v-else class="relative border-b border-border">
                <slot name="header" />
                
                <Button
                  type="button"
                  variant="secondary"
                  class="absolute top-6 right-6 inline-flex h-9 w-9 items-center justify-center rounded-lg"
                  aria-label="Zamknij dialog"
                  @click="close"
                >
                  ✕
                </Button>
              </div>

              <div class="flex-1 overflow-auto p-6">
                <slot />
              </div>

              <div v-if="$slots.footer" class="flex justify-end gap-3 border-t border-border p-6">
                <slot name="footer" />
              </div>
            </div>
          </Transition>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
