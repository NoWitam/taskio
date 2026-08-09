// @vitest-environment happy-dom
// mentionSuggestStates.dom.spec — the `@`-mention popup's EMPTY and ERROR arms.
//
// WHY THIS FILE EXISTS: `MentionSuggest` and `WikilinkSuggest` were two copies of the same popup
// around a different row, and the copies drifted. The wikilink one learned to tell a FAILED search
// from a search that matched nothing; this one did not, so the identical failure here kept rendering
// as "No matches" — and a writer told there is no such person types the name as plain text, so the
// mention is never made and nobody is ever notified. Both now render through one shell
// (`SuggestListPopup`) with one state contract, and `mention.ts` sets the flag that turns the error
// arm on.
//
// It also pins the copy: the empty row used to be the English literal "No matches" baked into a
// `ui/` component, which is exactly the defect the wikilink popup's `labels` prop was introduced to
// end — rendered into a Polish UI like any other hardcoded string.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, ref } from 'vue';
import Modal from '../../overlay/Modal.vue';
import MarkdownEditor from '../MarkdownEditor.vue';
import { setLocale, translate as t } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

type Editor = {
  commands: { insertContent: (value: string) => void };
  view: { dom: HTMLElement };
};

type MentionItem = { id: string; label: string; avatar: string | null };

/** A Modal hosting the editor — the arrangement every markdown editor in Taskio actually lives in. */
function hostWith(fetch: () => Promise<MentionItem[]>) {
  return defineComponent({
    components: { Modal, MarkdownEditor },
    setup() {
      const open = ref(false);
      const text = ref('');
      return { open, text, mentions: { fetch } };
    },
    template: `
      <Modal v-model:open="open" aria-label="host">
        <MarkdownEditor v-model="text" hide-toolbar :mentions="mentions" />
      </Modal>
    `,
  });
}

function editorOf(wrapper: ReturnType<typeof mount>): Editor {
  const editorComp = wrapper.findComponent(MarkdownEditor);
  const exposed = editorComp.vm as unknown as { editor: { value?: unknown } };
  return ((exposed.editor as { value?: unknown }).value ?? exposed.editor) as Editor;
}

async function settle(times = 4): Promise<void> {
  for (let i = 0; i < times; i += 1) await nextTick();
}

const popup = (): HTMLElement | null => document.body.querySelector('#next-mention-listbox');

async function openAndType(
  fetch: () => Promise<MentionItem[]>,
  text: string,
): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(hostWith(fetch), { attachTo: document.body });
  (wrapper.vm as unknown as { open: boolean }).open = true;
  await settle(5);
  editorOf(wrapper).commands.insertContent(text);
  await settle(8);
  return wrapper;
}

describe('the `@` mention popup states', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('says the SEARCH failed, and never that there are no matches', async () => {
    const wrapper = await openAndType(async () => {
      throw new Error('network');
    }, '@Ada');

    const row = document.body.querySelector('[data-suggest-error]');
    expect(row, 'the error row must exist').toBeTruthy();
    expect(row?.textContent).toContain(t('editor.suggest.mentionError'));
    // THE distinction: the empty wording must not appear, because it is a different claim.
    expect(popup()?.textContent).not.toContain(t('editor.suggest.mentionEmpty'));

    wrapper.unmount();
  });

  it('says there are no matches when the search WORKED and returned nothing', async () => {
    const wrapper = await openAndType(async () => [], '@Ada');

    expect(popup()?.textContent).toContain(t('editor.suggest.mentionEmpty'));
    expect(document.body.querySelector('[data-suggest-error]')).toBeNull();

    wrapper.unmount();
  });

  it('shows no error row when the search worked', async () => {
    // Otherwise one dropped request would poison the popup for the rest of the session.
    const wrapper = await openAndType(
      async () => [{ id: 'u1', label: 'Ada Lovelace', avatar: null }],
      '@Ada',
    );

    expect(document.body.querySelector('[data-suggest-error]')).toBeNull();
    expect(popup()?.textContent).toContain('Ada Lovelace');

    wrapper.unmount();
  });

  it('renders its empty state in Polish too — it used to be an English literal', async () => {
    setLocale('pl');
    const wrapper = await openAndType(async () => [], '@Ada');

    expect(popup()?.textContent).toContain('Brak dopasowań');
    expect(popup()?.textContent).not.toContain('No matches');

    wrapper.unmount();
  });
});
