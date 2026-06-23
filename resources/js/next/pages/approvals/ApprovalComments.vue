<script setup lang="ts">
// ApprovalComments — the comments panel inside the Approvals → review drawer
// (next, Batch 3). A STANDALONE sibling of tasks/TaskComments.vue: it follows the
// SAME UX (list with skeletons / empty / error+retry, a bottom composer, inline
// edit/delete of OWN comments, an `edited` marker) but keeps its own self-
// contained state — NO Pinia store, NO coupling to the tasks store.
//
// VERIFIED backend contract (app/modules/Comments):
//   GET    {comments_url}              (cursorPaginate(8), created_at DESC)
//                                      → { data: Comment[], meta: { next_cursor }, links }
//   POST   {comments_url}   body { content }            → { data: Comment }
//   PATCH  /comments/{id}   body { content }            → { data: Comment }
//   DELETE /comments/{id}                               → { message }
//   CommentResource: { id, content, author: { id, name, email }, created_at,
//                      updated_at, is_edited }.  There are NO can_edit/can_delete
//   flags — ownership is resolved client-side against the auth user id (the same
//   approach TaskComments uses); authorization stays server-side (CommentPolicy).
//
// `comments_url` is an ABSOLUTE URL built server-side via route('comments.index').
// The `next` api singleton already prefixes `/api`, so we normalize the URL down
// to a path relative to that root, and derive the single-comment `/comments/{id}`
// path under the same root for PATCH/DELETE.
//
// All design-system components; no legacy imports; namespaced tokens; i18n + a11y.
import { computed, ref, watch } from 'vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import { api } from '../../app/lib/api';
import { useAuthStore } from '../../app/stores/auth';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import { toApiPath, singleCommentPath } from './commentsPath';

/** CommentResource shape (VERIFIED — do NOT invent fields). */
interface ApprovalComment {
  id: string | number;
  content: unknown;
  author: { id: string | number; name: string; email?: string } | null;
  created_at: string;
  updated_at: string;
  is_edited: boolean;
}

interface CommentListResponse {
  data: ApprovalComment[];
  meta?: { next_cursor?: string | null };
}
interface CommentResponse {
  data: ApprovalComment;
}

const props = defineProps<{
  /** The entity's index/store URL (`entity.comments_url`). */
  commentsUrl: string;
}>();

const { t, currentLocale } = useI18n();
const auth = useAuthStore();
const toast = useToast();
const confirm = useConfirm();

// --- URL normalization ----------------------------------------------------
// `comments_url` is an absolute URL (route() output); the api singleton prefixes
// `/api`, so we normalize it down to a relative path. See `commentsPath.ts`.
/** The list/create path (relative to the /api root). */
const listPath = computed(() => toApiPath(props.commentsUrl));

// --- List state (self-contained) ------------------------------------------
const comments = ref<ApprovalComment[]>([]);
const cursor = ref<string | null>(null);
const hasMore = ref(true);
const loading = ref(false);
const loadingMore = ref(false);
const loadError = ref(false);
let token = 0;

const initialLoading = computed(() => loading.value && comments.value.length === 0);
const isEmpty = computed(
  () => !loading.value && !loadingMore.value && !loadError.value && comments.value.length === 0,
);

async function fetchComments(reset = true): Promise<void> {
  if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

  const myToken = (token += 1);
  if (reset) {
    loading.value = true;
    comments.value = [];
    cursor.value = null;
    hasMore.value = true;
  } else {
    loadingMore.value = true;
  }
  loadError.value = false;

  try {
    const sep = listPath.value.includes('?') ? '&' : '?';
    const url = cursor.value && !reset ? `${listPath.value}${sep}cursor=${encodeURIComponent(cursor.value)}` : listPath.value;
    const res = await api.get<CommentListResponse>(url);
    if (myToken !== token) return;

    const incoming = res.data ?? [];
    comments.value = reset ? incoming : [...comments.value, ...incoming];
    cursor.value = res.meta?.next_cursor ?? null;
    hasMore.value = (res.meta?.next_cursor ?? null) !== null;
  } catch {
    if (myToken !== token) return;
    loadError.value = true;
    // Stop the sentinel from hammering a failing endpoint; the retry button
    // re-runs a full fetch.
    hasMore.value = false;
  } finally {
    if (myToken === token) {
      loading.value = false;
      loadingMore.value = false;
    }
  }
}

