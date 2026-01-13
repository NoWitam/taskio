<script setup lang="ts">
import { computed, getCurrentInstance, ref, watch } from "vue";
import { cn } from "@/lib/helpers";
import Button from "@/components/ui/Button.vue";
import { api } from "@/lib/api";
import { useToast } from "@/composables/useToast";

export type UploadedFile = {
  uuid: string;
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

const help = computed(() =>
  props.multiple ? "Upuść pliki tutaj lub wybierz z dysku" : "Upuść plik tutaj lub wybierz z dysku"
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

  return await api.post<UploadedFile>("/disk/file", fd, {
    signal: ctx.signal,
    onUploadProgress: (e: any) => {
      const total = e?.total;
      const loaded = e?.loaded;
      if (typeof total === "number" && total > 0 && typeof loaded === "number") {
        ctx.onProgress(Math.max(0, Math.min(100, Math.round((loaded / total) * 100))));
      }
    },
  });
}

const uploader = computed<FileUploader>(() => props.uploader ?? defaultUploader);

function addUuid(uuid: string) {
  if (!isControlled.value) return;
  const cur = model.value;
  if (cur.includes(uuid)) return;
  model.value = [...cur, uuid];
}

function removeUuid(uuid: string) {
  if (!isControlled.value) return;
  model.value = (model.value || []).filter((x) => x !== uuid);
}

function enqueue(files: File[]) {
  if (!files.length) return;

  const nextItems: UploadItem[] = files.map((file) => ({
    id: uid(),
    file,
    status: "queued",
    progress: 0,
  }));

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
    addUuid(meta.uuid);
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
  if (item.meta?.uuid) removeUuid(item.meta.uuid);
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
    title: err ? "Załączniki: część nieudana" : "Załączniki wysłane",
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
        <div class="text-sm font-semibold text-foreground">Załączniki</div>
        <div class="mt-1 text-sm text-foreground/70">{{ help }}</div>

        <div class="mt-4 flex flex-wrap justify-center gap-2">
          <Button size="sm" variant="secondary" :disabled="disabled" @click.stop="openPicker">
            Wybierz pliki
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
        <div class="flex items-start justify-between gap-3">
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
