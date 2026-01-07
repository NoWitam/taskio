<script setup lang="ts">
import { computed, onMounted, ref, useSlots, watch } from "vue";
import { cn } from "@/lib/helpers";

const props = withDefaults(
  defineProps<{
    modelValue: string; // HTML
    id?: string;
    label?: string;
    placeholder?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;

    toolbar?: boolean;
    allowLinks?: boolean;
    pastePlainText?: boolean;
  }>(),
  { disabled: false, toolbar: true, allowLinks: true, pastePlainText: true }
);

const emit = defineEmits<{ (e: "update:modelValue", value: string): void }>();

const inputId = props.id ?? `rt_${Math.random().toString(16).slice(2)}`;
const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.hint) ids.push(`${inputId}_hint`);
  if (props.error) ids.push(`${inputId}_err`);
  return ids.length ? ids.join(" ") : undefined;
});

const editorRef = ref<HTMLDivElement | null>(null);
const lastHtml = ref<string>("");

const slots = useSlots();
const hasLeft = computed(() => !!slots.left);
const hasRight = computed(() => !!slots.right);

const isEmpty = computed(() => {
  const el = editorRef.value;
  if (!el) return true;
  const txt = (el.textContent ?? "").trim();
  return txt.length === 0;
});

function setHtml(html: string) {
  const el = editorRef.value;
  if (!el) return;
  el.innerHTML = html || "";
  lastHtml.value = el.innerHTML;
}

function syncOut() {
  const el = editorRef.value;
  if (!el) return;
  const html = el.innerHTML;
  if (html === lastHtml.value) return;
  lastHtml.value = html;
  emit("update:modelValue", html);
}

onMounted(() => setHtml(props.modelValue));

watch(
  () => props.modelValue,
  (v) => {
    const el = editorRef.value;
    if (!el) return;
    if (v !== el.innerHTML) setHtml(v);
  }
);

function cmd(command: string, value?: string) {
  if (props.disabled) return;
  editorRef.value?.focus();
  // execCommand jest “legacy”, ale wciąż praktyczne do prostego RTE bez bibliotek
  document.execCommand(command, false, value);
  syncOut();
}

function onPaste(e: ClipboardEvent) {
  if (!props.pastePlainText) return;
  e.preventDefault();
  const text = e.clipboardData?.getData("text/plain") ?? "";
  cmd("insertText", text);
}

function insertLink() {
  if (!props.allowLinks) return;
  const url = window.prompt("Wklej URL:");
  if (!url) return;
  cmd("createLink", url);
}
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <div class="relative">
      <div v-if="hasLeft" class="absolute top-2 left-0 flex items-start pl-3 pointer-events-auto">
        <slot name="left" />
      </div>

      <div
        :class="cn(
          'w-full rounded-lg border bg-card px-3 py-2 text-sm text-foreground',
          hasLeft && 'pl-10',
          hasRight && 'pr-10',
          'min-h-[10rem]',
          'border-border hover:border-border/80',
          'focus-within:outline-none focus-within:ring-2 focus-within:ring-primary/30 focus-within:border-primary/40',
          props.disabled && 'opacity-60 cursor-not-allowed',
          props.error && 'border-danger focus-within:ring-danger/25 focus-within:border-danger',
          props.class
        )"
      >
        <div v-if="toolbar" class="mb-2 flex flex-wrap gap-2 border-b border-border pb-2">
          <button type="button" class="h-8 px-2 rounded-md border border-border bg-background hover:bg-secondary/60 text-sm"
                  :disabled="disabled" @click="cmd('bold')" aria-label="Pogrubienie">
            <b>B</b>
          </button>
          <button type="button" class="h-8 px-2 rounded-md border border-border bg-background hover:bg-secondary/60 text-sm"
                  :disabled="disabled" @click="cmd('italic')" aria-label="Kursywa">
            <i>I</i>
          </button>
          <button type="button" class="h-8 px-2 rounded-md border border-border bg-background hover:bg-secondary/60 text-sm"
                  :disabled="disabled" @click="cmd('underline')" aria-label="Podkreślenie">
            <u>U</u>
          </button>
          <button type="button" class="h-8 px-2 rounded-md border border-border bg-background hover:bg-secondary/60 text-sm"
                  :disabled="disabled" @click="cmd('insertUnorderedList')" aria-label="Lista punktowana">
            ••
          </button>
          <button type="button" class="h-8 px-2 rounded-md border border-border bg-background hover:bg-secondary/60 text-sm"
                  :disabled="disabled" @click="cmd('insertOrderedList')" aria-label="Lista numerowana">
            1.
          </button>
          <button v-if="allowLinks" type="button"
                  class="h-8 px-2 rounded-md border border-border bg-background hover:bg-secondary/60 text-sm"
                  :disabled="disabled" @click="insertLink" aria-label="Link">
            🔗
          </button>
        </div>

        <div class="relative">
          <div
            ref="editorRef"
            :id="inputId"
            class="min-h-[7rem] outline-none"
            :class="disabled && 'pointer-events-none'"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :aria-invalid="!!error || undefined"
            :aria-describedby="describedBy"
            @input="syncOut"
            @blur="syncOut"
            @paste="onPaste"
          />

          <div
            v-if="placeholder && isEmpty"
            class="pointer-events-none absolute left-0 top-0 text-sm text-muted-foreground/70"
          >
            {{ placeholder }}
          </div>
        </div>
      </div>

      <div v-if="hasRight" class="absolute top-2 right-0 flex items-start pr-3 pointer-events-auto">
        <slot name="right" />
      </div>
    </div>

    <p v-if="hint" :id="`${inputId}_hint`" class="text-xs text-muted-foreground text-left pl-0">
      {{ hint }}
    </p>
    <p v-if="error" :id="`${inputId}_err`" class="text-xs text-danger text-left pl-0">
      {{ error }}
    </p>
  </div>
</template>
