<script setup lang="ts">
import { computed, getCurrentInstance, ref, watch } from "vue";
import { cn } from "@/lib/helpers";
import Button from "@/components/ui/Button.vue";
import { api } from "@/lib/api";
import { useToast } from "@/composables/useToast";
import { useI18n } from "@/composables/useI18n";

export type UploadedFile = {
  id: string;
  name: string;
  size: number;
  mime: string;
};

type UploaderCtx = {
  signal: AbortSignal;
  onProgress: (progressPct: number) => void;
};

export type FileUploader = (file: File, ctx: UploaderCtx) => Promise<UploadedFile>;

type UploadStatus = "queued" | "uploading" | "success" | "error" | "canceled";

type UploadItem = {
  id: string;
  file: File;
  status: UploadStatus;
  progress: number;
  error?: string;
  meta?: UploadedFile;
  controller?: AbortController;
  thumbnail?: string;
};

const props = withDefaults(
  defineProps<{
    modelValue?: string[];
    accept?: string;
    multiple?: boolean;
    disabled?: boolean;
    class?: string;
    concurrency?: number;
    uploader?: FileUploader;
    showDiskButton?: boolean;
    showAiButton?: boolean;
  }>(),
  {
    multiple: true,
    disabled: false,
    concurrency: 4,
    showDiskButton: false,
    showAiButton: false,
  }
);

const emit = defineEmits<{
  (e: "update:modelValue", value: string[]): void;
  (e: "uploaded", file: UploadedFile): void;
  (e: "ai"): void;
}>();

function uid() {
  return `u_${Math.random().toString(16).slice(2)}_${Date.now()}`;
}

function formatBytes(n: number) {
  if (!Number.isFinite(n) || n <= 0) return "";
  const units = ["B", "KB", "MB", "GB"];
  let v = n;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  const digits = i <= 1 ? 0 : 1;
  return `${v.toFixed(digits)} ${units[i]}`;
}

const inputRef = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const items = ref<UploadItem[]>([]);

const instance = getCurrentInstance();
const hasVModelListener = computed(() => {
  const vp = instance?.vnode?.props ?? {};
  return Object.prototype.hasOwnProperty.call(vp, "onUpdate:modelValue");
});
const isControlled = computed(() => hasVModelListener.value);

const model = computed<string[]>({
  get: () => (Array.isArray(props.modelValue) ? props.modelValue : []),
  set: (v) => emit("update:modelValue", v),
});

const { t } = useI18n();

const help = computed(() =>
  props.multiple ? t('upload.dragDropMulti') : t('upload.dragDropSingle')
);

function openPicker() {
  if (props.disabled) return;
  inputRef.value?.click();
}

function handleFiles(fileList: FileList | null) {
  if (!fileList || props.disabled) return;
  const next = Array.from(fileList);
  enqueue(next);

  // Pozwala wybrać ponownie ten sam plik (input change odpali ponownie)
  if (inputRef.value) inputRef.value.value = "";
}

function onDrop(e: DragEvent) {
  e.preventDefault();
  dragging.value = false;
  handleFiles(e.dataTransfer?.files ?? null);
}

function onDragOver(e: DragEvent) {
  e.preventDefault();
  if (props.disabled) return;
  dragging.value = true;
}

function onDragLeave(e: DragEvent) {
  e.preventDefault();
  dragging.value = false;
}

async function defaultUploader(file: File, ctx: UploaderCtx): Promise<UploadedFile> {
  const fd = new FormData();
  fd.append("file", file, file.name);

  const response = await api.post<{ data: UploadedFile }>("/disk/temp", fd, {
    signal: ctx.signal,
    onUploadProgress: (e: any) => {
      const total = e?.total;
      const loaded = e?.loaded;
      if (typeof total === "number" && total > 0 && typeof loaded === "number") {
        ctx.onProgress(Math.max(0, Math.min(100, Math.round((loaded / total) * 100))));
      }
    },
  });
  
  // Laravel Resource owija dane w klucz 'data'
  return response.data;
}

const uploader = computed<FileUploader>(() => props.uploader ?? defaultUploader);

function addUuid(uuid: string) {
  const cur = model.value;
  if (cur.includes(uuid)) return;
  model.value = [...cur, uuid];
}

function removeUuid(uuid: string) {
  // Zawsze aktualizuj model - nie sprawdzaj isControlled
  model.value = (model.value || []).filter((x) => x !== uuid);
}

