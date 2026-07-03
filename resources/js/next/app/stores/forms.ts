// Forms store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the Forms BROWSE experience: a single cursor-paginated
// list plus the per-form lifecycle actions. The PAGE owns the filter state and
// passes it in; this store fetches, appends, tracks cursor/total/loading/error,
// and reconciles the list in place after an action so the UI updates without a
// full refetch.
//
// Backend contract (verified — do NOT invent fields):
//   GET /forms?search=&trashed=1&enabled=1&indexed=1&cursor=<cursor>
//     → { data: FormSummary[], meta: { next_cursor: string|null, total?: number|null } }
//   `total` is present ONLY on the first page (no cursor). Cursor page size is 12.
//   Lifecycle endpoints all return `{ data: FormDetail }`:
//     POST /forms/{id}/enable | /disable | /index | /unindex | /restore-index
//     POST /forms/{id}/restore · DELETE /forms/{id} · DELETE /forms/{id}/force
//
// Filter serialization mirrors the Tasks store: scalars appended as-is; booleans
// sent as `1`/`0` (so the backend `filled()`+`boolean()` checks tell true / false
// / absent apart); empty / null / '' skipped.
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/forms.ts` is reference only).
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  CompatibilityInfo,
  FormBase,
  FormDetail,
  FormDetailResponse,
  FormFilters,
  FormListResponse,
  FormReport,
  FormSubmission,
  FormSummary,
  ReportFilters,
  SubmissionFilters,
} from '../../pages/forms/types';

/** Serialize report filters: scalars as-is, arrays as `key[]`, true bools as 1. */
function serializeReportFilters(filters: ReportFilters): URLSearchParams {
  const params = new URLSearchParams();
  (Object.entries(filters) as Array<[keyof ReportFilters, unknown]>).forEach(
    ([key, value]) => {
      if (value === undefined || value === null || value === '' || value === false) return;
      if (Array.isArray(value)) {
        value.forEach((v) => params.append(`${key}[]`, String(v)));
        return;
      }
      params.append(key, value === true ? '1' : String(value));
    },
  );
  return params;
}

interface FormReportListResponse {
  data: FormReport[];
  meta?: { next_cursor: string | null };
}
interface FormReportResponse {
  data: FormReport;
}

/** Serialize submission filters: scalars as-is, arrays as `key[]`, bools as 1/0. */
function serializeSubmissionFilters(filters: SubmissionFilters): URLSearchParams {
  const params = new URLSearchParams();
  (Object.entries(filters) as Array<[keyof SubmissionFilters, unknown]>).forEach(
    ([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      if (Array.isArray(value)) {
        if (value.length === 0) return;
        value.forEach((v) => params.append(`${key}[]`, String(v)));
        return;
      }
      if (typeof value === 'boolean') {
        params.append(key, value ? '1' : '0');
        return;
      }
      params.append(key, String(value));
    },
  );
  return params;
}

/** Cursor-paginated submissions envelope (collections still wrap in `data`). */
interface FormSubmissionListResponse {
  data: FormSubmission[];
  meta?: { next_cursor: string | null };
}
// NOTE: a SINGLE FormSubmissionResource is NOT wrapped in `data`. Its `toArray`
// has its own `data` key (the answers), which collides with Laravel's default
// `data` wrapper, so the framework skips wrapping — the response body IS the
// submission. (FormResource / TaskResource have no `data` key, so they DO wrap.)

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. Skips undefined /
 * null / '' and serializes booleans as `1`/`0`. (cursor is added by the caller.)
 */
function serializeFilters(filters: FormFilters): URLSearchParams {
  const params = new URLSearchParams();

  (Object.entries(filters) as Array<[keyof FormFilters, unknown]>).forEach(
    ([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      if (typeof value === 'boolean') {
        params.append(key, value ? '1' : '0');
        return;
      }
      params.append(key, String(value));
    },
  );

  return params;
}

function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'forms.error.description';
}

export const useFormsStore = defineStore('next-forms', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<FormSummary[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const total = ref<number | null>(null);
  const loading = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);

  // Request token: a reset always supersedes work in flight so rapid filter
  // changes can never leave stale pages.
  let token = 0;

  // --- Detail (single form) ------------------------------------------------
  const detail = ref<FormDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- List helpers --------------------------------------------------------
  /** Replace a form in the list in place (after a lifecycle action). */
  function replaceInList(form: FormBase): void {
    const idx = items.value.findIndex((f) => f.id === form.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = form as FormSummary;
      items.value = next;
    }
  }

  /** Remove a form from the list + decrement the total (delete / move out). */
  function removeFromList(id: string): void {
    const before = items.value.length;
    items.value = items.value.filter((f) => f.id !== id);
    if (items.value.length !== before && total.value != null) {
      total.value = Math.max(0, total.value - 1);
    }
  }

  /** After a lifecycle action: refresh the list row + the open detail. */
  function applyUpdated(form: FormDetail): void {
    replaceInList(form);
    if (detail.value && detail.value.id === form.id) detail.value = form;
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default
   * for a filter change) the list + cursor are cleared first and `total` is
   * captured; otherwise the page is appended for infinite scroll.
   */
  async function fetchForms(
    filters: FormFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    // Appends are no-ops while a page is in flight or when exhausted.
    if (!reset && (loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    loading.value = true;
    errored.value = false;
    error.value = null;

    if (reset) {
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
    }

    try {
      const params = serializeFilters(filters);
      if (cursor.value && !reset) params.set('cursor', cursor.value);

      const qs = params.toString();
      const response = await api.get<FormListResponse>(`/forms${qs ? `?${qs}` : ''}`);
      if (myToken !== token) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];

      cursor.value = response.meta?.next_cursor ?? null;
      hasMore.value = (response.meta?.next_cursor ?? null) !== null;

      // `total` only ships on the first page; keep the captured value otherwise.
      if (
        response.meta &&
        Object.prototype.hasOwnProperty.call(response.meta, 'total') &&
        response.meta.total != null
      ) {
        total.value = response.meta.total;
      }
    } catch (err: unknown) {
      if (myToken !== token) return;
      errored.value = true;
      error.value = extractMessage(err);
      // Stop infinite scroll from hammering a failing endpoint.
      hasMore.value = false;
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /** Append the next page (infinite scroll). */
  async function loadMore(filters: FormFilters = {}): Promise<void> {
    await fetchForms(filters, { reset: false });
  }

  /** Drop all cached list state. */
  function resetAll(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    total.value = null;
    loading.value = false;
    errored.value = false;
    error.value = null;
  }

  // --- Detail --------------------------------------------------------------
  /** Fetch the FULL form (`GET /api/forms/{id}`) — includes `content`. */
  async function fetchForm(id: string): Promise<FormDetail | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<FormDetailResponse>(`/forms/${id}`);
      detail.value = res.data;
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  // --- Create / update -----------------------------------------------------
  /** The write payload — matches StoreFormRequest + FormDTO 1:1. */
  interface FormWritePayload {
    name: string;
    icon?: string | null;
    description?: string | null;
    content: unknown[];
    is_anonymous?: boolean;
  }

  /** Create a form (`POST /api/forms`). Prepends to the list when initialized. */
  async function createForm(payload: FormWritePayload): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>('/forms', payload);
    // Anonymous forms are hidden from the browse list — never inject them into it
    // (e.g. when created inline from a task while the Forms page is mounted).
    if (!payload.is_anonymous) {
      if (items.value.length > 0) items.value = [res.data as FormSummary, ...items.value];
      if (total.value != null) total.value += 1;
    }
    return res.data;
  }

  /** Update a form (`PUT /api/forms/{id}`). Reconciles the list + open detail. */
  async function updateForm(id: string, payload: FormWritePayload): Promise<FormDetail> {
    const res = await api.put<FormDetailResponse>(`/forms/${id}`, payload);
    applyUpdated(res.data);
    return res.data;
  }

  // --- Lifecycle actions (all return the fresh FormDetail) -----------------
  async function enableForm(id: string): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>(`/forms/${id}/enable`);
    applyUpdated(res.data);
    return res.data;
  }

  async function disableForm(id: string): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>(`/forms/${id}/disable`);
    applyUpdated(res.data);
    return res.data;
  }

  async function indexForm(id: string): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>(`/forms/${id}/index`);
    applyUpdated(res.data);
    return res.data;
  }

  async function unindexForm(id: string, backupIndexes = false): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>(`/forms/${id}/unindex`, {
      backup_indexes: backupIndexes,
    });
    applyUpdated(res.data);
    return res.data;
  }

  async function restoreIndex(id: string): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>(`/forms/${id}/restore-index`);
    applyUpdated(res.data);
    return res.data;
  }

  /** Move a form to trash (`DELETE /api/forms/{id}`). Drops it from the list. */
  async function deleteForm(id: string): Promise<void> {
    await api.delete(`/forms/${id}`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = null;
  }

  /** Permanently delete a trashed form (`DELETE /api/forms/{id}/force`). */
  async function forceDeleteForm(id: string): Promise<void> {
    await api.delete(`/forms/${id}/force`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = null;
  }

  /**
   * Restore a trashed form (`POST /api/forms/{id}/restore`). It leaves the trash
   * view, so it is removed from the current (trashed) list.
   */
  async function restoreForm(id: string): Promise<FormDetail> {
    const res = await api.post<FormDetailResponse>(`/forms/${id}/restore`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = res.data;
    return res.data;
  }

  /** Index compatibility info (`GET /api/forms/{id}/compatibility`). */
  async function fetchCompatibilityInfo(id: string): Promise<CompatibilityInfo> {
    return api.get<CompatibilityInfo>(`/forms/${id}/compatibility`);
  }

  // --- Submissions (per form id, cursor-paginated) -------------------------
  const submissions = ref<Record<string, FormSubmission[]>>({});
  const subCursor = ref<Record<string, string | null>>({});
  const subHasMore = ref<Record<string, boolean>>({});
  const subLoading = ref<Record<string, boolean>>({});
  const subError = ref<Record<string, string | null>>({});
  const subTokens: Record<string, number> = {};

  function submissionsFor(formId: string): FormSubmission[] {
    return submissions.value[formId] ?? [];
  }

  /**
   * Fetch a page of a form's submissions (`GET /api/forms/{id}/submissions`).
   * `{ reset: true }` (default) clears the bucket + cursor first; otherwise the
   * page is appended (infinite scroll). Token-guarded against stale responses.
   */
  async function fetchSubmissions(
    formId: string,
    filters: SubmissionFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (subLoading.value[formId] || subHasMore.value[formId] === false)) return;

    const myToken = (subTokens[formId] = (subTokens[formId] ?? 0) + 1);
    subLoading.value[formId] = true;
    subError.value[formId] = null;
    if (reset) {
      submissions.value[formId] = [];
      subCursor.value[formId] = null;
      subHasMore.value[formId] = true;
    }
    try {
      const params = serializeSubmissionFilters(filters);
      const cursorVal = subCursor.value[formId];
      if (cursorVal && !reset) params.set('cursor', cursorVal);
      const qs = params.toString();
      const res = await api.get<FormSubmissionListResponse>(
        `/forms/${formId}/submissions${qs ? `?${qs}` : ''}`,
      );
      if (myToken !== subTokens[formId]) return;

      const incoming = res.data ?? [];
      submissions.value[formId] = reset ? incoming : [...(submissions.value[formId] ?? []), ...incoming];
      subCursor.value[formId] = res.meta?.next_cursor ?? null;
      subHasMore.value[formId] = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== subTokens[formId]) return;
      subError.value[formId] = extractMessage(err);
      subHasMore.value[formId] = false;
    } finally {
      if (myToken === subTokens[formId]) subLoading.value[formId] = false;
    }
  }

  async function loadMoreSubmissions(formId: string, filters: SubmissionFilters = {}): Promise<void> {
    await fetchSubmissions(formId, filters, { reset: false });
  }

  /** Fetch a single submission (`GET /api/form-submissions/{id}` — unwrapped). */
  async function fetchSubmission(id: string): Promise<FormSubmission> {
    return api.get<FormSubmission>(`/form-submissions/${id}`);
  }

  /** Create a submission (`POST /api/form-submissions`). Prepends to the bucket. */
  async function createSubmission(payload: {
    form_id: string;
    data: Record<string, unknown>;
  }): Promise<FormSubmission> {
    const submission = await api.post<FormSubmission>('/form-submissions', payload);
    const bucket = submissions.value[payload.form_id];
    if (bucket) submissions.value[payload.form_id] = [submission, ...bucket];
    return submission;
  }

  /** Update a submission's data (`PUT /api/form-submissions/{id}` — unwrapped). */
  async function updateSubmission(
    id: string,
    data: Record<string, unknown>,
  ): Promise<FormSubmission> {
    const submission = await api.put<FormSubmission>(`/form-submissions/${id}`, { data });
    Object.keys(submissions.value).forEach((formId) => {
      const idx = (submissions.value[formId] ?? []).findIndex((s) => s.id === id);
      if (idx >= 0) {
        const next = [...submissions.value[formId]];
        next[idx] = submission;
        submissions.value[formId] = next;
      }
    });
    return submission;
  }

  /** Drop a submission id from a form's bucket (it left the current view). */
  function removeSubmission(formId: string, id: string): void {
    const bucket = submissions.value[formId];
    if (bucket) submissions.value[formId] = bucket.filter((s) => s.id !== id);
  }

  /** Soft-delete a submission (`DELETE /api/form-submissions/{id}`). */
  async function deleteSubmission(id: string, formId: string): Promise<void> {
    await api.delete(`/form-submissions/${id}`);
    removeSubmission(formId, id);
  }

  /** Restore a trashed submission (`POST /api/form-submissions/{id}/restore`). */
  async function restoreSubmission(id: string, formId: string): Promise<void> {
    await api.post(`/form-submissions/${id}/restore`);
    removeSubmission(formId, id);
  }

  /** Permanently delete a trashed submission (`DELETE /api/form-submissions/{id}/force`). */
  async function forceDeleteSubmission(id: string, formId: string): Promise<void> {
    await api.delete(`/form-submissions/${id}/force`);
    removeSubmission(formId, id);
  }

  // --- Reports (per form id, cursor-paginated) -----------------------------
  const reports = ref<Record<string, FormReport[]>>({});
  const repCursor = ref<Record<string, string | null>>({});
  const repHasMore = ref<Record<string, boolean>>({});
  const repLoading = ref<Record<string, boolean>>({});
  const repError = ref<Record<string, string | null>>({});
  const repTokens: Record<string, number> = {};

  function reportsFor(formId: string): FormReport[] {
    return reports.value[formId] ?? [];
  }

  /** Fetch a page of a form's reports (`GET /api/forms/{id}/reports`). */
  async function fetchReports(
    formId: string,
    filters: ReportFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (repLoading.value[formId] || repHasMore.value[formId] === false)) return;

    const myToken = (repTokens[formId] = (repTokens[formId] ?? 0) + 1);
    repLoading.value[formId] = true;
    repError.value[formId] = null;
    if (reset) {
      reports.value[formId] = [];
      repCursor.value[formId] = null;
      repHasMore.value[formId] = true;
    }
    try {
      const params = serializeReportFilters(filters);
      const cursorVal = repCursor.value[formId];
      if (cursorVal && !reset) params.set('cursor', cursorVal);
      const qs = params.toString();
      const res = await api.get<FormReportListResponse>(`/forms/${formId}/reports${qs ? `?${qs}` : ''}`);
      if (myToken !== repTokens[formId]) return;

      const incoming = res.data ?? [];
      reports.value[formId] = reset ? incoming : [...(reports.value[formId] ?? []), ...incoming];
      repCursor.value[formId] = res.meta?.next_cursor ?? null;
      repHasMore.value[formId] = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== repTokens[formId]) return;
      repError.value[formId] = extractMessage(err);
      repHasMore.value[formId] = false;
    } finally {
      if (myToken === repTokens[formId]) repLoading.value[formId] = false;
    }
  }

  async function loadMoreReports(formId: string, filters: ReportFilters = {}): Promise<void> {
    await fetchReports(formId, filters, { reset: false });
  }

  /** Fetch a single report (`GET /api/form-reports/{id}`). */
  async function fetchReport(id: string): Promise<FormReport> {
    const res = await api.get<FormReportResponse>(`/form-reports/${id}`);
    return res.data;
  }

  /**
   * Fetch a generated report's file as raw text (its markdown source). `url` is
   * the FileResource `path` (the absolute `disk.show` download URL); axios uses
   * it as-is and returns the body as text.
   */
  async function fetchReportFile(url: string): Promise<string> {
    return api.get<string>(url, { responseType: 'text' });
  }

  /** Create a report (`POST /api/form-reports`). Prepends to the bucket. */
  async function createReport(payload: {
    form_id: string;
    name: string;
    guidelines?: string | null;
    sources?: string[];
    submissions_from?: string | null;
    submissions_to?: string | null;
  }): Promise<FormReport> {
    const res = await api.post<FormReportResponse>('/form-reports', payload);
    const bucket = reports.value[payload.form_id];
    if (bucket) reports.value[payload.form_id] = [res.data, ...bucket];
    return res.data;
  }

  function removeReport(formId: string, id: string): void {
    const bucket = reports.value[formId];
    if (bucket) reports.value[formId] = bucket.filter((r) => r.id !== id);
  }

  /** Replace a report in the bucket in place (used by completion polling). */
  function patchReport(formId: string, report: FormReport): void {
    const bucket = reports.value[formId];
    if (!bucket) return;
    const idx = bucket.findIndex((r) => r.id === report.id);
    if (idx >= 0) {
      const next = [...bucket];
      next[idx] = report;
      reports.value[formId] = next;
    }
  }

  /** Soft-delete a report (`DELETE /api/form-reports/{id}`). */
  async function deleteReport(id: string, formId: string): Promise<void> {
    await api.delete(`/form-reports/${id}`);
    removeReport(formId, id);
  }

  /** Restore a trashed report (`POST /api/form-reports/{id}/restore`). */
  async function restoreReport(id: string, formId: string): Promise<void> {
    await api.post(`/form-reports/${id}/restore`);
    removeReport(formId, id);
  }

  /** Permanently delete a trashed report (`DELETE /api/form-reports/{id}/force`). */
  async function forceDeleteReport(id: string, formId: string): Promise<void> {
    await api.delete(`/form-reports/${id}/force`);
    removeReport(formId, id);
  }

  return {
    // list state
    items,
    cursor,
    hasMore,
    total,
    loading,
    errored,
    error,
    // detail state
    detail,
    detailLoading,
    detailError,
    // list actions
    fetchForms,
    loadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // detail
    fetchForm,
    // create / update
    createForm,
    updateForm,
    // lifecycle
    enableForm,
    disableForm,
    indexForm,
    unindexForm,
    restoreIndex,
    deleteForm,
    forceDeleteForm,
    restoreForm,
    fetchCompatibilityInfo,
    // submissions
    submissions,
    subCursor,
    subHasMore,
    subLoading,
    subError,
    submissionsFor,
    fetchSubmissions,
    loadMoreSubmissions,
    fetchSubmission,
    createSubmission,
    updateSubmission,
    deleteSubmission,
    restoreSubmission,
    forceDeleteSubmission,
    // reports
    reports,
    repCursor,
    repHasMore,
    repLoading,
    repError,
    reportsFor,
    fetchReports,
    loadMoreReports,
    fetchReport,
    fetchReportFile,
    createReport,
    deleteReport,
    restoreReport,
    forceDeleteReport,
    patchReport,
  };
});
