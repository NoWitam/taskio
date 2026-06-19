<script setup lang="ts">
// EditorToolbar — the grouped command bar for <MarkdownEditor>.
//
// Groups (separated by thin rules): history (undo/redo) · block type (paragraph /
// H1–H3 dropdown) · inline marks (bold/italic/underline/strike/code) · lists
// (bullet/ordered) · blockquote · link (add/edit/remove via a FieldPopover) ·
// table (insert + a contextual ops menu when the selection is inside a table) ·
// horizontal rule · clear formatting.
//
// Every button is an icon-only Button with an `aria-label`, an `aria-pressed`
// active state for toggles, a Tooltip carrying the label + shortcut, and is
// disabled when the command can't run (`editor.can()`). The toolbar is reactive
// to the editor's transactions via a bumped `tick` ref.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Editor } from '@tiptap/vue-3';
import Button from '../primitives/Button.vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Tooltip from '../overlay/Tooltip.vue';
import DropdownMenu from '../overlay/DropdownMenu.vue';
import DropdownMenuItem from '../overlay/DropdownMenuItem.vue';
import DropdownMenuSeparator from '../overlay/DropdownMenuSeparator.vue';
import FieldPopover from '../forms/FieldPopover.vue';
import TextInput from '../forms/TextInput.vue';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

const props = defineProps<{
  editor: Editor;
  disabled?: boolean;
  /** PART 2: show the insert-if-block button. */
  showIfBlock?: boolean;
  /** PART 2: show the insert-AI-text button. */
  showAiText?: boolean;
}>();

const showPart2 = computed(() => props.showIfBlock || props.showAiText);

function insertIfBlock(): void {
  (props.editor.chain().focus() as never as { insertIfBlock: () => { run: () => void } })
    .insertIfBlock()
    .run();
}
// At maxDepth the insertIfBlock command returns false, so this disables the button.
const canInsertIfBlock = computed(() => {
  void tick.value;
  try {
    return (
      props.editor.can() as unknown as { insertIfBlock?: () => boolean }
    ).insertIfBlock?.() ?? false;
  } catch {
    return false;
  }
});
function insertAiText(): void {
  (props.editor.chain().focus() as never as { insertAiText: () => { run: () => void } })
    .insertAiText()
    .run();
}

// Tiptap mutates the editor in place; bump a tick on every transaction so the
// computed active/can() states re-evaluate.
const tick = ref(0);
function bump(): void {
  tick.value += 1;
}
props.editor.on('transaction', bump);
props.editor.on('selectionUpdate', bump);
onBeforeUnmount(() => {
  props.editor.off('transaction', bump);
  props.editor.off('selectionUpdate', bump);
});

const isMac =
  typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
const mod = isMac ? '⌘' : 'Ctrl';

function isActive(name: string, attrs?: Record<string, unknown>): boolean {
  void tick.value;
  return props.editor.isActive(name, attrs);
}
function can(fn: (chain: ReturnType<Editor['can']>) => boolean): boolean {
  void tick.value;
  try {
    return fn(props.editor.can());
  } catch {
    return false;
  }
}

const focusChain = () => props.editor.chain().focus();

// --- Block type -------------------------------------------------------------
const blockLabel = computed(() => {
  void tick.value;
  if (isActive('heading', { level: 1 })) return t('editor.toolbar.heading1', 'Heading 1');
  if (isActive('heading', { level: 2 })) return t('editor.toolbar.heading2', 'Heading 2');
  if (isActive('heading', { level: 3 })) return t('editor.toolbar.heading3', 'Heading 3');
  return t('editor.toolbar.paragraph', 'Paragraph');
});
const blockIcon = computed<IconName>(() => {
  void tick.value;
  if (isActive('heading', { level: 1 })) return 'heading-1';
  if (isActive('heading', { level: 2 })) return 'heading-2';
  if (isActive('heading', { level: 3 })) return 'heading-3';
  return 'pilcrow';
});

function setParagraph(): void {
  focusChain().setParagraph().run();
}
function setHeading(level: 1 | 2 | 3): void {
  focusChain().toggleHeading({ level }).run();
}

// --- Link popover -----------------------------------------------------------
const linkOpen = ref(false);
const linkUrl = ref('');
const linkError = ref('');

const LINK_RE = /^(https?:\/\/|mailto:)/i;

