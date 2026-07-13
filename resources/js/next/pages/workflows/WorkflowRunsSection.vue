<script setup lang="ts">
// WorkflowRunsSection — a thin route adapter for the Runs child route
// (`next.workflows.detail.runs`). WorkflowRunsView keeps its routing-agnostic
// `workflowId` prop API (its own lifecycle: fetch/reset + the `?run_detail=`
// drawer); this wrapper only derives the id from `route.params` so the router
// has a component to point at.
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import WorkflowRunsView from './WorkflowRunsView.vue';

const route = useRoute();
const workflowId = computed(() => String(route.params.id));
</script>

<template>
  <WorkflowRunsView :workflow-id="workflowId" />
</template>
