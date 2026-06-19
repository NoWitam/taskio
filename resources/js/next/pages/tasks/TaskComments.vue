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
import Avatar from '../../ui/primitives/Avatar.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useAuthStore } from '../../app/stores/auth';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
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
  <section class="flex flex-col gap-next-4" :aria-label="t('tasks.commentsPanel.title')">
    <!-- Add comment -->
    <form class="flex flex-col gap-next-2" @submit.prevent="submit">
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
          size="sm"
          leading-icon="mail"
          :loading="posting"
          :disabled="!draft.trim()"
        >
          {{ posting ? t('tasks.commentsPanel.submitting') : t('tasks.commentsPanel.submit') }}
        </Button>
      </div>
    </form>

    <!-- Error -->
    <Alert v-if="error" variant="danger" size="sm">
      {{ t('tasks.commentsPanel.loadError') }}
    </Alert>

    <!-- Loading skeletons (mirror a comment row) -->
    <div v-else-if="initialLoading" class="flex flex-col gap-next-4" aria-hidden="true">
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
    <ul v-else class="flex flex-col gap-next-4">
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
              {{ comment.content }}
            </p>
            <div v-if="isOwn(comment.author?.id)" class="flex gap-next-2">
              <button
                type="button"
                class="rounded-next-sm text-next-xs font-next-medium text-next-muted-foreground hover:text-next-fg"
                @click="startEdit(comment.id, comment.content)"
              >
                {{ t('tasks.commentsPanel.edit') }}
              </button>
              <button
                type="button"
                class="rounded-next-sm text-next-xs font-next-medium text-next-danger hover:underline"
                @click="remove(comment.id)"
              >
                {{ t('tasks.commentsPanel.delete') }}
              </button>
            </div>
          </template>
        </div>
      </li>
    </ul>

    <!-- Load more -->
    <div v-if="!error && hasMore && comments.length > 0" class="flex justify-center">
      <Button variant="ghost" size="sm" :loading="loadingMore" @click="loadMore">
        {{ t('tasks.commentsPanel.loadMore') }}
      </Button>
    </div>
  </section>
</template>
