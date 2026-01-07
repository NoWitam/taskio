<script setup lang="ts">
  import { computed } from "vue";
  import Badge from "./Badge.vue";

  type Status = "pending" | "processing" | "success" | "error" | "warning" | "info";

  const props = withDefaults(
    defineProps<{
      status: Status;
      label?: string;
      class?: string;
    }>(),
    {}
  );

  const statusConfig: Record<Status, { tone: any; defaultLabel: string }> = {
    pending: { tone: "neutral", defaultLabel: "Pending" },
    processing: { tone: "primary", defaultLabel: "Processing" },
    success: { tone: "success", defaultLabel: "Success" },
    error: { tone: "danger", defaultLabel: "Error" },
    warning: { tone: "warning", defaultLabel: "Warning" },
    info: { tone: "primary", defaultLabel: "Info" },
  };

  const config = computed(() => statusConfig[props.status]);
  const displayLabel = computed(() => props.label || config.value.defaultLabel);
</script>

<template>
  <Badge :tone="config.tone" :class="class">{{ displayLabel }}</Badge>
</template>
