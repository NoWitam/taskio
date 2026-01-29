<script setup lang="ts">
import { ref } from 'vue';
import Button from './ui/Button.vue';
import Icon from './ui/Icon.vue';

const props = defineProps<{
    entityId?: string;
    entityType?: string;
}>();

const commentText = ref('');

const handleSubmit = () => {
    // Placeholder - w przyszłości obsługa dodawania komentarza
    console.log('Add comment:', commentText.value);
    commentText.value = '';
};
</script>

<template>
  <div class="flex h-full w-80 flex-col border-l border-border bg-muted/30">
    <div class="border-b border-border p-4">
      <h3 class="flex items-center gap-2 text-sm font-semibold">
        <Icon name="message" size="sm" />
        Komentarze
      </h3>
    </div>

    <div class="flex-1 overflow-y-auto p-4">
      <div class="flex h-full items-center justify-center">
        <div class="text-center text-sm text-muted-foreground">
          <Icon name="message" size="lg" class="mx-auto mb-2 opacity-50" />
          <p>Funkcja komentarzy</p>
          <p>w przygotowaniu</p>
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
          disabled
        />
        <Button
          type="button"
          variant="primary"
          size="sm"
          class="w-full"
          :disabled="!commentText.trim()"
          @click="handleSubmit"
        >
          <Icon name="send" size="sm" />
          Dodaj komentarz
        </Button>
      </div>
    </div>
  </div>
</template>
