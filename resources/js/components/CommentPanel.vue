<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import Button from './ui/Button.vue';
import Icon from './ui/Icon.vue';
import Skeleton from './ui/Skeleton.vue';
import { useTasksStore, type Comment } from '@/store/tasks';

const props = defineProps<{
    entityId: string;
    entityType: string;
}>();

const tasksStore = useTasksStore();
const commentText = ref('');
const isSubmitting = ref(false);
const editingCommentId = ref<string | null>(null);
const editingText = ref('');

const comments = computed(() => tasksStore.commentsByTask[props.entityId] || []);
const loading = computed(() => tasksStore.loadingComments[props.entityId] || false);

const fetchComments = async () => {
    if (props.entityType === 'task') {
        await tasksStore.fetchComments(props.entityId);
    }
};

const handleSubmit = async () => {
    if (!commentText.value.trim()) return;
    
    isSubmitting.value = true;
    try {
        await tasksStore.addComment(props.entityId, commentText.value.trim());
        commentText.value = '';
    } catch (error) {
        console.error('Error adding comment:', error);
        alert('Wystąpił błąd podczas dodawania komentarza');
    } finally {
        isSubmitting.value = false;
    }
};

const startEdit = (comment: Comment) => {
    editingCommentId.value = comment.id;
    editingText.value = comment.content;
};

const cancelEdit = () => {
    editingCommentId.value = null;
    editingText.value = '';
};

const saveEdit = async (commentId: string) => {
    if (!editingText.value.trim()) return;
    
    try {
        await tasksStore.updateComment(commentId, editingText.value.trim());
        cancelEdit();
    } catch (error) {
        console.error('Error updating comment:', error);
        alert('Wystąpił błąd podczas edycji komentarza');
    }
};

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

onMounted(() => {
    fetchComments();
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
        Komentarze
        <span v-if="comments.length > 0" class="ml-auto text-xs text-muted-foreground">
          ({{ comments.length }})
        </span>
      </h3>
    </div>

    <div class="flex-1 overflow-y-auto p-4">
      <!-- Loading state -->
      <div v-if="loading" class="space-y-4">
        <div v-for="i in 3" :key="i" class="space-y-2">
          <Skeleton class="h-4 w-32" />
          <Skeleton class="h-16 w-full" />
        </div>
      </div>

      <!-- Empty state -->
      <div v-else-if="comments.length === 0" class="flex h-full items-center justify-center">
        <div class="text-center text-sm text-muted-foreground">
          <Icon name="message" size="lg" class="mx-auto mb-2 opacity-50" />
          <p>Brak komentarzy</p>
          <p class="text-xs">Dodaj pierwszy komentarz</p>
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
                <span v-if="comment.is_edited" class="ml-1">(edytowany)</span>
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
            <textarea
              v-model="editingText"
              class="w-full resize-none rounded border border-border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
              rows="3"
            />
            <div class="flex gap-2">
              <Button
                type="button"
                variant="primary"
                size="xs"
                @click="saveEdit(comment.id)"
              >
                Zapisz
              </Button>
              <Button
                type="button"
                variant="secondary"
                size="xs"
                @click="cancelEdit"
              >
                Anuluj
              </Button>
            </div>
          </div>

          <!-- View mode -->
          <p v-else class="whitespace-pre-wrap text-sm">{{ comment.content }}</p>
        </div>
      </div>
    </div>

    <div class="border-t border-border p-4">
      <div class="space-y-2">
        <textarea
          v-model="commentText"
          placeholder="Dodaj komentarz..."
          class="w-full resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary"
          rows="3"
          :disabled="isSubmitting"
        />
        <Button
          type="button"
          variant="primary"
          size="sm"
          class="w-full"
          :disabled="!commentText.trim() || isSubmitting"
          @click="handleSubmit"
        >
          <Icon name="send" size="sm" />
          {{ isSubmitting ? 'Dodawanie...' : 'Dodaj komentarz' }}
        </Button>
      </div>
    </div>
  </div>
</template>
