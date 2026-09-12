<script setup lang="ts">
// PublicationMediaField — the ordered media list of a publication (§6.3, D12).
//
// ORDER IS DATA, AND IT CHANGES WITH BUTTONS. `media` is the ordered list of Disk uuids in
// the order the platform receives them, so it is not decoration: a carousel whose first
// image is the one somebody dragged there is a different post. Every tile therefore carries
// `▲`/`▼` with real accessible names and announces the new position politely. Drag-and-drop
// is deliberately absent rather than "coming later": an order that cannot be changed from a
// keyboard is data some people cannot reach.
//
// THE RECORD STORES POINTERS, NOT COPIES. Nothing here calls `POST /disk/{id}/copy-to-temp`
// — that is the pattern for form fields, which need their own copy. `publications.media`
// points AT Disk files and the module never dereferences them, so copying to a temp would
// make a second file and sever the relationship with the Disk.
//
// A FILE CAN VANISH BETWEEN DRAFTING AND PUBLISHING, and the backend deliberately has no
// foreign key for it ("a dangling id fails loudly at publish time, with a reason to show").
// This screen shows that reason EARLIER, while something can still be done about it — and it
// never trims `media` by itself: silently dropping an id would edit somebody's publication
// on their behalf on the strength of one failed request.
import { computed, ref, watch } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import DiskFilePickerModal from '../disk/DiskFilePickerModal.vue';
import DiskThumbnail from '../disk/DiskThumbnail.vue';
import { fileTypeIcon } from '../disk/fileIcon';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import type { DiskFile } from '../disk/types';

/** The server's cap (`max:10`, `distinct`) — stated once so the header and the gate agree. */
const MAX_MEDIA = 10;

const model = defineModel<string[]>({ default: () => [] });
const props = withDefaults(defineProps<{ disabled?: boolean }>(), { disabled: false });

const { t } = useI18n();

/** What we know about each uuid: the file, that it is gone, or that the lookup failed. */
type Entry =
  | { state: 'loading' }
  | { state: 'ready'; file: DiskFile }
  /** 404/403 — the file is genuinely not on the Disk any more (or not ours). */
  | { state: 'missing' }
  /** The lookup itself failed. The FILE is fine; only our preview is not. */
  | { state: 'unreachable' };

const entries = ref<Record<string, Entry>>({});
const pickerOpen = ref(false);
/** The polite announcement after a move — read by screen readers, invisible otherwise. */
const announcement = ref('');

const anyMissing = computed(() => model.value.some((id) => entries.value[id]?.state === 'missing'));
const anyUnreachable = computed(() =>
  model.value.some((id) => entries.value[id]?.state === 'unreachable'),
);
const canAdd = computed(() => !props.disabled && model.value.length < MAX_MEDIA);

/**
 * Hydrate the ids we have not seen. `GET /disk/{id}/info` is the single-file READ (a bare
 * `GET /disk/{id}` serves the bytes), so this is the endpoint that answers with a name and
 * enough to draw a thumbnail.
 */
async function hydrate(ids: string[]): Promise<void> {
  const unknown = ids.filter((id) => entries.value[id] === undefined);
  if (unknown.length === 0) return;

  for (const id of unknown) entries.value[id] = { state: 'loading' };

  await Promise.all(
    unknown.map(async (id) => {
      try {
        const res = await api.get<{ data: DiskFile }>(`/disk/${encodeURIComponent(id)}/info`);
        entries.value[id] = { state: 'ready', file: res.data };
      } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status ?? null;
        // 404/403 is a STATEMENT ABOUT THE FILE; anything else is a statement about the
        // network, and the two must not read the same — one of them accuses the Disk of
        // losing something it still has.
        entries.value[id] = status === 404 || status === 403 ? { state: 'missing' } : { state: 'unreachable' };
      }
    }),
  );
}

watch(model, (ids) => void hydrate(ids), { immediate: true, deep: true });

function nameOf(id: string): string {
  const entry = entries.value[id];
  return entry?.state === 'ready' ? entry.file.name : id;
}

function announce(id: string, index: number): void {
  announcement.value = t('publishing.editor.mediaPosition', '', {
    name: nameOf(id),
    index: index + 1,
    total: model.value.length,
  });
}

function move(index: number, delta: -1 | 1): void {
  const target = index + delta;
  if (target < 0 || target >= model.value.length) return;
  const next = [...model.value];
  const [moved] = next.splice(index, 1);
  next.splice(target, 0, moved);
  model.value = next;
  announce(moved, target);
}

function remove(index: number): void {
  const id = model.value[index];
  model.value = model.value.filter((_, i) => i !== index);
  announcement.value = t('publishing.editor.mediaRemove', '', { name: nameOf(id) });
}

