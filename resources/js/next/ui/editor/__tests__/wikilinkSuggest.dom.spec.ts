// @vitest-environment happy-dom
// wikilinkSuggest.dom.spec — the `[[` wikilink trigger.
//
// THE ONE THAT MATTERS: Escape. The plugin answers Escape from ProseMirror's `handleKeyDown`, a
// BUBBLE-phase listener, while the shared overlay stack listens on `document` in the CAPTURE phase
// and stops Escape for whatever it thinks is topmost. Without registering, the first Escape closes
// the surrounding Modal/Drawer — discarding everything the user typed — while the suggestion list
// stays open. That regression already hit `@`-mentions and `Select`; this spec makes sure the
// wikilink popup cannot repeat it, in a module whose editor holds up to 40 000 characters.
//
// It also pins the design decisions that keep this feature cheap:
//   • picking inserts PLAIN TEXT `[[slug]]` — no node, no schema change, no serializer;
//   • the query may contain SPACES (entry titles are prose) but stops at `|` (the user is writing
//     their own label and does not want it overwritten).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, ref } from 'vue';
import Modal from '../../overlay/Modal.vue';
import MarkdownEditor from '../MarkdownEditor.vue';
import { setLocale } from '../../../app/i18n';
import { overlayStackSize } from '../../../app/composables/useOverlayStack';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const ENTRIES = [
  { slug: 'brand-voice', title: 'Brand voice', status: 'approved' },
  { slug: 'pricing', title: 'Pricing policy', status: 'draft' },
];

type Editor = {
  commands: { insertContent: (value: string) => void };
  view: { dom: HTMLElement };
  getText: () => string;
};

/** A Modal (a registered overlay) hosting the editor — the arrangement the regression needs. */
const Host = defineComponent({
  components: { Modal, MarkdownEditor },
  setup() {
    const open = ref(false);
    const text = ref('');
    return {
      open,
      text,
      wikilinks: {
        fetch: async (query: string) =>
          ENTRIES.filter((e) => e.title.toLowerCase().includes(query.trim().toLowerCase())),
        allowCreate: true,
        // A stand-in for what a HOST supplies. Deliberately not imported from `pages/knowledge`:
        // `ui/` must not reach into a feature, which is the whole reason the popup takes the map as
        // a prop instead of importing it — and the boundary spec enforces that for tests too.
        statusMap: {
          approved: { label: 'Zatwierdzony', variant: 'success', icon: 'check-circle' },
          draft: { label: 'Szkic', variant: 'neutral', icon: 'pencil' },
        },
        // `labels` is REQUIRED — the popup carries no copy of its own in any language. This spec
        // was itself the kind of host the change is about: it passed none, and before the fix the
        // popup silently rendered English defaults.
        labels: {
          list: 'Entries to link',
          create: (query: string) => `Insert a link to "${query}"`,
          empty: 'No matches',
          error: 'We could not search the entries',
        },
      },
    };
  },
  template: `
    <Modal v-model:open="open" aria-label="host">
      <MarkdownEditor v-model="text" hide-toolbar :wikilinks="wikilinks" />
    </Modal>
  `,
});

function editorOf(wrapper: ReturnType<typeof mount>): Editor {
  const editorComp = wrapper.findComponent(MarkdownEditor);
  const exposed = editorComp.vm as unknown as { editor: { value?: unknown } };
  return ((exposed.editor as { value?: unknown }).value ?? exposed.editor) as Editor;
}

async function settle(times = 4): Promise<void> {
  for (let i = 0; i < times; i += 1) await nextTick();
}

async function type(editor: Editor, text: string): Promise<void> {
  editor.commands.insertContent(text);
  await settle();
}

async function pressEscape(target: EventTarget): Promise<void> {
  target.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
  );
  await settle();
}

const modal = (): HTMLElement | null => document.body.querySelector('.next-modal');
const popup = (): HTMLElement | null =>
  document.body.querySelector('#next-wikilink-listbox');

async function openHost(): Promise<{ wrapper: ReturnType<typeof mount>; editor: Editor }> {
  const wrapper = mount(Host, { attachTo: document.body });
  (wrapper.vm as unknown as { open: boolean }).open = true;
  await settle(5);
  return { wrapper, editor: editorOf(wrapper) };
}

