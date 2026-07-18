<script setup lang="ts">
// DiskThumbnail — a file tile's preview: a real thumbnail where it is cheap, else the type glyph.
//
// The serve endpoint needs the workspace/auth headers only the api client sends, so a thumbnail
// cannot be a bare <img src> — it is blob-fetched into an object URL. To avoid downloading every
// file in a grid, the fetch is DEFERRED until the tile nears the viewport (IntersectionObserver)
// and runs only for the types we can preview cheaply:
//   • image → the image itself (object-cover),
//   • text  → the first lines of its content (a snippet).
// Everything else (video, document, audio, archive …) shows the type glyph; a real preview there
// needs server-side rendering (a video frame, a PDF page), which is out of scope.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import { api } from '../../app/lib/api';
import { fileTypeIcon, isImageFile, isTextFile } from './fileIcon';
import type { DiskFile } from './types';

const props = defineProps<{ file: DiskFile }>();

const rootRef = ref<HTMLElement | null>(null);
const imageUrl = ref<string | null>(null);
const textSnippet = ref<string | null>(null);

const isImage = (): boolean => isImageFile(props.file.type, props.file.mime_type);
const isText = (): boolean => isTextFile(props.file.type, props.file.mime_type);
const previewable = (): boolean => isImage() || isText();

let observer: IntersectionObserver | null = null;
let started = false;

function inlineUrl(path: string): string {
  return path + (path.includes('?') ? '&' : '?') + 'inline=1';
}

async function load(): Promise<void> {
  if (started) return;
  started = true;
  try {
    const blob = await api.get<Blob>(inlineUrl(props.file.path), { responseType: 'blob' });
    if (isImage()) {
      imageUrl.value = URL.createObjectURL(blob);
    } else {
      textSnippet.value = (await blob.text()).slice(0, 400);
    }
  } catch {
    // Leave both null → the glyph shows. A missing/oversized preview is not worth a toast.
  }
}

onMounted(() => {
  if (!previewable()) return;
  // No IntersectionObserver (older env / test) → just load; otherwise defer until near-viewport.
  if (typeof IntersectionObserver === 'undefined') {
    void load();
    return;
  }
  observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((e) => e.isIntersecting)) {
        observer?.disconnect();
        observer = null;
        void load();
      }
    },
    { rootMargin: '200px' },
  );
  if (rootRef.value) observer.observe(rootRef.value);
});

onBeforeUnmount(() => {
  observer?.disconnect();
  if (imageUrl.value) URL.revokeObjectURL(imageUrl.value);
});
</script>

<template>
  <span
    ref="rootRef"
    class="flex h-full w-full items-center justify-center overflow-hidden bg-next-muted text-next-4xl text-next-muted-foreground"
  >
    <img v-if="imageUrl" :src="imageUrl" :alt="file.name" class="h-full w-full object-cover" />
    <span
      v-else-if="textSnippet !== null"
      class="block h-full w-full overflow-hidden whitespace-pre-wrap break-words p-next-2 text-left text-[9px] leading-[1.25] text-next-fg/70"
      aria-hidden="true"
      >{{ textSnippet }}</span
    >
    <Icon v-else :name="fileTypeIcon(file.type)" aria-hidden="true" />
  </span>
</template>