function generateThumbnail(file: File): Promise<string | undefined> {
  return new Promise((resolve) => {
    const fileType = file.type.toLowerCase();
    
    // Dla obrazów - generuj miniaturkę
    if (fileType.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = (e) => {
        const img = new Image();
        img.onload = () => {
          const canvas = document.createElement('canvas');
          const ctx = canvas.getContext('2d');
          
          // Oblicz proporcje dla 640x360
          const targetWidth = 640;
          const targetHeight = 360;
          const aspectRatio = img.width / img.height;
          const targetAspectRatio = targetWidth / targetHeight;
          
          let drawWidth = img.width;
          let drawHeight = img.height;
          let offsetX = 0;
          let offsetY = 0;
          
          // Przytnij do proporcji 16:9
          if (aspectRatio > targetAspectRatio) {
            // Obraz szerszy - przytnij boki
            drawWidth = img.height * targetAspectRatio;
            offsetX = (img.width - drawWidth) / 2;
          } else {
            // Obraz wyższy - przytnij góra/dół
            drawHeight = img.width / targetAspectRatio;
            offsetY = (img.height - drawHeight) / 2;
          }
          
          canvas.width = targetWidth;
          canvas.height = targetHeight;
          
          ctx?.drawImage(
            img,
            offsetX, offsetY, drawWidth, drawHeight,
            0, 0, targetWidth, targetHeight
          );
          
          resolve(canvas.toDataURL('image/jpeg', 0.8));
        };
        img.onerror = () => resolve(undefined);
        img.src = e.target?.result as string;
      };
      reader.onerror = () => resolve(undefined);
      reader.readAsDataURL(file);
    } else {
      // Dla innych typów plików nie generujemy thumbnails
      resolve(undefined);
    }
  });
}

async function enqueue(files: File[]) {
  if (!files.length) return;

  const nextItems: UploadItem[] = await Promise.all(
    files.map(async (file) => ({
      id: uid(),
      file,
      status: "queued" as UploadStatus,
      progress: 0,
      thumbnail: await generateThumbnail(file),
    }))
  );

  items.value = [...items.value, ...nextItems];
  pump();
}

async function startUpload(item: UploadItem) {
  const controller = new AbortController();
  item.controller = controller;
  item.status = "uploading";
  item.progress = 0;
  item.error = undefined;

  try {
    const meta = await uploader.value(item.file, {
      signal: controller.signal,
      onProgress: (pct) => {
        item.progress = pct;
      },
    });

    item.meta = meta;
    item.progress = 100;
    item.status = "success";
    addUuid(meta.id);
    emit("uploaded", meta);
  } catch (err: any) {
    if (controller.signal.aborted) {
      item.status = "canceled";
      item.error = undefined;
      return;
    }

    item.status = "error";
    item.error = err?.response?.data?.message || err?.message || "Upload nieudany";
  } finally {
    pump();
  }
}

function pump() {
  const limit = Math.max(1, Number(props.concurrency || 4));
  const active = items.value.filter((x) => x.status === "uploading").length;
  const free = Math.max(0, limit - active);
  if (!free) return;

  const toStart = items.value.filter((x) => x.status === "queued").slice(0, free);
  toStart.forEach((it) => {
    void startUpload(it);
  });
}

function cancel(item: UploadItem) {
  if (item.status !== "uploading") return;
  item.controller?.abort();
}

function removeItem(item: UploadItem) {
  if (item.meta?.id) removeUuid(item.meta.id);
  items.value = items.value.filter((x) => x.id !== item.id);
}

const { push: pushToast, update: updateToast } = useToast();
const toastId = ref<string | null>(null);

function updateAggregateToast() {
  if (isControlled.value) return;

  const total = items.value.length;
  if (!total) {
    if (toastId.value) {
      // nic nie robimy: toast i tak zniknie wg timeout
      toastId.value = null;
    }
    return;
  }

  const uploading = items.value.filter((x) => x.status === "uploading").length;
  const queued = items.value.filter((x) => x.status === "queued").length;
  const ok = items.value.filter((x) => x.status === "success").length;
  const err = items.value.filter((x) => x.status === "error").length;

  const running = uploading + queued;
  const message = `W toku: ${running} • OK: ${ok} • Błąd: ${err}`;

  if (!toastId.value) {
    toastId.value = pushToast({
      title: "Wysyłanie załączników",
      message,
      tone: "neutral",
      timeoutMs: null,
    });
    return;
  }

  if (running > 0) {
    updateToast(toastId.value, {
      title: "Wysyłanie załączników",
      message,
      tone: err ? "warning" : "neutral",
      timeoutMs: null,
    });
    return;
  }

  // zakończone: jeszcze chwilę pokazujemy
  updateToast(toastId.value, {
    title: err ? t('upload.uploadPartialError') : t('upload.uploadSuccess'),
    message,
    tone: err ? "danger" : "success",
    timeoutMs: 2500,
  });
  toastId.value = null;
}