// Refetch whenever the target entity changes (the drawer is keyed by process,
// but the URL is the stable identity of the comment thread).
watch(
  () => props.commentsUrl,
  () => void fetchComments(true),
  { immediate: true },
);

function retry(): void {
  void fetchComments(true);
}

// --- Infinite scroll ------------------------------------------------------
const scrollRef = ref<HTMLElement | null>(null);
const { sentinelRef } = useInfiniteScroll({
  root: scrollRef,
  onLoadMore: () => void fetchComments(false),
  canLoadMore: () =>
    hasMore.value && !loadingMore.value && !loading.value && !loadError.value,
});

// --- Ownership + rendering helpers ----------------------------------------
function isOwn(authorId: string | number | undefined): boolean {
  return auth.user != null && authorId != null && String(auth.user.id) === String(authorId);
}

function formatDateTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(currentLocale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(d);
}

interface RichNode {
  type?: string;
  text?: string;
  content?: RichNode[];
}
function extractText(node: RichNode): string {
  if (typeof node.text === 'string') return node.text;
  if (!Array.isArray(node.content)) return '';
  const parts = node.content.map(extractText);
  return node.type === 'paragraph' ? parts.join('') : parts.join('\n');
}
/** Render readable text whether `content` is plain text or a Tiptap document. */
function displayContent(raw: unknown): string {
  if (raw == null) return '';
  if (typeof raw === 'object') {
    const node = raw as RichNode;
    return node.type === 'doc' ? extractText(node).trim() : String(raw);
  }
  if (typeof raw !== 'string') return String(raw);
  const trimmed = raw.trim();
  if (!trimmed.startsWith('{')) return raw;
  try {
    const parsed = JSON.parse(trimmed) as RichNode;
    if (parsed?.type === 'doc') return extractText(parsed).trim();
  } catch {
    return raw;
  }
  return raw;
}

// --- Add (POST → prepend; DESC order) -------------------------------------
const draft = ref('');
const posting = ref(false);

async function submit(): Promise<void> {
  const content = draft.value.trim();
  if (!content || posting.value) return;
  posting.value = true;
  try {
    const res = await api.post<CommentResponse>(listPath.value, { content });
    comments.value = [res.data, ...comments.value];
    draft.value = '';
    toast.success(t('approvals.comments.toasts.added'));
  } catch {
    toast.danger(t('approvals.comments.toasts.error'));
  } finally {
    posting.value = false;
  }
}

// --- Inline edit (PATCH) --------------------------------------------------
const editingId = ref<string | number | null>(null);
const editDraft = ref('');
const savingEdit = ref(false);

function startEdit(id: string | number, content: string): void {
  editingId.value = id;
  editDraft.value = content;
}
function cancelEdit(): void {
  editingId.value = null;
  editDraft.value = '';
}
async function saveEdit(id: string | number): Promise<void> {
  const content = editDraft.value.trim();
  if (!content || savingEdit.value) return;
  savingEdit.value = true;
  try {
    const res = await api.patch<CommentResponse>(singleCommentPath(id), { content });
    const idx = comments.value.findIndex((c) => String(c.id) === String(id));
    if (idx >= 0) {
      const next = [...comments.value];
      next[idx] = res.data;
      comments.value = next;
    }
    toast.success(t('approvals.comments.toasts.updated'));
    cancelEdit();
  } catch {
    toast.danger(t('approvals.comments.toasts.error'));
  } finally {
    savingEdit.value = false;
  }
}

// --- Delete (DELETE) ------------------------------------------------------
async function remove(id: string | number): Promise<void> {
  const ok = await confirm({
    title: t('approvals.comments.deleteTitle'),
    message: t('approvals.comments.deleteConfirm'),
    confirmLabel: t('approvals.comments.delete'),
    cancelLabel: t('approvals.comments.cancel'),
    variant: 'danger',
    onConfirm: async () => {
      await api.delete(singleCommentPath(id));
      comments.value = comments.value.filter((c) => String(c.id) !== String(id));
    },
  });
  if (ok) toast.success(t('approvals.comments.toasts.deleted'));
}
</script>

