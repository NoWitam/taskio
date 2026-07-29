// Templates store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the workspace-level generation TEMPLATES surface: a single
// cursor-paginated list plus the CRUD actions, and the two DRAFT-FRIENDLY editor actions
// (catalog + faithful preview). The PAGE owns the filter state and passes it in; this store
// fetches, appends, tracks cursor/loading/error, and reconciles the list IN PLACE after a write
// (ordered by NAME, mirroring the backend `orderBy('name')`) so the UI updates without a full
// refetch. Mirrors `stores/consts.ts` / `stores/functions.ts`.
//
// A template does NOT surface as a workflow variable / operation (it is consumed by a future
// `generate_content` step, sub-stage 5), so — unlike consts / functions — a template mutation
// does NOT invalidate any workflow catalog.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /generator/templates?search=&cursor=   (cursorPaginate(20), orderBy name)
//     → { data: Template[], meta: { next_cursor } }   NO `total`.
//   GET    /generator/templates/{id}   → { data: Template }
//   POST   /generator/templates        → { data: Template }     (201)
//   PUT    /generator/templates/{id}   → { data: Template }      (200)
//   DELETE /generator/templates/{id}   → { message }            (200)
//   GET    /generator/content-types → { data: ContentTypeDefinition[] }   (code-defined registry)
//   POST   /generator/catalog  { slots:[{name, descriptor}] }   → { data: { variables, operations, types } }
//   POST   /generator/preview  { content_type, content, slots, slot_values } → { data: { parts: {…} } }
//   NOTE: catalog/preview responses use the standard `{ data: … }` envelope (like every Resource);
//   the parsers read `.data` (with a flat fallback only for defensiveness) — keep the `.data` read.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ContentTypeDefinition,
  ContentTypesResponse,
  GeneratorCatalog,
  Template,
  TemplateCatalogRequest,
  TemplateFilters,
  TemplateListResponse,
  TemplatePreviewRequest,
  TemplatePreviewResult,
  TemplateResponse,
  TemplateWritePayload,
} from '../../pages/generator/types';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. The ONLY server filter is
 * `search` (name); undefined / null / '' are skipped. (cursor is added by the caller.)
 */
export function serializeFilters(filters: TemplateFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'generator.templates.errors.description';
}

