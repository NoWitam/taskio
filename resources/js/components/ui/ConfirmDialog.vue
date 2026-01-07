    <script setup lang="ts">
    import Dialog from "@/components/ui/Dialog.vue";
    import Button from "@/components/ui/Button.vue";

    type Tone = "neutral" | "danger";

    const props = withDefaults(
        defineProps<{
            modelValue: boolean;
            title: string;
            description?: string;
            confirmLabel?: string;
            cancelLabel?: string;
            tone?: Tone;
            loading?: boolean;
        }>(),
        {
            confirmLabel: "Potwierdź",
            cancelLabel: "Anuluj",
            tone: "neutral",
            loading: false,
        }
    );

    const emit = defineEmits<{
        (e: "update:modelValue", v: boolean): void;
        (e: "confirm"): void;
        (e: "cancel"): void;
    }>();

    function close() {
        emit("update:modelValue", false);
    }

    function onCancel() {
        emit("cancel");
        close();
    }

    function onConfirm() {
        emit("confirm");
        // nie zamykam automatycznie przy loading=true – ale możesz zamknąć w handlerze po sukcesie
        setTimeout(() => {
            if (!props.loading) close();
        }, 0);

    }
</script>

<template>
  <Dialog
    :model-value="modelValue"
    :title="title"
    :description="description"
    width="sm"
    @update:modelValue="(v) => emit('update:modelValue', v)"
  >
    <template #footer>
      <Button variant="secondary" :disabled="loading" @click="onCancel">
        {{ cancelLabel }}
      </Button>

      <Button
        :variant="tone === 'danger' ? 'danger' : 'primary'"
        :loading="loading"
        @click="onConfirm"
      >
        {{ confirmLabel }}
      </Button>
    </template>
  </Dialog>
</template>