<template>
  <section
    class="flex h-full min-h-0 flex-col gap-next-4"
    :aria-label="t('approvals.comments.title')"
  >
    <!-- Load error (full-panel) with retry. -->
    <Alert v-if="loadError && comments.length === 0" variant="danger" size="sm" class="shrink-0">
      <div class="flex items-center justify-between gap-next-2">
        <span>{{ t('approvals.comments.loadError') }}</span>
        <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="retry">
          {{ t('approvals.comments.retry') }}
        </Button>
      </div>
    </Alert>

    <!-- Scrollable list region: the ONLY part that scrolls in the panel. -->
    <div ref="scrollRef" class="min-h-0 flex-1 overflow-y-auto">
      <!-- Loading skeletons (mirror a comment row). -->
      <div v-if="initialLoading" class="flex flex-col gap-next-4" aria-hidden="true">
        <div v-for="n in 3" :key="n" class="flex gap-next-3">
          <Skeleton variant="circle" diameter="2rem" />
          <div class="flex min-w-0 flex-1 flex-col gap-next-2">
            <Skeleton variant="text" width="30%" />
            <Skeleton variant="text" width="85%" />
          </div>
        </div>
      </div>

      <!-- Empty. -->
      <EmptyState
        v-else-if="isEmpty"
        size="sm"
        icon="mail"
        :title="t('approvals.comments.empty')"
        :description="t('approvals.comments.emptyDescription')"
      />

      <!-- List. -->
      <template v-else>
        <ul class="flex flex-col gap-next-4">
          <li v-for="comment in comments" :key="comment.id" class="flex gap-next-3">
            <Avatar :name="comment.author?.name" size="sm" class="shrink-0" />
            <div class="flex min-w-0 flex-1 flex-col gap-next-1">
              <div class="flex flex-wrap items-baseline gap-next-2">
                <span class="text-next-sm font-next-semibold text-next-fg">
                  {{ comment.author?.name }}
                </span>
                <time :datetime="comment.created_at" class="text-next-xs text-next-muted-foreground">
                  {{ formatDateTime(comment.created_at) }}
                </time>
                <span v-if="comment.is_edited" class="text-next-xs italic text-next-muted-foreground">
                  ({{ t('approvals.comments.edited') }})
                </span>
              </div>

              <!-- Inline edit. -->
              <template v-if="editingId === comment.id">
                <Textarea
                  v-model="editDraft"
                  :rows="2"
                  auto-grow
                  :aria-label="t('approvals.comments.editAria')"
                />
                <div class="flex gap-next-2">
                  <Button
                    size="sm"
                    :loading="savingEdit"
                    :disabled="!editDraft.trim()"
                    @click="saveEdit(comment.id)"
                  >
                    {{ t('approvals.comments.save') }}
                  </Button>
                  <Button size="sm" variant="ghost" :disabled="savingEdit" @click="cancelEdit">
                    {{ t('approvals.comments.cancel') }}
                  </Button>
                </div>
              </template>

              <!-- Display. -->
              <template v-else>
                <p class="whitespace-pre-wrap break-words text-next-sm text-next-fg">
                  {{ displayContent(comment.content) }}
                </p>
                <div v-if="isOwn(comment.author?.id)" class="flex gap-next-1">
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    :aria-label="t('approvals.comments.edit')"
                    @click="startEdit(comment.id, displayContent(comment.content))"
                  >
                    <Icon name="pencil" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    :aria-label="t('approvals.comments.delete')"
                    @click="remove(comment.id)"
                  >
                    <Icon name="trash" />
                  </Button>
                </div>
              </template>
            </div>
          </li>
        </ul>

        <!-- Infinite-scroll sentinel: skeleton rows while the next page loads. -->
        <div v-if="hasMore" ref="sentinelRef" class="mt-next-4 flex flex-col gap-next-4" aria-hidden="true">
          <div v-for="n in 2" :key="n" class="flex gap-next-3">
            <Skeleton variant="circle" diameter="2rem" />
            <div class="flex min-w-0 flex-1 flex-col gap-next-2">
              <Skeleton variant="text" width="30%" />
              <Skeleton variant="text" width="85%" />
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Composer (pinned at the BOTTOM; never scrolls). -->
    <form
      class="flex shrink-0 flex-col gap-next-2 border-t border-next-border pt-next-4"
      @submit.prevent="submit"
    >
      <Textarea
        v-model="draft"
        :rows="3"
        :maxlength="5000"
        :placeholder="t('approvals.comments.placeholder')"
        :aria-label="t('approvals.comments.add')"
        auto-grow
      />
      <div class="flex justify-end">
        <Button type="submit" leading-icon="mail" :loading="posting" :disabled="!draft.trim()">
          {{ posting ? t('approvals.comments.submitting') : t('approvals.comments.submit') }}
        </Button>
      </div>
    </form>
  </section>
</template>
