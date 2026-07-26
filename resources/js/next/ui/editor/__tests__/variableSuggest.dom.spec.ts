// @vitest-environment happy-dom
// variableSuggest.dom.spec — the `{`-INSERT popup, after B4 put it on the shared VariableBrowser.
//
// THE DEFECT (owner-reported twice): the `{` list was a FLAT list of names. No type icons, no
// nullable `?` / list `[]` markers, no way to expand an object — and, because every row in it was
// insertable, picking a form SECTION or an object GLOBAL dropped a directive that resolves to a MAP
// into the user's text.
//
// THE RISK IN FIXING IT is the ProseMirror contract: the popup must NEVER take DOM focus, or the
// caret leaves the text and the user cannot go on typing the query. So the browser is driven by
// VIRTUAL focus — the plugin forwards keys into it — and this spec pins that mechanism as tightly
// as it pins the visuals:
//   • forwarded: ↑ ↓ Enter Esc always, ← → ONLY while the query is empty,
//   • NOT forwarded: printable keys (the tree's type-ahead must never swallow a keystroke),
//     and ← → once a query exists (they belong to the caret then),
//   • a click cannot blur the editor (mousedown is prevented),
//   • a CONTAINER is expand-only — Enter on one opens it and inserts nothing.
//
// It drives the REAL editor (mount → insertContent('{') → dispatch keydown on the PM surface), so
// the plugin, the store bridge, the popup and the browser are all exercised together.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import MarkdownEditor from '../MarkdownEditor.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../../variables/types';