describe('the `[[` wikilink popup', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('opens on `[[` and offers the matching entries', async () => {
    const { wrapper, editor } = await openHost();

    await type(editor, '[[');
    await settle(6);

    expect(popup()).not.toBeNull();
    const options = document.body.querySelectorAll('#next-wikilink-listbox [role="option"]');
    // Both entries match an empty query, plus nothing to create (empty query ⇒ no slug).
    expect(options.length).toBeGreaterThanOrEqual(2);
    expect(popup()?.textContent).toContain('Brand voice');

    wrapper.unmount();
  });

  it('ESCAPE closes the popup, NOT the Modal around it', async () => {
    const { wrapper, editor } = await openHost();
    const depthWithModalOnly = overlayStackSize();

    await type(editor, '[[');
    await settle(6);
    expect(popup()).not.toBeNull();
    // The active popup is an overlay in its own right — this is the whole fix.
    expect(overlayStackSize()).toBe(depthWithModalOnly + 1);

    await pressEscape(editor.view.dom);

    expect(popup()).toBeNull();
    expect(modal()).not.toBeNull(); // the user's work is still on screen
    expect(overlayStackSize()).toBe(depthWithModalOnly); // and the slot was given back

    // Only the SECOND Escape reaches the modal.
    await pressEscape(modal() as HTMLElement);
    await settle();
    expect(modal()).toBeNull();

    wrapper.unmount();
  });

  it('leaves no stack entry behind when the editor unmounts with the popup open', async () => {
    const baseline = overlayStackSize();
    const { wrapper, editor } = await openHost();
    const depthWithModalOnly = overlayStackSize();

    await type(editor, '[[');
    await settle(6);
    expect(overlayStackSize()).toBe(depthWithModalOnly + 1);

    wrapper.unmount();
    await settle();
    expect(overlayStackSize()).toBe(baseline);
  });

  it('keeps the popup open while the query contains SPACES', async () => {
    const { wrapper, editor } = await openHost();

    await type(editor, '[[brand voice');
    await settle(6);

    expect(popup()).not.toBeNull();
    wrapper.unmount();
  });

  it('closes once the user types `|` to write their own label', async () => {
    const { wrapper, editor } = await openHost();

    await type(editor, '[[brand');
    await settle(6);
    expect(popup()).not.toBeNull();

    await type(editor, '|');
    await settle(6);
    // The user is authoring the label now; the picker must stop offering to overwrite it.
    expect(popup()).toBeNull();

    wrapper.unmount();
  });

  it('inserts PLAIN TEXT `[[slug]]`, leaving no node behind', async () => {
    const { wrapper, editor } = await openHost();

    await type(editor, '[[Brand');
    await settle(6);

    const option = document.body.querySelector(
      '#next-wikilink-listbox [role="option"]',
    ) as HTMLElement | null;
    expect(option).not.toBeNull();
    option?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
    await settle(6);

    // The document holds characters, not a chip: the markdown stays portable and the backend's
    // own `[[…]]` parser remains the single source of truth about what a link is.
    expect(editor.getText()).toContain('[[brand-voice]]');
    expect(document.body.querySelector('[data-wikilink-node]')).toBeNull();

    wrapper.unmount();
  });
});

// ------------------------------------------------------------------------------------------------
// A FAILED SEARCH IS NOT AN EMPTY ONE.
//
// Both used to leave `items` empty, so the popup said "No matches" either way — and a writer told
// there is no such entry writes a red link instead of retrying. The entry that did exist stays
// unlinked, and nothing on screen ever admitted the search broke.
const BrokenHost = defineComponent({
  components: { MarkdownEditor, Modal },
  data() {
    return {
      open: false,
      text: '',
      wikilinks: {
        // A dropped connection, a 500, an expired session — all the same to the writer.
        fetch: async () => {
          throw new Error('network');
        },
        allowCreate: false,
        labels: {
          list: 'Entries to link',
          create: (query: string) => `Insert a link to "${query}"`,
          empty: 'No matches',
          error: 'We could not search the entries',
        },
      },
    };
  },
  template: `
    <Modal v-model:open="open" aria-label="host">
      <MarkdownEditor v-model="text" hide-toolbar :wikilinks="wikilinks" />
    </Modal>
  `,
});

describe('when the wikilink search FAILS', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('says the SEARCH failed, and never that there are no matches', async () => {
    const wrapper = mount(BrokenHost, { attachTo: document.body });
    (wrapper.vm as unknown as { open: boolean }).open = true;
    await settle(5);

    await type(editorOf(wrapper), '[[Cen');
    await settle(8);

    const row = document.body.querySelector('[data-suggest-error]');
    expect(row, 'the error row must exist').toBeTruthy();
    expect(row?.textContent).toContain('We could not search the entries');
    // THE distinction: the empty wording must not appear, because it is a different claim.
    expect(popup()?.textContent).not.toContain('No matches');

    wrapper.unmount();
  });

  it('shows no error row when the search worked', async () => {
    // Otherwise one dropped request would poison the popup for the rest of the session.
    const { wrapper, editor } = await openHost();

    await type(editor, '[[Cen');
    await settle(8);

    expect(document.body.querySelector('[data-suggest-error]')).toBeNull();

    wrapper.unmount();
  });
});

// ------------------------------------------------------------------------------------------------
// THE STATUS BADGE, through the primitive and the module's own map.
describe('the entry status in a wikilink row', () => {
  beforeEach(() => {
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  for (const locale of ['pl', 'en'] as const) {
    it(`never prints the raw enum value (${locale})`, async () => {
      setLocale(locale);
      const { wrapper, editor } = await openHost();

      await type(editor, '[[');
      await settle(8);

      // `approved` is an English enum word the Polish UI used to render verbatim, and the badge
      // carried no icon where every sibling badge in the module has one.
      const text = popup()?.textContent ?? '';
      expect(text).not.toContain('approved');
      expect(text).not.toContain('draft');

      wrapper.unmount();
    });
  }
});
