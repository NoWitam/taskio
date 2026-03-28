<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import type { JSONContent } from '@tiptap/core';
import Button from './ui/Button.vue';
import Icon from './ui/Icon.vue';
import Skeleton from './ui/Skeleton.vue';
import MarkdownEditor from '@/components/editors/MarkdownEditor/MarkdownEditor.vue';
import MarkdownViewer from '@/components/editors/MarkdownEditor/MarkdownViewer.vue';
import type { EditorConfig as MarkdownEditorConfig, MarkdownEditorChangeMeta } from '@/components/editors/MarkdownEditor/types/editor';
import { serializeDocument } from '@/components/editors/MarkdownEditor/utils/serialize';
import { parseMarkdown } from '@/components/editors/MarkdownEditor/utils/parse';
import { useTasksStore, type Comment } from '@/store/tasks';
import { useUsersStore } from '@/store/users';
import { useI18n } from '@/composables/useI18n';
import { useInfiniteScroll } from '@/composables/useInfiniteScroll';
import type { User } from '@/types';

const props = defineProps<{
    entityId: string;
    entityType: string;
}>();

const tasksStore = useTasksStore();
const usersStore = useUsersStore();
const { t } = useI18n();
const commentInput = ref('');
const commentDoc = ref<JSONContent>(createEmptyDoc());
const isSubmitting = ref(false);
const editingCommentId = ref<string | null>(null);
const editingInput = ref('');
const editingDoc = ref<JSONContent>(createEmptyDoc());
const mentionUsersLoaded = ref(false);

const comments = computed(() => tasksStore.commentsByTask[props.entityId] || []);
const loading = computed(() => tasksStore.loadingComments[props.entityId] || false);
const hasMore = computed(() => tasksStore.hasMoreComments[props.entityId] || false);

const mentionUsers = computed(() => {
  const catalog = usersStore.usersById || {};
  return Object.values(catalog)
    .filter((user): user is User => Boolean(user && user.id != null && user.name))
    .map((user) => ({
      id: String(user.id),
      name: user.name,
      avatar: user.avatar ?? undefined,
      email: user.email ?? undefined,
    }));
});

const markdownEditorConfig = computed<MarkdownEditorConfig>(() => ({
  features: {
    markdown: {
      headings: [1, 2, 3],
      links: true,
      lists: true,
      bold: true,
      italic: true,
      underline: true,
    },
    mentions: {
      enabled: mentionUsers.value.length > 0,
      users: mentionUsers.value,
      trigger: '@',
    },
    variables: { enabled: false, variables: [], operationsCatalog: [] },
    ifBlock: { enabled: false },
    aiText: { enabled: false, labelsEnabled: false },
  },
}));

function createEmptyDoc(): JSONContent {
  return {
    type: 'doc',
    content: [{ type: 'paragraph', content: [] }],
  };
}

function normalizeDoc(doc: JSONContent | null | undefined): JSONContent {
  if (doc && doc.type === 'doc') {
    return {
      ...doc,
      content: Array.isArray(doc.content) ? doc.content : [],
    };
  }
  return createEmptyDoc();
}

function parseDocString(raw: string | null | undefined): JSONContent | null {
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object' && parsed.type === 'doc') {
      return parsed as JSONContent;
    }
  } catch (error) {
    return null;
  }
  return null;
}

function hydrateContent(value?: string | JSONContent | null): JSONContent {
  if (!value) {
    return createEmptyDoc();
  }

  if (typeof value === 'object') {
    return normalizeDoc(value as JSONContent);
  }

  const fromJson = parseDocString(value);
  if (fromJson) {
    return normalizeDoc(fromJson);
  }

  const fromMarkdown = parseMarkdown(value) as JSONContent;
  return normalizeDoc(fromMarkdown);
}

async function ensureMentionUsersLoaded() {
  if (mentionUsersLoaded.value) return;
  if (Object.keys(usersStore.usersById || {}).length) {
    mentionUsersLoaded.value = true;
    return;
  }
  try {
    await usersStore.fetchUsers();
    mentionUsersLoaded.value = true;
  } catch (error) {
    console.error('Error loading users for mentions:', error);
  }
}

const fetchComments = async () => {
    if (props.entityType === 'task') {
        await tasksStore.fetchComments(props.entityId);
    }
};

const loadMoreIfNeeded = async () => {
    if (!hasMore.value || loading.value) return;
    if (props.entityType === 'task') {
        await tasksStore.loadMoreComments(props.entityId);
    }
};

const { triggerElement: loadMoreTrigger } = useInfiniteScroll(loadMoreIfNeeded, {
    rootMargin: '100px',
    threshold: 0.1,
});

const handleSubmit = async () => {
    const serialized = serializeDocument(commentDoc.value);
    if (!serialized || !serialized.trim()) return;
    
    isSubmitting.value = true;
    try {
        const contentToSend = JSON.stringify(commentDoc.value);
        await tasksStore.addComment(props.entityId, contentToSend);
        commentDoc.value = createEmptyDoc();
        commentInput.value = '';
    } catch (error) {
        console.error('Error adding comment:', error);
        alert('Wystąpił błąd podczas dodawania komentarza');
    } finally {
        isSubmitting.value = false;
    }
};

const startEdit = (comment: Comment) => {
    editingCommentId.value = comment.id;
    const doc = hydrateContent(comment.content);
    editingDoc.value = doc;
    editingInput.value = serializeDocument(doc) ?? '';
};

