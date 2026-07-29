// @vitest-environment happy-dom
// TemplateBodyPart.spec — the body editor with the PROMOTED first-class `@[ai-text]` block. Asserts the
// two affordances ("AI fragment" + "Whole post by AI") render and drive the SHARED `insertAiText` command
// on the underlying editor (the chip is reused, not forked). MarkdownEditor is stubbed and EXPOSES a mock
// `editor` so the command call is observable.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateBodyPart from '../TemplateBodyPart.vue';

const insertSpy = vi.fn();

const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue', 'variables', 'aiText', 'ifBlocks', 'disabled', 'placeholder', 'ariaLabel', 'minHeight'],
  setup(_: unknown, { expose }: { expose: (o: Record<string, unknown>) => void }) {
    // Mirror the real editor's chain().focus().insertAiText().run() shape.
    const chain = () => ({ focus: () => ({ insertAiText: () => ({ run: insertSpy }) }) });
    expose({ editor: { chain } });
    return () => h('div', { class: 'md-stub' });
  },
};

const variables = { variables: [], operationsCatalog: [], source: () => [], catalog: () => [] };

describe('TemplateBodyPart', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('surfaces the first-class AI block affordances and drives insertAiText', async () => {
    const wrapper = mount(TemplateBodyPart, {
      props: { modelValue: { markdown: '' }, variables, aiText: { personas: [] } },
      global: { stubs: { MarkdownEditor: MarkdownEditorStub } },
      attachTo: document.body,
    });

    expect(wrapper.text()).toContain('AI fragment');
    expect(wrapper.text()).toContain('Whole post by AI');

    const aiButton = wrapper.findAll('button').find((b) => b.text().includes('AI fragment'))!;
    await aiButton.trigger('click');
    expect(insertSpy).toHaveBeenCalledTimes(1);

    wrapper.unmount();
  });
});