watch(
  () => items.value.map((x) => [x.status, x.progress, x.error]).flat(),
  () => {
    updateAggregateToast();
  }
);
</script>

<template>
  <div class="space-y-3" :class="props.class">
    <div
      :class="cn(
        'rounded-2xl border border-dashed p-6 transition',
        'bg-background',
        dragging ? 'border-primary/50 bg-secondary/30' : 'border-secondary/60',
        disabled && 'opacity-60 cursor-not-allowed'
      )"
      role="button"
      tabindex="0"
      @click="openPicker"
      @keydown.enter.prevent="openPicker"
      @keydown.space.prevent="openPicker"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <input
        ref="inputRef"
        class="hidden"
        type="file"
        :accept="accept"
        :multiple="multiple"
        :disabled="disabled"
        @change="handleFiles(($event.target as HTMLInputElement).files)"
      />

      <div class="text-center">
        <div class="text-sm font-semibold text-foreground">{{ t('upload.attachmentsLabel') }}</div>
        <div class="mt-1 text-sm text-foreground/70">{{ help }}</div>

        <div class="mt-4 flex flex-wrap justify-center gap-2">
          <Button size="sm" variant="secondary" :disabled="disabled" @click.stop="openPicker">
            {{ t('inputs.selectFilesButton') }}
          </Button>

          <Button
            v-if="showDiskButton"
            size="sm"
            variant="secondary"
            :disabled="disabled"
            @click.stop="openPicker"
          >
            Dodaj z dysku
          </Button>

          <Button
            v-if="showAiButton"
            size="sm"
            variant="secondary"
            :disabled="disabled"
            @click.stop="emit('ai')"
          >
            Wygeneruj przez AI
          </Button>
        </div>
      </div>
    </div>

    <div v-if="isControlled && items.length" class="space-y-2">
      <div
        v-for="it in items"
        :key="it.id"
        class="rounded-xl border border-border bg-card px-3 py-2"
      >
        <div class="flex items-start gap-3">
          <!-- Thumbnail -->
          <div class="shrink-0">
            <div
              v-if="it.thumbnail"
              class="relative w-32 h-18 rounded-lg overflow-hidden bg-secondary"
            >
              <img
                :src="it.thumbnail"
                :alt="it.file.name"
                class="w-full h-full object-cover"
              />
            </div>
            <div
              v-else-if="it.file.type === 'application/pdf'"
              class="w-32 h-18 rounded-lg flex items-center justify-center bg-red-100 dark:bg-red-950"
            >
              <svg
                class="w-12 h-12 text-red-600 dark:text-red-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                />
              </svg>
            </div>
            <div
              v-else
              class="w-32 h-18 rounded-lg flex items-center justify-center bg-secondary"
            >
              <svg
                class="w-10 h-10 text-muted-foreground"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                />
              </svg>
            </div>
          </div>

          <!-- File Info -->
          <div class="flex-1 min-w-0 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="truncate text-sm font-semibold">{{ it.file.name }}</div>
              <div class="text-xs text-muted-foreground">
                {{ formatBytes(it.file.size) }}
                <span v-if="it.status === 'error' && it.error" class="text-danger"> • {{ it.error }}</span>
                <span v-else-if="it.status === 'canceled'" class="text-amber-600"> • Anulowano</span>
                <span v-else-if="it.status === 'success'"> • OK</span>
              </div>
            </div>

            <div class="shrink-0 flex items-center gap-2">
              <Button
                v-if="it.status === 'uploading'"
                type="button"
                variant="secondary"
                size="sm"
                @click="cancel(it)"
              >
                Anuluj
              </Button>

              <Button
                v-else
                type="button"
                variant="secondary"
                size="sm"
                @click="removeItem(it)"
              >
                Usuń
              </Button>
            </div>
          </div>
        </div>

        <div v-if="it.status === 'uploading' || it.status === 'queued'" class="mt-2">
          <div class="h-2 w-full overflow-hidden rounded-full bg-secondary">
            <div
              class="h-full rounded-full bg-primary transition-all"
              :style="{ width: `${it.status === 'queued' ? 0 : it.progress}%` }"
            />
          </div>
          <div class="mt-1 text-xs text-muted-foreground">
            {{ it.status === 'queued' ? 'W kolejce…' : `${it.progress}%` }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
