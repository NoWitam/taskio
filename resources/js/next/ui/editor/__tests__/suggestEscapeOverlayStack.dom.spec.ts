// @vitest-environment happy-dom
// suggestEscapeOverlayStack.dom.spec — the `@`-mention and `{`-variable caret popups are OVERLAYS,
// so they must take part in the shared overlay stack.
//
// THE DEFECT: both popups handle Escape from ProseMirror's `handleKeyDown`, i.e. a BUBBLE-phase
// listener on the editor surface. The overlay stack listens on `document` in the CAPTURE phase and
// `stopPropagation()`s Escape for whatever it considers topmost. So with an editor inside a Modal /
// Drawer — where every markdown editor in Taskio actually lives — the FIRST Escape closed the whole
// MODAL (discarding the user's text) while the suggestion list stayed open, because the modal was
// the only registered overlay. `Select` was fixed the same way; these two were not, and after
// `Select` joined the stack it became the app's most common overlay, so the ordering gap matters.
//
// The fix registers each popup while it is active. This spec pins the ordering from the user's seat:
// list first, modal second, and no leaked stack entry when the popup closes or the editor unmounts.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, ref } from 'vue';
import Modal from '../../overlay/Modal.vue';
import MarkdownEditor from '../MarkdownEditor.vue';
import { setLocale } from '../../../app/i18n';
import { overlayStackSize } from '../../../app/composables/useOverlayStack';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../../variables/types';

const VARS: VariableSourceVar[] = [
  { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
];

const MENTIONS = [
  { id: 'u1', label: 'Ada Lovelace', avatar: null },
];

type Editor = {
  commands: { insertContent: (value: string) => void };
  view: { dom: HTMLElement };
};

/** A Modal (a registered overlay) hosting a markdown editor — the real-world arrangement. */
const Host = defineComponent({
  components: { Modal, MarkdownEditor },
  setup() {
    const open = ref(false);
    const text = ref('');
    return {
      open,
      text,
      variables: { variables: [], operationsCatalog: [], source: () => VARS },
      mentions: { fetch: async () => MENTIONS },
    };
  },
  template: `
    <Modal v-model:open="open" aria-label="host">
      <MarkdownEditor v-model="text" hide-toolbar :variables="variables" :mentions="mentions" />
    </Modal>
  `,
});

function editorOf(wrapper: ReturnType<typeof mount>): Editor {
  const editorComp = wrapper.findComponent(MarkdownEditor);
  const exposed = editorComp.vm as unknown as { editor: { value?: unknown } };
  return ((exposed.editor as { value?: unknown }).value ?? exposed.editor) as Editor;
}

async function settle(times = 3): Promise<void> {
  for (let i = 0; i < times; i += 1) await nextTick();
}

async function type(editor: Editor, text: string): Promise<void> {
  editor.commands.insertContent(text);
  await settle();
}

/** Escape on the ProseMirror surface — exactly where the caret is while typing a trigger. */
async function pressEscape(target: EventTarget): Promise<void> {
  target.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
  );
  await settle();
}

const modal = (): HTMLElement | null => document.body.querySelector('.next-modal');
const variablePopup = (): HTMLElement | null =>
  document.body.querySelector('[data-variable-suggest]');
const mentionPopup = (): HTMLElement | null => document.body.querySelector('.next-suggest-pop');

async function openHost(): Promise<{ wrapper: ReturnType<typeof mount>; editor: Editor }> {
  const wrapper = mount(Host, { attachTo: document.body });
  (wrapper.vm as unknown as { open: boolean }).open = true;
  await settle(4);
  return { wrapper, editor: editorOf(wrapper) };
}

describe('the caret suggestion popups join the overlay stack', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('Escape closes the `{` VARIABLE popup, not the Modal around it', async () => {
    const { wrapper, editor } = await openHost();
    const depthWithModalOnly = overlayStackSize();

    await type(editor, '{');
    expect(variablePopup()).not.toBeNull();
    // The active popup is an overlay in its own right.
    expect(overlayStackSize()).toBe(depthWithModalOnly + 1);

    await pressEscape(editor.view.dom);
    expect(variablePopup()).toBeNull();
    expect(modal()).not.toBeNull();
    // …and it gave its slot back.
    expect(overlayStackSize()).toBe(depthWithModalOnly);

    // A second Escape now reaches the modal.
    await pressEscape(modal() as HTMLElement);
    await settle();
    expect(modal()).toBeNull();

    wrapper.unmount();
  });

  it('Escape closes the `@` MENTION popup, not the Modal around it', async () => {
    const { wrapper, editor } = await openHost();
    const depthWithModalOnly = overlayStackSize();

    await type(editor, '@');
    expect(mentionPopup()).not.toBeNull();
    expect(overlayStackSize()).toBe(depthWithModalOnly + 1);

    await pressEscape(editor.view.dom);
    expect(mentionPopup()).toBeNull();
    expect(modal()).not.toBeNull();
    expect(overlayStackSize()).toBe(depthWithModalOnly);

    wrapper.unmount();
  });

  it('leaves no stack entry behind when the editor unmounts with a popup open', async () => {
    const baseline = overlayStackSize();
    const { wrapper, editor } = await openHost();
    const depthWithModalOnly = overlayStackSize();

    await type(editor, '{');
    expect(overlayStackSize()).toBe(depthWithModalOnly + 1);

    wrapper.unmount();
    await settle();
    expect(overlayStackSize()).toBe(baseline);
  });
});