function onPick(file: DiskFile): void {
  if (model.value.includes(file.id) || model.value.length >= MAX_MEDIA) return;
  entries.value[file.id] = { state: 'ready', file };
  model.value = [...model.value, file.id];
  pickerOpen.value = false;
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <div class="flex items-center justify-between gap-next-2">
      <h3 class="text-next-sm font-next-medium text-next-fg">
        {{ t('publishing.editor.mediaSection', '', { count: model.length, max: MAX_MEDIA }) }}
      </h3>
      <!-- The button DISAPPEARS at the cap and the header says why — a disabled "Add"
           without a number beside it is a riddle. -->
      <Button
        v-if="canAdd"
        variant="outline"
        size="sm"
        leading-icon="plus"
        @click="pickerOpen = true"
      >
        {{ t('publishing.editor.addMedia') }}
      </Button>
    </div>

    <!-- A file that is gone will NOT publish. Said here, where it can still be fixed. -->
    <Alert v-if="anyMissing" variant="warning" size="sm">
      {{ t('publishing.editor.mediaMissingWarning') }}
    </Alert>
    <!-- The previews failed, not the files. Deliberately NOT phrased as a loss. -->
    <Alert v-if="anyUnreachable" variant="info" size="sm">
      {{ t('publishing.editor.mediaPreviewsFailed') }}
    </Alert>

    <ul
      v-if="model.length"
      role="list"
      :aria-label="t('publishing.editor.mediaListLabel')"
      class="grid grid-cols-2 gap-next-2 next-md:grid-cols-4"
    >
      <li
        v-for="(id, index) in model"
        :key="id"
        role="listitem"
        class="flex flex-col gap-next-1 rounded-next-lg border p-next-2"
        :class="
          entries[id]?.state === 'missing'
            ? 'border-next-warning/40 bg-next-warning-subtle'
            : 'border-next-border bg-next-card'
        "
      >
        <div class="flex items-center gap-next-1">
          <span class="text-next-2xs font-next-medium tabular-nums text-next-muted-foreground">
            {{ index + 1 }}
          </span>
          <span class="flex-1" />
          <!-- Trailing order: the CONDITIONAL movers before the permanent remove, so the
               control that is always there never changes place. -->
          <Button
            v-if="index > 0 && !disabled"
            variant="ghost"
            size="icon-xs"
            leading-icon="arrow-up"
            :aria-label="t('publishing.editor.mediaMoveUp', '', { name: nameOf(id) })"
            @click="move(index, -1)"
          />
          <Button
            v-if="index < model.length - 1 && !disabled"
            variant="ghost"
            size="icon-xs"
            leading-icon="arrow-down"
            :aria-label="t('publishing.editor.mediaMoveDown', '', { name: nameOf(id) })"
            @click="move(index, 1)"
          />
          <Button
            v-if="!disabled"
            variant="ghost"
            size="icon-xs"
            leading-icon="x"
            :aria-label="t('publishing.editor.mediaRemove', '', { name: nameOf(id) })"
            @click="remove(index)"
          />
        </div>

        <!-- A FIXED `muted` backing under every preview, in both themes: a transparent PNG
             on a dark card is an invisible image that looks like a broken one. -->
        <div
          class="flex h-16 w-full items-center justify-center overflow-hidden rounded-next-md bg-next-muted"
        >
          <template v-if="entries[id]?.state === 'ready'">
            <DiskThumbnail :file="(entries[id] as { file: DiskFile }).file" />
          </template>
          <Icon
            v-else-if="entries[id]?.state === 'missing'"
            name="alert-triangle"
            class="text-next-lg text-next-warning-subtle-foreground"
            aria-hidden="true"
          />
          <Skeleton v-else variant="rect" width="100%" height="4rem" />
        </div>

        <p class="truncate text-next-2xs" :title="nameOf(id)">
          <span v-if="entries[id]?.state === 'missing'" class="text-next-warning-subtle-foreground">
            {{ t('publishing.editor.mediaMissing') }}
          </span>
          <span v-else class="text-next-muted-foreground">{{ nameOf(id) }}</span>
        </p>
        <Icon
          v-if="entries[id]?.state === 'ready'"
          :name="fileTypeIcon((entries[id] as { file: DiskFile }).file.type)"
          class="sr-only"
          aria-hidden="true"
        />
      </li>
    </ul>

    <!-- Position announcements. Polite, and the only thing this region ever says. -->
    <p aria-live="polite" class="sr-only">{{ announcement }}</p>

    <!-- Already-added files are shown DISABLED rather than hidden: a file missing from the
         picker reads as a file missing from the Disk. -->
    <DiskFilePickerModal
      v-model:open="pickerOpen"
      :disabled-ids="model"
      :disabled-hint="t('publishing.editor.mediaAlreadyAdded')"
      @select="onPick"
    />
  </div>
</template>
