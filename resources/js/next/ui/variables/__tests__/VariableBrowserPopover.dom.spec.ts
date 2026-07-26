// @vitest-environment happy-dom
// VariableBrowserPopover.dom.spec — the FIELD shell around the browser: a FieldShell
// combobox trigger, a teleported anchored panel, and the search input that drives the
// browser's query mode. The browsing behaviour itself is covered by VariableBrowser.dom.spec;
// what is pinned here is the SHELL contract — open/close, focus, and the fact that the
// input FORWARDS its keys into the browser's one keyboard model (so ↓/Enter work while the
// caret is still in the search box). B3: the browsed rows are `treeitem`s (one inline tree) —
// only the search RESULTS are `option`s, which is why the row helper accepts both.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import VariableBrowserPopover from '../VariableBrowserPopover.vue';
import { buildVariableTree } from '../variableTree';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../types';

const VARS: VariableSourceVar[] = [
  { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
  { source: 'trigger', path: 'trigger.city', name: 'City', type: 'text' },
];
const TREE = buildVariableTree(VARS, {});

function mountPopover(props: Record<string, unknown> = {}) {
  return mount(VariableBrowserPopover, {
    attachTo: document.body,
    props: { nodes: TREE, label: 'Pick a variable', ...props },
  });
}

type Wrapper = ReturnType<typeof mountPopover>;

function panel(): HTMLElement | null {
  return document.body.querySelector('[data-variable-browser]');
}
function input(): HTMLInputElement | null {
  return document.body.querySelector('input[type="text"]');
}
/** Every VISIBLE row of the panel: tree items while browsing, options while querying. */
function rows(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"], [role="option"]'));
}
async function open(wrapper: Wrapper): Promise<void> {
  await wrapper.get('[role="combobox"]').trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

describe('VariableBrowserPopover', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('the trigger is a combobox that opens a teleported panel with the search + browser', async () => {
    const wrapper = mountPopover();
    const trigger = wrapper.get('[role="combobox"]');

    // The popup is the browser's ARIA TREE (it only becomes a listbox while searching).
    expect(trigger.attributes('aria-haspopup')).toBe('tree');
    expect(trigger.attributes('aria-expanded')).toBe('false');
    expect(panel()).toBeNull();

    await open(wrapper);

    expect(wrapper.get('[role="combobox"]').attributes('aria-expanded')).toBe('true');
    expect(panel()).toBeTruthy();
    expect(input()).toBeTruthy();
    expect(rows()).toHaveLength(2);
    // The input announces the browser's virtual focus while the caret lives in it.
    expect(input()?.getAttribute('aria-activedescendant')).toBe(rows()[0].id);

    wrapper.unmount();
  });

  it('typing switches to flat RESULTS and Enter — pressed IN THE INPUT — picks the highlighted one, then closes', async () => {
    const wrapper = mountPopover();
    await open(wrapper);

    const search = input()!;
    search.value = 'city';
    search.dispatchEvent(new Event('input'));
    await nextTick();

    expect(rows()).toHaveLength(1);
    expect(rows()[0].textContent).toContain('City');

    // The caret stays in the input; the browser owns the keys.
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    await nextTick();

    expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ path: 'trigger.city' });
    expect(panel()).toBeNull(); // picking closes the panel

    wrapper.unmount();
  });

  it('Escape from the search input closes without selecting', async () => {
    const wrapper = mountPopover();
    await open(wrapper);

    input()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await nextTick();

    expect(panel()).toBeNull();
    expect(wrapper.emitted('select')).toBeUndefined();

    wrapper.unmount();
  });

  it('marks the already-chosen path as the selected option', async () => {
    const wrapper = mountPopover({ selectedPath: 'trigger.city' });
    await open(wrapper);

    const selected = rows().filter((r) => r.getAttribute('aria-selected') === 'true');
    expect(selected).toHaveLength(1);
    expect(selected[0].textContent).toContain('City');

    wrapper.unmount();
  });

  it('a disabled trigger never opens', async () => {
    const wrapper = mountPopover({ disabled: true });

    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();

    expect(panel()).toBeNull();

    wrapper.unmount();
  });
});
