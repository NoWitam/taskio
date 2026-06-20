<script setup lang="ts">
// TaskComments — the comments section inside the task detail Drawer (next).
//
// Reads cursor-paginated comments from the store (DESC order). Lets the user add
// a comment (Textarea + submit), and edit/delete THEIR OWN comments (ownership is
// resolved against the auth user id). `is_edited` is shown. Covers loading
// (skeletons mirroring a comment row), empty, error, and success states.
//
// All design-system components; no legacy imports; namespaced tokens; i18n + a11y.
import { computed, ref } from 'vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useAuthStore } from '../../app/stores/auth';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';

const props = defineProps<{
  taskId: string | number;
}>();

const { t, currentLocale } = useI18n();
const store = useTasksStore();
const auth = useAuthStore();
const toast = useToast();
const confirm = useConfirm();

const draft = ref('');
const posting = ref(false);
const loadingMore = ref(false);

// Per-comment inline edit state.
const editingId = ref<string | number | null>(null);
const editDraft = ref('');
const savingEdit = ref(false);

const comments = computed(() => store.comments);
const initialLoading = computed(() => store.commentsLoading && comments.value.length === 0);
const hasMore = computed(() => store.commentsHasMore);
const error = computed(() => store.commentsError);

function isOwn(authorId: string | number): boolean {
  return auth.user != null && String(auth.user.id) === String(authorId);
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

/**
 * Comments may be stored either as plain text or as a serialized Tiptap
 * document (legacy editor). Render readable text in both cases.
 */
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
    if (parsed?.type === 'doc') {
      return extractText(parsed).trim();
    }
  } catch {
    return raw;
  }
  return raw;
}

async function submit(): Promise<void> {
  const content = draft.value.trim();
  if (!content || posting.value) return;
  posting.value = true;
  try {
    await store.addComment(props.taskId, content);
    draft.value = '';
    toast.success(t('tasks.toasts.commentAdded'));
  } catch {
    toast.danger(t('tasks.toasts.commentError'));
  } finally {
    posting.value = false;
  }
}

async function loadMore(): Promise<void> {
  if (loadingMore.value) return;
  loadingMore.value = true;
  try {
    await store.loadMoreComments(props.taskId);
  } finally {
    loadingMore.value = false;
  }
}

// Auto-load the next page when the skeleton sentinel scrolls into view (the
// shared infinite-scroll pattern used across the app). The scroll container is
// the list region below the fixed composer.
const scrollRef = ref<HTMLElement | null>(null);
const { sentinelRef } = useInfiniteScroll({
  root: scrollRef,
  onLoadMore: () => void loadMore(),
  canLoadMore: () =>
    hasMore.value && !loadingMore.value && !initialLoading.value && !error.value,
});

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
    await store.updateComment(id, content);
    toast.success(t('tasks.toasts.commentUpdated'));
    cancelEdit();
  } catch {
    toast.danger(t('tasks.toasts.commentError'));
  } finally {
    savingEdit.value = false;
  }
}

async function remove(id: string | number): Promise<void> {
  const ok = await confirm({
    title: t('tasks.commentsPanel.deleteTitle'),
    message: t('tasks.commentsPanel.deleteConfirm'),
    confirmLabel: t('tasks.commentsPanel.delete'),
    cancelLabel: t('tasks.commentsPanel.cancel'),
    variant: 'danger',
    onConfirm: async () => {
      await store.deleteComment(id);
    },
  });
  if (ok) toast.success(t('tasks.toasts.commentDeleted'));
}
</script>

<template>
  <section class="flex h-full min-h-0 flex-col gap-next-4" :aria-label="t('tasks.commentsPanel.title')">
    <!-- Error -->
    <Alert v-if="error" variant="danger" size="sm" class="shrink-0">
      {{ t('tasks.commentsPanel.loadError') }}
    </Alert>

    <!-- Scrollable list region: the ONLY part that scrolls in the panel. -->
    <div ref="scrollRef" class="min-h-0 flex-1 overflow-y-auto">
      <!-- Loading skeletons (mirror a comment row) -->
      <div v-if="initialLoading" class="flex flex-col gap-next-4" aria-hidden="true">
        <div v-for="n in 3" :key="n" class="flex gap-next-3">
          <Skeleton variant="circle" diameter="2rem" />
          <div class="flex min-w-0 flex-1 flex-col gap-next-2">
            <Skeleton variant="text" width="30%" />
            <Skeleton variant="text" width="85%" />
          </div>
        </div>
      </div>

      <!-- Empty -->
      <EmptyState
        v-else-if="comments.length === 0"
        size="sm"
        icon="mail"
        :title="t('tasks.commentsPanel.empty')"
        :description="t('tasks.commentsPanel.emptyDescription')"
      />

      <!-- List -->
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
                  ({{ t('tasks.commentsPanel.edited') }})
                </span>
              </div>

              <!-- Inline edit -->
              <template v-if="editingId === comment.id">
                <Textarea
                  v-model="editDraft"
                  :rows="2"
                  auto-grow
                  :aria-label="t('tasks.commentsPanel.edit')"
                />
                <div class="flex gap-next-2">
                  <Button size="sm" :loading="savingEdit" :disabled="!editDraft.trim()" @click="saveEdit(comment.id)">
                    {{ t('tasks.commentsPanel.save') }}
                  </Button>
                  <Button size="sm" variant="ghost" :disabled="savingEdit" @click="cancelEdit">
                    {{ t('tasks.commentsPanel.cancel') }}
                  </Button>
                </div>
              </template>

              <!-- Display -->
              <template v-else>
                <p class="whitespace-pre-wrap break-words text-next-sm text-next-fg">
                  {{ displayContent(comment.content) }}
                </p>
                <div v-if="isOwn(comment.author?.id)" class="flex gap-next-1">
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    :aria-label="t('tasks.commentsPanel.edit')"
                    @click="startEdit(comment.id, displayContent(comment.content))"
                  >
                    <Icon name="pencil" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon-xs"
                    :aria-label="t('tasks.commentsPanel.delete')"
                    @click="remove(comment.id)"
                  >
                    <Icon name="trash" />
                  </Button>
                </div>
              </template>
            </div>
          </li>
        </ul>

        <!-- Infinite-scroll sentinel: skeleton rows shown while the next page
             loads; the IntersectionObserver triggers loadMore as it nears view. -->
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

    <!-- Add comment (pinned at the BOTTOM, below the thread; never scrolls). -->
    <form class="flex shrink-0 flex-col gap-next-2 border-t border-next-border pt-next-4" @submit.prevent="submit">
      <Textarea
        v-model="draft"
        :rows="3"
        :placeholder="t('tasks.commentsPanel.placeholder')"
        :aria-label="t('tasks.commentsPanel.add')"
        auto-grow
      />
      <div class="flex justify-end">
        <Button
          type="submit"
          leading-icon="mail"
          :loading="posting"
          :disabled="!draft.trim()"
        >
          {{ posting ? t('tasks.commentsPanel.submitting') : t('tasks.commentsPanel.submit') }}
        </Button>
      </div>
    </form>
  </section>
</template>
