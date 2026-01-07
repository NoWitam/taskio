<script setup lang="ts">
    import { computed, ref } from "vue";
    import { cn } from "@/lib/helpers";
    import Button from "@/components/ui/Button.vue";

    const props = withDefaults(
        defineProps<{
            accept?: string;        // np. "image/*,video/*"
            multiple?: boolean;
            disabled?: boolean;
            class?: string;
        }>(),
        { multiple: true, disabled: false }
    );

    const emit = defineEmits<{
        (e: "files", files: File[]): void;
    }>();

    const inputRef = ref<HTMLInputElement | null>(null);
    const dragging = ref(false);

    const help = computed(() =>
        props.multiple ? "Upuść pliki tutaj lub wybierz z dysku" : "Upuść plik tutaj lub wybierz z dysku"
    );

    function openPicker() {
        if (props.disabled) return;
        inputRef.value?.click();
    }

    function handleFiles(fileList: FileList | null) {
        if (!fileList || props.disabled) return;
        emit("files", Array.from(fileList));
    }

    function onDrop(e: DragEvent) {
        e.preventDefault();
        dragging.value = false;
        handleFiles(e.dataTransfer?.files ?? null);
    }

    function onDragOver(e: DragEvent) {
        e.preventDefault();
        if (props.disabled) return;
        dragging.value = true;
    }

    function onDragLeave(e: DragEvent) {
        e.preventDefault();
        dragging.value = false;
    }
</script>

<template>
  <div
    :class="cn(
      'rounded-2xl border border-dashed p-6 transition',
      'bg-background',
      dragging ? 'border-primary/50 bg-secondary/30' : 'border-secondary/60',
      disabled && 'opacity-60 cursor-not-allowed',
      props.class
    )"
    role="button"
    tabindex="0"
    @click="openPicker"
    @keydown.enter.prevent="openPicker"
    @keydown.space.prevent="openPicker"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="onDrop"
  >
    <input
      ref="inputRef"
      class="hidden"
      type="file"
      :accept="accept"
      :multiple="multiple"
      :disabled="disabled"
      @change="handleFiles(($event.target as HTMLInputElement).files)"
    />

    <div class="text-center">
      <div class="text-sm font-semibold text-foreground">Upload</div>
      <div class="mt-1 text-sm text-foreground/70">{{ help }}</div>

      <div class="mt-4 flex justify-center">
        <Button size="sm" variant="secondary" :disabled="disabled">
          Wybierz pliki
        </Button>
      </div>
    </div>
  </div>
</template>