function openLink(): void {
  void tick.value;
  const existing = props.editor.getAttributes('link').href as string | undefined;
  linkUrl.value = existing ?? '';
  linkError.value = '';
  linkOpen.value = true;
}
function applyLink(close: () => void): void {
  let url = linkUrl.value.trim();
  if (!url) {
    removeLink();
    close();
    return;
  }
  // Bare domains / emails get a sensible protocol so the rendered href is safe.
  if (!LINK_RE.test(url)) {
    url = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(url) ? `mailto:${url}` : `https://${url}`;
  }
  if (!LINK_RE.test(url)) {
    linkError.value = t('editor.toolbar.linkInvalid', 'Use an http(s):// or mailto: link.');
    return;
  }
  focusChain().extendMarkRange('link').setLink({ href: url }).run();
  close();
}
function removeLink(): void {
  focusChain().extendMarkRange('link').unsetLink().run();
}

// --- Table ------------------------------------------------------------------
const inTable = computed(() => {
  void tick.value;
  return props.editor.isActive('table');
});
function insertTable(): void {
  focusChain()
    .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
    .run();
}
</script>

<template>
  <div
    class="next-md-toolbar flex flex-wrap items-center gap-next-0_5 border-b border-next-border px-next-2 py-next-1_5"
    role="toolbar"
    :aria-label="t('editor.toolbar.label', 'Formatting')"
    :aria-disabled="disabled ? 'true' : undefined"
  >
    <!-- History -->
    <div class="flex items-center gap-next-0_5">
      <Tooltip :label="`${t('editor.toolbar.undo', 'Undo')} (${mod}+Z)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          :aria-label="t('editor.toolbar.undo', 'Undo')"
          :disabled="disabled || !can((c) => c.undo())"
          @click="focusChain().undo().run()"
        >
          <Icon name="undo" />
        </Button>
      </Tooltip>
      <Tooltip :label="`${t('editor.toolbar.redo', 'Redo')} (${mod}+Shift+Z)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          :aria-label="t('editor.toolbar.redo', 'Redo')"
          :disabled="disabled || !can((c) => c.redo())"
          @click="focusChain().redo().run()"
        >
          <Icon name="redo" />
        </Button>
      </Tooltip>
    </div>

    <span class="next-md-tsep" aria-hidden="true" />

    <!-- Block type -->
    <DropdownMenu :aria-label="t('editor.toolbar.blockType', 'Block type')">
      <template #trigger="{ props: triggerProps }">
        <Tooltip :label="t('editor.toolbar.paragraphHeadings', 'Paragraph & headings')">
          <Button
            size="sm"
            variant="ghost"
            class="next-md-block-trigger"
            :disabled="disabled"
            trailing-icon="chevron-down"
            v-bind="triggerProps"
          >
            <Icon :name="blockIcon" />
            <span class="ml-next-1">{{ blockLabel }}</span>
          </Button>
        </Tooltip>
      </template>
      <DropdownMenuItem icon="pilcrow" @select="setParagraph">Paragraph</DropdownMenuItem>
      <DropdownMenuItem icon="heading-1" @select="setHeading(1)">Heading 1</DropdownMenuItem>
      <DropdownMenuItem icon="heading-2" @select="setHeading(2)">Heading 2</DropdownMenuItem>
      <DropdownMenuItem icon="heading-3" @select="setHeading(3)">Heading 3</DropdownMenuItem>
    </DropdownMenu>

    <span class="next-md-tsep" aria-hidden="true" />

    <!-- Inline marks -->
    <div class="flex items-center gap-next-0_5">
      <Tooltip :label="`Bold (${mod}+B)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Bold"
          :aria-pressed="isActive('bold')"
          :class="isActive('bold') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleBold())"
          @click="focusChain().toggleBold().run()"
        >
          <Icon name="bold" />
        </Button>
      </Tooltip>
      <Tooltip :label="`Italic (${mod}+I)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Italic"
          :aria-pressed="isActive('italic')"
          :class="isActive('italic') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleItalic())"
          @click="focusChain().toggleItalic().run()"
        >
          <Icon name="italic" />
        </Button>
      </Tooltip>
      <Tooltip :label="`Underline (${mod}+U)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Underline"
          :aria-pressed="isActive('underline')"
          :class="isActive('underline') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleUnderline())"
          @click="focusChain().toggleUnderline().run()"
        >
          <Icon name="underline" />
        </Button>
      </Tooltip>
      <Tooltip :label="`Strikethrough (${mod}+Shift+S)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Strikethrough"
          :aria-pressed="isActive('strike')"
          :class="isActive('strike') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleStrike())"
          @click="focusChain().toggleStrike().run()"
        >
          <Icon name="strikethrough" />
        </Button>
      </Tooltip>
      <Tooltip :label="`Inline code (${mod}+E)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Inline code"
          :aria-pressed="isActive('code')"
          :class="isActive('code') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleCode())"
          @click="focusChain().toggleCode().run()"
        >
          <Icon name="code" />
        </Button>
      </Tooltip>
    </div>

    <span class="next-md-tsep" aria-hidden="true" />

    <!-- Lists + blockquote + code block -->
    <div class="flex items-center gap-next-0_5">
      <Tooltip :label="`Bullet list (${mod}+Shift+8)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Bullet list"
          :aria-pressed="isActive('bulletList')"
          :class="isActive('bulletList') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleBulletList())"
          @click="focusChain().toggleBulletList().run()"
        >
          <Icon name="list" />
        </Button>
      </Tooltip>
      <Tooltip :label="`Ordered list (${mod}+Shift+7)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Ordered list"
          :aria-pressed="isActive('orderedList')"
          :class="isActive('orderedList') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleOrderedList())"
          @click="focusChain().toggleOrderedList().run()"
        >
          <Icon name="list-ordered" />
        </Button>
      </Tooltip>
      <Tooltip :label="`Blockquote (${mod}+Shift+B)`">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Blockquote"
          :aria-pressed="isActive('blockquote')"
          :class="isActive('blockquote') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleBlockquote())"
          @click="focusChain().toggleBlockquote().run()"
        >
          <Icon name="quote" />
        </Button>
      </Tooltip>
      <Tooltip label="Code block">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Code block"
          :aria-pressed="isActive('codeBlock')"
          :class="isActive('codeBlock') ? 'is-active' : ''"
          :disabled="disabled || !can((c) => c.toggleCodeBlock())"
          @click="focusChain().toggleCodeBlock().run()"
        >
          <Icon name="code-block" />
        </Button>
      </Tooltip>
    </div>

    <span class="next-md-tsep" aria-hidden="true" />

    <!-- Link -->
    <div class="flex items-center gap-next-0_5">
      <FieldPopover
        v-model:open="linkOpen"
        align="start"
        aria-label="Edit link"
        :disabled="disabled"
      >
        <template #trigger>
          <Tooltip :label="`Link (${mod}+K)`">
            <Button
              size="icon"
              variant="ghost"
              class="next-md-tbtn"
              aria-label="Add or edit link"
              :aria-pressed="isActive('link')"
              :class="isActive('link') ? 'is-active' : ''"
              :disabled="disabled"
              @click="openLink()"
            >
              <Icon name="link" />
            </Button>
          </Tooltip>
        </template>
        <template #default="{ closePanel }">
          <form
            class="flex w-72 flex-col gap-next-2 p-next-3"
            @submit.prevent="applyLink(closePanel)"
          >
            <label class="text-next-xs font-next-medium text-next-fg" for="next-md-link-url">
              Link URL
            </label>
            <TextInput
              id="next-md-link-url"
              v-model="linkUrl"
              type="url"
              placeholder="https://example.com"
              :aria-invalid="!!linkError"
            />
            <p v-if="linkError" class="text-next-xs text-next-danger" role="alert">
              {{ linkError }}
            </p>
            <div class="flex items-center justify-between gap-next-2">
              <Button
                v-if="isActive('link')"
                size="sm"
                variant="ghost"
                type="button"
                @click="removeLink(); closePanel()"
              >
                Remove
              </Button>
              <span v-else />
              <Button size="sm" variant="primary" type="submit">Apply</Button>
            </div>
          </form>
        </template>
      </FieldPopover>

      <Tooltip label="Remove link">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Remove link"
          :disabled="disabled || !isActive('link')"
          @click="removeLink()"
        >
          <Icon name="unlink" />
        </Button>
      </Tooltip>
    </div>

    <span class="next-md-tsep" aria-hidden="true" />

    <!-- Table: insert, or contextual ops when inside a table -->
    <div class="flex items-center gap-next-0_5">
      <Tooltip label="Insert table">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Insert table"
          :class="inTable ? 'is-active' : ''"
          :disabled="disabled || inTable || !can((c) => c.insertTable())"
          @click="insertTable()"
        >
          <Icon name="table" />
        </Button>
      </Tooltip>

      <DropdownMenu v-if="inTable" aria-label="Table options">
        <template #trigger="{ props: triggerProps }">
          <Tooltip label="Table options">
            <Button
              size="icon"
              variant="ghost"
              class="next-md-tbtn"
              aria-label="Table options"
              :disabled="disabled"
              v-bind="triggerProps"
            >
              <Icon name="more-horizontal" />
            </Button>
          </Tooltip>
        </template>
        <DropdownMenuItem icon="plus" @select="focusChain().addColumnBefore().run()">
          Add column before
        </DropdownMenuItem>
        <DropdownMenuItem icon="plus" @select="focusChain().addColumnAfter().run()">
          Add column after
        </DropdownMenuItem>
        <DropdownMenuItem icon="minus" @select="focusChain().deleteColumn().run()">
          Delete column
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem icon="plus" @select="focusChain().addRowBefore().run()">
          Add row before
        </DropdownMenuItem>
        <DropdownMenuItem icon="plus" @select="focusChain().addRowAfter().run()">
          Add row after
        </DropdownMenuItem>
        <DropdownMenuItem icon="minus" @select="focusChain().deleteRow().run()">
          Delete row
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem icon="table" @select="focusChain().toggleHeaderRow().run()">
          Toggle header row
        </DropdownMenuItem>
        <DropdownMenuItem
          icon="trash"
          destructive
          @select="focusChain().deleteTable().run()"
        >
          Delete table
        </DropdownMenuItem>
      </DropdownMenu>
    </div>

    <span class="next-md-tsep" aria-hidden="true" />

    <!-- PART 2: insert app nodes (only present when the feature is enabled).
         Variables are inserted via the `{` trigger, NOT a toolbar button. -->
    <div v-if="showPart2" class="flex items-center gap-next-0_5">
      <Tooltip v-if="showIfBlock" label="Insert conditional block">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Insert conditional block"
          :disabled="disabled || !canInsertIfBlock"
          @click="insertIfBlock()"
        >
          <Icon name="git-branch" />
        </Button>
      </Tooltip>
      <Tooltip v-if="showAiText" label="Insert AI text">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Insert AI text"
          :disabled="disabled"
          @click="insertAiText()"
        >
          <Icon name="sparkles" />
        </Button>
      </Tooltip>
    </div>

    <span v-if="showPart2" class="next-md-tsep" aria-hidden="true" />

    <!-- Horizontal rule + clear formatting -->
    <div class="flex items-center gap-next-0_5">
      <Tooltip label="Horizontal rule">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Horizontal rule"
          :disabled="disabled || !can((c) => c.setHorizontalRule())"
          @click="focusChain().setHorizontalRule().run()"
        >
          <Icon name="minus" />
        </Button>
      </Tooltip>
      <Tooltip label="Clear formatting">
        <Button
          size="icon"
          variant="ghost"
          class="next-md-tbtn"
          aria-label="Clear formatting"
          :disabled="disabled"
          @click="focusChain().unsetAllMarks().clearNodes().run()"
        >
          <Icon name="remove-formatting" />
        </Button>
      </Tooltip>
    </div>
  </div>
</template>

<style scoped>
/* Toolbar buttons are compact (icon size is 2.5rem by default; shrink to 1.75rem
   so the bar stays slim). The active/pressed state uses the accent tint. */
.next-md-toolbar :deep(.next-md-tbtn) {
  height: 1.75rem;
  width: 1.75rem;
  font-size: var(--text-next-base);
}
.next-md-toolbar :deep(.next-md-tbtn.is-active) {
  background-color: var(--color-next-accent);
  color: var(--color-next-accent-foreground);
}
.next-md-toolbar :deep(.next-md-block-trigger) {
  height: 1.75rem;
}
.next-md-toolbar :deep(.next-md-block-trigger.is-active) {
  background-color: var(--color-next-accent);
}
.next-md-tsep {
  align-self: stretch;
  width: 1px;
  margin-block: 0.125rem;
  background-color: var(--color-next-border);
}
</style>
