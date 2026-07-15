<script setup lang="ts">
// WorkflowRunsSection — a thin route adapter for the Runs child route
// (`next.workflows.detail.runs`). WorkflowRunsView keeps its routing-agnostic
// `workflowId` prop API (its own lifecycle: fetch/reset + the `?run_detail=`
// drawer); this wrapper derives the id from `route.params` AND threads the open
// workflow's `trigger_type` down (guarded on `detail.id === workflowId`) so the
// Runs SOURCE filter can drop the "schedule" option for a form-submitted workflow.
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import WorkflowRunsView from './WorkflowRunsView.vue';
import { useWorkflowsStore } from '../../app/stores/workflows';

const route = useRoute();
const store = useWorkflowsStore();
const workflowId = computed(() => String(route.params.id));

// Only trust the cached detail's trigger type when it matches THIS route id.
const triggerType = computed(() =>
  store.detail && store.detail.id === workflowId.value ? store.detail.trigger_type : null,
);
</script>

<template>
  <WorkflowRunsView :workflow-id="workflowId" :trigger-type="triggerType" />
</template>
