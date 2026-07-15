<script setup lang="ts">
// FormSubmissionsView — a form's submissions list (`/forms/:id/submissions`).
//
// A sub-view of FormsModuleLayout (the open form comes from FORM_MODULE_CTX). The
// filter + list machinery — the Saved Views toolbar, the FilterBar (search /
// source / indexed / sort / date), the Active/Deleted bucket, and the cursor-
// paginated SubmissionCard list — lives in the SHARED SubmissionsBrowser (also
// reused by the workflow run-now picker so the two can't drift). This page owns
// only the surrounding chrome:
//   • the section PageHeader (+ the "New" fill action),
//   • the Active/Deleted bucket ↔ `?tab=` URL sync (passed to the browser as
//     `v-model:bucket` so the browser stays router-free),
//   • the right-side SubmissionPreviewDrawer (opened by the browser's `preview`
//     event) + the `?submission=<id>` deep-link that auto-opens it.
// All strings via i18n.
//
// NOTE: the backend exposes no submission delete/restore route, so the Deleted
// tab is view-only (it shows soft-deleted rows the API returns) — the browser's
// row actions call the store as-is.
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Button from '../../ui/primitives/Button.vue';
import SubmissionsBrowser from './SubmissionsBrowser.vue';
import SubmissionPreviewDrawer from './SubmissionPreviewDrawer.vue';
import { useFormsStore } from '../../app/stores/forms';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { FORM_MODULE_CTX } from './formContext';
import { hydrateTab, serializeTabQuery, type BucketTab } from './tabQuery';
import type { FormSubmission } from './types';

const route = useRoute();
const router = useRouter();
const store = useFormsStore();
const toast = useToast();
const { t } = useI18n();

const ctx = inject(FORM_MODULE_CTX);
const form = computed(() => ctx?.form.value ?? null);
const formId = computed(() => String(route.params.id));

// --- Bucket (Active/Deleted) ↔ `?tab=` — the browser renders the tabs via
// `v-model:bucket`; this page keeps the value in sync with the URL. --------
const bucket = ref<BucketTab>('active');

let hydrating = false;
function hydrateFromQuery(): void {
  hydrating = true;
  bucket.value = hydrateTab(route.query);
  hydrating = false;
}
function syncQuery(): void {
  if (hydrating) return;
  void router.replace({ query: serializeTabQuery(route.query, bucket.value) });
}
watch(bucket, () => syncQuery());

// --- Detail / preview drawer ----------------------------------------------
const selected = ref<FormSubmission | null>(null);
const saving = ref(false);
const drawerOpen = computed<boolean>({
  get: () => selected.value !== null,
  set: (open) => {
    if (!open) {
      selected.value = null;
      // Drop the deep-link param so the URL reflects the closed drawer (and a
      // later re-open by the same id fires the query watcher again).
      if (route.query.submission != null) {
        const { submission: _drop, ...rest } = route.query;
        void router.replace({ query: rest });
      }
    }
  },
});
function openDetail(submission: FormSubmission): void {
  selected.value = submission;
}

// --- Deep-link: `?submission=<id>` auto-opens the preview (view mode) ---------
// Honors a submission id in the URL on mount + route change: open it from the
// loaded page when present, otherwise fetch the single submission on demand.
function readSubmissionQuery(): string | null {
  const raw = route.query.submission;
  const id = Array.isArray(raw) ? raw[0] : raw;
  return typeof id === 'string' && id ? id : null;
}
async function openSubmissionById(id: string): Promise<void> {
  const inList = store.submissionsFor(formId.value).find((s) => s.id === id);
  if (inList) {
    selected.value = inList;
    return;
  }
  try {
    selected.value = await store.fetchSubmission(id);
  } catch {
    toast.danger(t('forms.submissions.error.description'));
  }
}
function syncDrawerFromQuery(): void {
  const id = readSubmissionQuery();
  if (id) {
    if (!selected.value || selected.value.id !== id) void openSubmissionById(id);
  }
}
watch(() => route.query.submission, () => syncDrawerFromQuery());
async function onSaveEdit(data: Record<string, unknown>): Promise<void> {
  if (!selected.value) return;
  saving.value = true;
  try {
    selected.value = await store.updateSubmission(selected.value.id, data);
    toast.success(t('forms.submissions.updated'));
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } };
    toast.danger(e.response?.data?.message ?? t('forms.submissions.updateError'));
  } finally {
    saving.value = false;
  }
}

function fill(): void {
  void router.push({ query: { ...route.query, fill: formId.value } });
}

onMounted(() => {
  hydrateFromQuery();
  syncDrawerFromQuery();
});
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <!-- Sub-view header (uniform scale app-wide): the h1 is the SECTION label —
         the form's identity lives in the module aside's selected block. -->
    <PageHeader
      icon="inbox"
      :title="t('forms.submissions.title')"
      :description="t('forms.submissions.subtitle')"
    >
      <template #actions>
        <Button v-if="form?.can_be_filled" leading-icon="plus" @click="fill">{{ t('forms.submissions.new') }}</Button>
      </template>
    </PageHeader>

    <SubmissionsBrowser
      v-model:bucket="bucket"
      :form-id="formId"
      :can-fill="form?.can_be_filled ?? false"
      @preview="openDetail"
      @fill="fill"
    />

    <!-- Detail / edit drawer (larger; metadata in the footer). -->
    <SubmissionPreviewDrawer
      v-model:open="drawerOpen"
      mode="view"
      :submission="selected"
      :form-content="form?.content ?? []"
      :submitting="saving"
      @submit="onSaveEdit"
    />
  </div>
</template>