const cancelEdit = () => {
    editingCommentId.value = null;
    editingDoc.value = createEmptyDoc();
    editingInput.value = '';
};

const saveEdit = async (commentId: string) => {
    const serialized = serializeDocument(editingDoc.value);
    if (!serialized || !serialized.trim()) return;
    
    try {
        const contentToSend = JSON.stringify(editingDoc.value);
        await tasksStore.updateComment(commentId, contentToSend);
        cancelEdit();
    } catch (error) {
        console.error('Error updating comment:', error);
        alert('Wystąpił błąd podczas edycji komentarza');
    }
};

function handleCommentChange(meta: MarkdownEditorChangeMeta) {
    commentDoc.value = normalizeDoc(meta.doc);
}

function handleEditingChange(meta: MarkdownEditorChangeMeta) {
    editingDoc.value = normalizeDoc(meta.doc);
}

const handleDelete = async (commentId: string) => {
    const confirmed = confirm('Czy na pewno chcesz usunąć ten komentarz?');
    if (!confirmed) return;
    
    try {
        await tasksStore.deleteComment(commentId, props.entityId);
    } catch (error) {
        console.error('Error deleting comment:', error);
        alert('Wystąpił błąd podczas usuwania komentarza');
    }
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleString('pl-PL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

onMounted(async () => {
    await ensureMentionUsersLoaded();
    await fetchComments();
});

watch(() => props.entityId, () => {
    fetchComments();
});
</script>

<template>
  <div class="flex h-full w-80 flex-col border-l border-border bg-muted/30">
    <div class="border-b border-border p-4">
      <h3 class="flex items-center gap-2 text-sm font-semibold">
        <Icon name="message" size="sm" />
        {{ t('comments.title') }}
        <span v-if="comments.length > 0" class="ml-auto text-xs text-muted-foreground">
          ({{ comments.length }})
        </span>
      </h3>
    </div>

    <div class="flex-1 overflow-y-auto p-4">
      <!-- Empty state -->
      <div v-if="!loading && comments.length === 0" class="flex h-full items-center justify-center">
        <div class="text-center text-sm text-muted-foreground">
          <Icon name="message" size="lg" class="mx-auto mb-2 opacity-50" />
          <p>{{ t('comments.noComments') }}</p>
          <p class="text-xs">{{ t('comments.addFirstComment') }}</p>
        </div>
      </div>

      <!-- Comments list -->
      <div v-else class="space-y-4">
        <div
          v-for="comment in comments"
          :key="comment.id"
          class="rounded-lg border border-border bg-background p-3"
        >
          <div class="mb-2 flex items-start justify-between gap-2">
            <div class="flex-1">
              <p class="text-sm font-medium">{{ comment.author.name }}</p>
              <p class="text-xs text-muted-foreground">
                {{ formatDate(comment.created_at) }}
                <span v-if="comment.is_edited" class="ml-1">{{ t('comments.edited') }}</span>
              </p>
            </div>
            <div class="flex gap-1">
              <button
                type="button"
                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="startEdit(comment)"
              >
                <Icon name="edit" size="xs" />
              </button>
              <button
                type="button"
                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-danger"
                @click="handleDelete(comment.id)"
              >
                <Icon name="trash" size="xs" />
              </button>
            </div>
          </div>

          <!-- Edit mode -->
          <div v-if="editingCommentId === comment.id" class="space-y-2">
            <MarkdownEditor
              v-model="editingInput"
              :config="markdownEditorConfig"
              :placeholder="t('comments.writeComment')"
              @change="handleEditingChange"
              hide-toolbar
              class="min-h-20"
            />
            <div class="flex gap-2">
              <Button
                type="button"
                variant="primary"
                size="sm"
                @click="saveEdit(comment.id)"
              >
                {{ t('common.save') }}
              </Button>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                @click="cancelEdit"
              >
                {{ t('common.cancel') }}
              </Button>
            </div>
          </div>

          <!-- View mode -->
          <div v-else class="prose prose-sm max-w-none">
            <MarkdownViewer :value="comment.content" :config="markdownEditorConfig" />
          </div>
        </div>

        <!-- Loading skeletons for initial load and infinite scroll -->
        <template v-if="loading || hasMore">
          <div
            v-for="i in (loading && comments.length === 0 ? 3 : 2)"
            :key="`loading-skeleton-${i}`"
            :ref="i === 1 && !loading ? (el: any) => { if (el) loadMoreTrigger = el; } : undefined"
            class="rounded-lg border border-border bg-background p-3 space-y-2"
          >
            <Skeleton class="h-4 w-32" />
            <Skeleton class="h-16 w-full" />
          </div>
        </template>
      </div>
    </div>

    <div class="border-t border-border p-4">
      <div class="space-y-2">
        <MarkdownEditor
          v-model="commentInput"
          :config="markdownEditorConfig"
          :placeholder="t('comments.writeComment')"
          @change="handleCommentChange"
          :disabled="isSubmitting"
          hide-toolbar
          class="min-h-20"
        />
        <Button
          type="button"
          variant="primary"
          size="sm"
          class="w-full"
          :disabled="isSubmitting"
          @click="handleSubmit"
        >
          <Icon name="send" size="sm" />
          {{ isSubmitting ? t('comments.adding') : t('comments.addComment') }}
        </Button>
      </div>
    </div>
  </div>
</template>