/** A trigger variable, a form SECTION (container) with one nullable leaf, and an object GLOBAL. */
const VARS: VariableSourceVar[] = [
  { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
  {
    source: 'trigger',
    path: 'trigger.fields.details',
    name: 'Details',
    type: 'text',
    descriptor: { base: 'object', nullable: false, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.details.note',
    name: 'Note',
    type: 'text',
    descriptor: { base: 'text', nullable: true, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.amount',
    name: 'Amount',
    type: 'number',
    descriptor: { base: 'number', nullable: false, array: true },
  },
];

type Editor = {
  commands: { insertContent: (value: string) => void; focus: () => void };
  view: { dom: HTMLElement };
  state: { doc: { childCount: number } };
};

function mountEditor(props: Record<string, unknown> = {}) {
  return mount(MarkdownEditor, {
    attachTo: document.body,
    // The toolbar is irrelevant here and its teleported Tooltips outlive the test's DOM cleanup.
    props: {
      modelValue: '',
      hideToolbar: true,
      variables: { variables: [], operationsCatalog: [], source: () => VARS },
      ...props,
    },
  });
}

type Wrapper = ReturnType<typeof mountEditor>;

function editorOf(wrapper: Wrapper): Editor {
  const exposed = wrapper.vm as unknown as { editor: { value?: unknown } };
  return ((exposed.editor as { value?: unknown }).value ?? exposed.editor) as Editor;
}

/** Type text into the document (the plugin derives the trigger + query from the doc). */
async function type(editor: Editor, text: string): Promise<void> {
  editor.commands.insertContent(text);
  await nextTick();
  await nextTick();
}

/** Dispatch a keydown on the ProseMirror surface and hand back the event to inspect. */
async function press(editor: Editor, key: string): Promise<KeyboardEvent> {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
  editor.view.dom.dispatchEvent(event);
  await nextTick();
  await nextTick();
  return event;
}

const popup = (): HTMLElement | null => document.body.querySelector('[data-variable-suggest]');
const rows = (): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>('[data-variable-suggest] [role="treeitem"]'));
const results = (): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>('[data-variable-suggest] [role="option"]'));
const label = (row: HTMLElement): string => row.querySelector('span.truncate')?.textContent?.trim() ?? '';
const activeRowId = (): string | null =>
  document.body
    .querySelector('[data-variable-suggest] [data-variable-browser]')
    ?.getAttribute('aria-activedescendant') ?? null;

function lastMarkdown(wrapper: Wrapper): string {
  const emitted = wrapper.emitted('update:modelValue');
  return (emitted?.[emitted.length - 1]?.[0] ?? '') as string;
}

describe('the `{` insert popup is the shared VariableBrowser (defect 2)', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders an ARIA TREE with type glyphs and the `?` / `[]` markers', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    expect(popup()).not.toBeNull();
    // A real tree, not a flat list of names.
    expect(document.body.querySelector('[data-variable-suggest] [role="tree"]')).not.toBeNull();
    expect(rows().map(label)).toEqual(['Title', 'Details', 'Amount']);

    // The type MARKERS the flat list never had: `[]` on the list-typed variable …
    const amount = rows()[2];
    expect(amount.querySelector('[data-marker="list"]')).not.toBeNull();
    // … and the container reads as a container (expandable, with the braces glyph).
    expect(rows()[1].getAttribute('aria-expanded')).toBe('false');

    wrapper.unmount();
  });

  it('expands a container INLINE and marks its nullable child', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    rows()[1].click(); // clicking a container TOGGLES it (it is not selectable)
    await nextTick();

    expect(rows().map(label)).toEqual(['Title', 'Details', 'Note', 'Amount']);
    expect(rows().map((r) => r.getAttribute('aria-level'))).toEqual(['1', '1', '2', '1']);
    expect(rows()[2].querySelector('[data-marker="optional"]')).not.toBeNull();
    // Nothing was inserted by opening it.
    expect(lastMarkdown(wrapper)).not.toContain('@[variable]');

    wrapper.unmount();
  });

  it('a CONTAINER is NOT insertable — Enter on it opens it instead of writing a directive', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    await press(editor, 'ArrowDown'); // cursor: Title → Details (the container)
    expect(activeRowId()).toBe(rows()[1].id);

    await press(editor, 'Enter');
    // It opened. No chip, no directive — the exact trap the flat renderer allowed.
    expect(rows().map(label)).toEqual(['Title', 'Details', 'Note', 'Amount']);
    expect(lastMarkdown(wrapper)).not.toContain('@[variable]');
    expect(popup()).not.toBeNull(); // and the popup stayed open

    wrapper.unmount();
  });

  it('inserts the chip for a picked LEAF, at the identity-only path', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    rows()[0].click(); // "Title"
    await nextTick();
    await nextTick();

    const markdown = lastMarkdown(wrapper);
    expect(markdown).toContain('id\\":\\"trigger.title'); // id === path (identity-only)
    // The typed trigger text was REPLACED by the directive (no stray `{` left behind).
    expect(markdown.startsWith('@[variable](')).toBe(true);
    expect(popup()).toBeNull(); // and the popup closed

    wrapper.unmount();
  });

  it('inserts a nested leaf at its COMPOSED path once its container is open', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    rows()[1].click(); // open "Details"
    await nextTick();
    rows()[2].click(); // pick "Note"
    await nextTick();
    await nextTick();

    expect(lastMarkdown(wrapper)).toContain('id\\":\\"trigger.fields.details.note');

    wrapper.unmount();
  });
});