/** Insert/replace a template into a name-sorted list, keeping the backend's ordering. */
function sortByName(items: Template[]): Template[] {
  return [...items].sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * The catalog response may arrive FLAT (`{variables,…}`, matching the contract) or wrapped in a
 * `data` envelope; normalise both into a fully-populated `GeneratorCatalog`.
 */
function normalizeCatalog(res: unknown): GeneratorCatalog {
  const body = res as { data?: GeneratorCatalog } & Partial<GeneratorCatalog>;
  const catalog = (body && 'variables' in body ? body : body?.data) as GeneratorCatalog | undefined;
  return {
    variables: catalog?.variables ?? [],
    operations: catalog?.operations ?? [],
    types: catalog?.types ?? [],
  };
}

export const useTemplatesStore = defineStore('next-templates', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<Template[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * Append (load-more) failure is tracked SEPARATELY from the first-page `errored`
   * so a failed page can be retried: on an append error we keep `hasMore`/`cursor`
   * intact and only set this flag, which pauses the infinite-scroll sentinel.
   */
  const loadMoreErrored = ref(false);

  // Request token: a reset always supersedes work in flight.
  let token = 0;

  // --- List helpers --------------------------------------------------------
  /** Replace a template in the list in place (after an update), keeping name order. */
  function replaceInList(template: Template): void {
    const idx = items.value.findIndex((t) => t.id === template.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = template;
      items.value = sortByName(next);
    }
  }

  /** Remove a template from the list (after a delete). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((t) => t.id !== id);
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default for a
   * filter change) the list + cursor are cleared first; otherwise the page is appended.
   */
  async function fetchTemplates(
    filters: TemplateFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
    } else {
      loadingMore.value = true;
    }
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;

    try {
      const params = serializeFilters(filters);
      if (cursor.value && !reset) params.set('cursor', cursor.value);

      const qs = params.toString();
      const response = await api.get<TemplateListResponse>(`/generator/templates${qs ? `?${qs}` : ''}`);
      if (myToken !== token) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];
      cursor.value = response.meta?.next_cursor ?? null;
      hasMore.value = (response.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      if (reset) {
        errored.value = true;
        hasMore.value = false;
      } else {
        loadMoreErrored.value = true;
      }
    } finally {
      if (myToken === token) {
        loading.value = false;
        loadingMore.value = false;
      }
    }
  }

  /** Append the next page (infinite scroll). */
  async function loadMore(filters: TemplateFilters = {}): Promise<void> {
    await fetchTemplates(filters, { reset: false });
  }

  /** Retry a failed append (clears the pause flag, re-fetches the same page). */
  async function retryLoadMore(filters: TemplateFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchTemplates(filters, { reset: false });
  }

  /** Drop all cached list state. */
  function resetAll(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;
  }

  // --- Single read ----------------------------------------------------------
  /**
   * Fetch ONE template (`GET /generator/templates/{id}`). Read-only, NOT cached and NOT
   * merged into the list — the caller owns the result.
   *
   * Its consumer is the workflow `generate_content` step editor (R2 sub-stage 5), which
   * needs the chosen template's DECLARED `slots` ({name, description?, descriptor}) to
   * render one typed row per slot, and its `content_type` for the scale/cost note. No new
   * endpoint: this is the same read the sessions store already performs for a session's
   * source template.
   */
  async function fetchTemplate(id: string): Promise<Template> {
    const res = await api.get<TemplateResponse>(`/generator/templates/${id}`);
    return res.data;
  }

  // --- Create / update / delete --------------------------------------------
  /** Create a template (`POST /generator/templates`). Inserts (name order). */
  async function createTemplate(payload: TemplateWritePayload): Promise<Template> {
    const res = await api.post<TemplateResponse>('/generator/templates', payload);
    const created = res.data;
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = sortByName([created, ...items.value]);
    }
    return created;
  }

  /** Update a template (`PUT /generator/templates/{id}`). Reconciles the list. */
  async function updateTemplate(id: string, payload: TemplateWritePayload): Promise<Template> {
    const res = await api.put<TemplateResponse>(`/generator/templates/${id}`, payload);
    const updated = res.data;
    replaceInList(updated);
    return updated;
  }

  /** Delete a template (`DELETE /generator/templates/{id}`). Drops it. */
  async function deleteTemplate(id: string): Promise<void> {
    await api.delete<{ message: string }>(`/generator/templates/${id}`);
    removeFromList(id);
  }

  // --- Content-type registry ------------------------------------------------
  /**
   * Fetch the code-defined CONTENT TYPE catalog (`GET /generator/content-types`) — the recipe SHAPES
   * (`{id,label,parts:[{key,kind,label,required,config}]}`) the editor renders its data-driven sections
   * from. Pure system data (no workspace state); the caller caches it for the editor's lifetime.
   */
  async function fetchContentTypes(): Promise<ContentTypeDefinition[]> {
    const res = await api.get<ContentTypesResponse>('/generator/content-types');
    return res.data ?? [];
  }

  // --- Draft-friendly editor actions ---------------------------------------
  /**
   * Fetch the LIVE template catalog for the current draft slots (`POST /generator/catalog`). Returns
   * the `{variables, operations, types}` shape the shared editor consumes — including `slots.<name>`
   * for every declared slot plus workspace globals + custom-function operations. Debounced by the
   * caller; no caching (the catalog is a pure function of the draft slots).
   */
  async function fetchCatalog(request: TemplateCatalogRequest): Promise<GeneratorCatalog> {
    const res = await api.post<unknown>('/generator/catalog', request);
    return normalizeCatalog(res);
  }

  /**
   * The FAITHFUL, PER-PART server-side preview (`POST /generator/preview`) — the backend runs the real
   * engine over each part of the content recipe (text → resolved markdown w/ inert `[AI: …]`; image →
   * a PLAN summary; scene → a per-scene summary) against the sample slot values. The FE never
   * re-implements interpolation. Response is `{data:{parts}}` (with a flat `{parts}` fallback guard).
   */
  async function preview(request: TemplatePreviewRequest): Promise<TemplatePreviewResult> {
    const res = await api.post<{ parts?: TemplatePreviewResult['parts']; data?: TemplatePreviewResult }>(
      '/generator/preview',
      request,
    );
    const parts = (res && 'parts' in res && res.parts ? res.parts : res?.data?.parts) ?? {};
    return { parts };
  }

  return {
    // list state
    items,
    cursor,
    hasMore,
    loading,
    loadingMore,
    errored,
    error,
    loadMoreErrored,
    // list actions
    fetchTemplates,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // single read
    fetchTemplate,
    // create / update / delete
    createTemplate,
    updateTemplate,
    deleteTemplate,
    // content-type registry
    fetchContentTypes,
    // draft-friendly editor actions
    fetchCatalog,
    preview,
  };
});
