// @vitest-environment happy-dom
// entryCharCounter.dom.spec — the 40 000-character cap as the WRITER experiences it.
//
// The cap is a product rule ("an entry is an encyclopedia entry"), not a technical accident, and the
// UI is required to make it legible BEFORE the save fails: a counter that turns red, and a warning
// that explains what to do instead (split and link). The server is the authority and answers 422 —
// this is the soft gate in front of it.
//
// KNOWLEDGE_ENTRY_MAX_CHARS is a hand-copied mirror of `config('knowledge.entry_max_chars')`, which
// the frontend cannot read. The first spec pins the number so a backend change that is not mirrored
// here shows up as a failing test rather than as a 422 nobody predicted.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import MarkdownEditor from '../../../ui/editor/MarkdownEditor.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { KNOWLEDGE_ENTRY_MAX_CHARS } from '../types';

async function settle(times = 4): Promise<void> {
  for (let i = 0; i < times; i += 1) await nextTick();
}

describe('the entry length cap', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('mirrors the backend cap exactly', () => {
    // config/knowledge.php → 'entry_max_chars' => 40000, enforced as `max:` on `content`.
    expect(KNOWLEDGE_ENTRY_MAX_CHARS).toBe(40000);
  });

  it('shows the count against the cap', async () => {
    const wrapper = mount(MarkdownEditor, {
      props: { modelValue: 'hello', counter: true, maxlength: KNOWLEDGE_ENTRY_MAX_CHARS },
      attachTo: document.body,
    });
    await settle();

    const counter = wrapper.find('[aria-live="polite"]');
    expect(counter.exists()).toBe(true);
    expect(counter.text()).toContain('40000');
    // Under the cap the counter is muted, not alarming.
    expect(counter.classes()).toContain('text-next-muted-foreground');

    wrapper.unmount();
  });

  it('turns the counter DANGEROUS once the content passes the cap', async () => {
    const wrapper = mount(MarkdownEditor, {
      props: {
        modelValue: 'x'.repeat(KNOWLEDGE_ENTRY_MAX_CHARS + 1),
        counter: true,
        maxlength: KNOWLEDGE_ENTRY_MAX_CHARS,
      },
      attachTo: document.body,
    });
    await settle();

    const counter = wrapper.find('[aria-live="polite"]');
    expect(counter.classes()).toContain('text-next-danger');
    // The count is announced politely, so a screen-reader user learns about it too.
    expect(counter.attributes('aria-live')).toBe('polite');

    wrapper.unmount();
  });

  it('stays muted exactly AT the cap — the limit is inclusive', async () => {
    const wrapper = mount(MarkdownEditor, {
      props: {
        modelValue: 'x'.repeat(KNOWLEDGE_ENTRY_MAX_CHARS),
        counter: true,
        maxlength: KNOWLEDGE_ENTRY_MAX_CHARS,
      },
      attachTo: document.body,
    });
    await settle();

    const counter = wrapper.find('[aria-live="polite"]');
    expect(counter.classes()).toContain('text-next-muted-foreground');
    expect(counter.classes()).not.toContain('text-next-danger');

    wrapper.unmount();
  });

  it('does NOT hard-block typing — the cap is a soft gate, the server is the authority', async () => {
    const over = 'x'.repeat(KNOWLEDGE_ENTRY_MAX_CHARS + 50);
    const wrapper = mount(MarkdownEditor, {
      props: { modelValue: over, counter: true, maxlength: KNOWLEDGE_ENTRY_MAX_CHARS },
      attachTo: document.body,
    });
    await settle();

    // The text is still there: truncating what someone wrote would be worse than refusing to save.
    // (`defineExpose` may hand back the ref or its unwrapped value depending on the harness.)
    const exposed = wrapper.vm as unknown as { editor: unknown };
    const editor = ((exposed.editor as { value?: unknown })?.value ?? exposed.editor) as {
      getText: () => string;
    };
    expect(editor.getText().length).toBeGreaterThan(KNOWLEDGE_ENTRY_MAX_CHARS);

    wrapper.unmount();
  });
});