describe('the `{` popup keeps ProseMirror’s focus + caret (virtual focus)', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('never takes DOM focus, and a click on a row cannot blur the editor', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    editor.commands.focus();
    await type(editor, '{');

    // The browser's body IS focusable (tabindex=0) — the invariant is that nothing here ever
    // focuses it, so DOM focus can stay on the ProseMirror surface and the caret with it.
    const browserBody = document.body.querySelector('[data-variable-browser]')!;
    expect(browserBody.getAttribute('tabindex')).toBe('0');
    expect(document.activeElement).not.toBe(browserBody);
    expect(popup()!.contains(document.activeElement)).toBe(false);

    // The panel swallows mousedown, which is what prevents focus (and the caret) moving on click.
    const down = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
    rows()[0].dispatchEvent(down);
    expect(down.defaultPrevented).toBe(true);
    expect(popup()!.contains(document.activeElement)).toBe(false);

    wrapper.unmount();
  });

  it('forwards ↑/↓ to the tree cursor and Esc to close', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    const first = rows()[0].id;
    const down = await press(editor, 'ArrowDown');
    expect(down.defaultPrevented).toBe(true);
    expect(activeRowId()).not.toBe(first);

    const up = await press(editor, 'ArrowUp');
    expect(up.defaultPrevented).toBe(true);
    expect(activeRowId()).toBe(first);

    await press(editor, 'Escape');
    expect(popup()).toBeNull();

    wrapper.unmount();
  });

  it('forwards ←/→ to the tree ONLY while the query is empty', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    // EMPTY query: → is the tree's "expand" key.
    await press(editor, 'ArrowDown'); // onto the container
    const right = await press(editor, 'ArrowRight');
    expect(right.defaultPrevented).toBe(true);
    expect(rows().map(label)).toEqual(['Title', 'Details', 'Note', 'Amount']);

    const left = await press(editor, 'ArrowLeft');
    expect(left.defaultPrevented).toBe(true);
    expect(rows().map(label)).toEqual(['Title', 'Details', 'Amount']); // collapsed again

    // NON-EMPTY query: the same keys must edit the query text, i.e. reach ProseMirror untouched.
    await type(editor, 'ti');
    expect(results().length).toBeGreaterThan(0); // we are in results mode
    const rightWithQuery = await press(editor, 'ArrowRight');
    const leftWithQuery = await press(editor, 'ArrowLeft');
    expect(rightWithQuery.defaultPrevented).toBe(false);
    expect(leftWithQuery.defaultPrevented).toBe(false);

    wrapper.unmount();
  });

  it('never swallows a printable key (the tree type-ahead must not eat the query)', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    for (const key of ['t', ' ', 'Home', 'End', 'Backspace']) {
      const event = await press(editor, key);
      expect(event.defaultPrevented).toBe(false);
    }

    wrapper.unmount();
  });

  it('typing a query switches to flat RESULTS and Enter picks the highlighted one', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{note');

    // Results, not the tree — a container is never a result (picking one is a dead end).
    expect(rows()).toHaveLength(0);
    expect(results().map((r) => r.textContent)).toHaveLength(1);
    expect(results()[0].textContent).toContain('Note');
    expect(results()[0].textContent).toContain('Details'); // the ancestor breadcrumb

    await press(editor, 'Enter');
    expect(lastMarkdown(wrapper)).toContain('id\\":\\"trigger.fields.details.note');

    wrapper.unmount();
  });

  it('a query that matches nothing says so, and leaves Enter to the editor', async () => {
    const wrapper = mountEditor();
    const editor = editorOf(wrapper);
    await type(editor, '{zzz');

    expect(document.body.querySelector('[data-variable-browser-nomatch]')).not.toBeNull();
    // Enter is still consumed by the popup (it is open), but nothing is inserted.
    await press(editor, 'Enter');
    expect(lastMarkdown(wrapper)).not.toContain('@[variable]');

    wrapper.unmount();
  });
});

describe('the `{` popup with no feed / no feature', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('shows the empty state and leaves navigation keys to the editor', async () => {
    const wrapper = mountEditor({
      variables: { variables: [], operationsCatalog: [], source: () => [] },
    });
    const editor = editorOf(wrapper);
    await type(editor, '{');

    expect(document.body.querySelector('[data-variable-browser-empty]')).not.toBeNull();
    // With nothing to navigate, the popup must not swallow keys: Enter reaches ProseMirror and
    // splits the block (observed on the DOC, since the serializer trims a trailing empty one)
    // instead of being eaten by an empty picker.
    expect(editor.state.doc.childCount).toBe(1);
    await press(editor, 'Enter');
    expect(editor.state.doc.childCount).toBe(2);
    expect(lastMarkdown(wrapper)).not.toContain('@[variable]');

    wrapper.unmount();
  });

  it('the pages/tasks editor (NO variables feature) opens no popup at all', async () => {
    // The tasks editor passes no `variables` prop, so the node — and its trigger plugin — is
    // never built. `{` must stay ordinary text.
    const wrapper = mount(MarkdownEditor, {
      attachTo: document.body,
      props: { modelValue: '' },
    });
    await nextTick();
    const editor = editorOf(wrapper);
    await type(editor, '{');

    expect(popup()).toBeNull();
    expect(document.body.querySelector('[data-variable-browser]')).toBeNull();
    expect(lastMarkdown(wrapper)).toContain('{');

    wrapper.unmount();
  });
});
